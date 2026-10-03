<?php

namespace App\Domain\Ingest\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Ingest\Exceptions\IngestCancelled;
use App\Domain\Ingest\Jobs\ProcessIngestBatch;
use App\Domain\Ingest\Models\IngestBatch;
use App\Domain\Ocr\Services\PageReader;
use App\Domain\Ocr\Services\PdfTextReader;
use App\Domain\QuizQuestion\Services\QuizGeneratorService;
use App\Domain\Speech\Jobs\PrepareChapterNarration;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Uploading reading material, start to finish.
 *
 * The teacher drops in a PDF or a stack of page photos and gets a book back.
 * They never create the book first and they never type the words: the book is
 * created here, the pages are read here, and the only thing asked of the
 * teacher afterwards is to look at what came out and say yes.
 */
class DocumentIngestService
{
    /** How long a queued upload waits for a queue worker before it is read in-request. */
    private const START_GRACE_SECONDS = 15;

    /** How long a run may go without progress before it is taken for dead. */
    private const STALL_MINUTES = 15;

    public function __construct(
        private PdfRasterizer $rasterizer,
        private PageReader $ocr,
        private PdfTextReader $pdfText,
        private ChapterSegmenter $segmenter,
        private ChapterContentAgent $contentAgent,
        private SystemLogService $logs,
        private QuizGeneratorService $quizzes,
    ) {}

    /**
     * Take the upload, create the draft book, and queue the reading.
     *
     * @param  list<UploadedFile>  $files  one PDF, or a set of page images
     */
    public function start(Teachers $teacher, array $files, ?string $title, ?int $bookId = null): IngestBatch
    {
        if ($files === []) {
            throw new RuntimeException('No file was uploaded.');
        }

        $first = $files[0];
        $isPdf = strtolower((string) $first->getClientOriginalExtension()) === 'pdf'
            || $first->getMimeType() === 'application/pdf';

        // A draft book so the pages have somewhere to land while they are being
        // read, and so nothing half-finished shows up in a child's library.
        $book = $bookId
            ? Book::findOrFail($bookId)
            : Book::create([
                'title' => $title ?: $this->titleFromFilename($first->getClientOriginalName()),
                'type' => 'scanned',
                'status' => 'draft',
                'sequence' => (int) (Book::max('sequence') ?? 0) + 1,
            ]);

        $batch = IngestBatch::create([
            'teacher_id' => $teacher->id,
            'book_id' => $book->id,
            'source_name' => $first->getClientOriginalName(),
            'source_type' => $isPdf ? 'pdf' : 'images',
            'status' => 'queued',
            'pages_total' => $isPdf ? 0 : count($files),
        ]);

        // Stash the upload where the queue worker can reach it — the temp file
        // behind the request is gone by the time the job runs.
        $paths = [];
        foreach ($files as $file) {
            $paths[] = $file->store("ingest/{$batch->id}", 'local');
        }

        $batch->update(['source_path' => json_encode($paths)]);

        $this->logs->record(
            'material.uploaded',
            "{$teacher->full_name} uploaded \"{$batch->source_name}\" to build \"{$book->title}\".",
            null,
            $teacher,
        );

        ProcessIngestBatch::dispatch($batch->id);

        return $batch->refresh();
    }

    /**
     * Do the work: split the file into pages, read each one, store it.
     * Called from the queue, never from a request.
     */
    public function process(IngestBatch $batch): void
    {
        $paths = json_decode((string) $batch->source_path, true) ?: [];
        $book = $batch->book;

        if (! $book) {
            $this->fail($batch, 'The book this upload belongs to no longer exists.');

            return;
        }

        try {
            $pages = $batch->source_type === 'pdf'
                ? $this->pagesFromPdf($batch, $paths[0] ?? '')
                : $this->pagesFromImages($batch, $paths);

            // Keep the chapters' own words: no cover, credits, footers or exercises.
            if ($this->contentAgent->isConfigured()) {
                $batch->update(['status' => 'analyzing']);
                $pages = $this->contentAgent->keepChapterContent($pages);
            }

            // Cancelled while the pages were being sorted: nothing has been
            // written to the book yet, so only the stored pictures are left.
            if ($this->wasCancelled($batch)) {
                $this->deleteImages(array_column($pages, 'image_path'));
                $this->deleteUpload($paths);

                return;
            }

            $startNumber = (int) ($book->pages()->max('page_number') ?? 0);

            foreach ($pages as $index => $page) {
                BookPage::create([
                    'book_id' => $book->id,
                    'page_number' => $startNumber + $index + 1,
                    'image_path' => $page['image_path'],
                    'text' => $page['text'],
                ]);
            }

            $batch->update([
                'status' => 'ready',
                'pages_total' => count($pages),
                'pages_done' => count($pages),
                'completed_at' => now(),
            ]);

            // The original upload is no longer needed once its pages exist.
            $this->deleteUpload($paths);
        } catch (IngestCancelled) {
            $this->deleteUpload($paths);
        } catch (Throwable $exception) {
            // The file is kept, so "Try again" can read it without a new upload.
            report($exception);
            $this->fail($batch, $exception->getMessage());
        }
    }

    /**
     * Keep an upload moving when nothing else will.
     *
     * Called each time the upload screen asks how a batch is doing. With no
     * queue worker running, a batch would say "Waiting to start…" forever, so
     * after a short wait it is read right here, once the answer has been sent.
     * A run that died part-way is turned into a failure the teacher can see
     * and retry, rather than a progress bar that never moves.
     */
    public function keepMoving(IngestBatch $batch): IngestBatch
    {
        if ($batch->isWaitingTooLong(self::START_GRACE_SECONDS)) {
            ProcessIngestBatch::dispatchAfterResponse($batch->id);
        } elseif ($batch->hasStalled(self::STALL_MINUTES)) {
            $this->fail($batch, 'Reading this file stopped part-way — the server may have restarted or run out of time. Press "Try again".');
        }

        return $batch;
    }

    /**
     * Read a failed upload again from the file the teacher sent. The file is
     * kept until the book has its pages, so a failure never means uploading
     * it a second time.
     *
     * @throws RuntimeException when the upload cannot be retried
     */
    public function retry(IngestBatch $batch): IngestBatch
    {
        if ($batch->status !== 'failed') {
            throw new RuntimeException('Only an upload that failed can be tried again.');
        }

        $paths = json_decode((string) $batch->source_path, true) ?: [];

        if ($paths === [] || collect($paths)->contains(fn ($path) => ! Storage::disk('local')->exists($path))) {
            throw new RuntimeException('The uploaded file is no longer on the server. Please upload it again.');
        }

        // Pages a dead run got as far as saving are thrown away and read again.
        $book = $batch->book;
        if ($book && $book->status === 'draft') {
            $this->deleteImages($book->pages()->pluck('image_path')->all());
            $book->pages()->delete();
        }

        $batch->update([
            'status' => 'queued',
            'pages_done' => 0,
            'error' => null,
            'completed_at' => null,
        ]);

        ProcessIngestBatch::dispatch($batch->id);

        return $batch->refresh();
    }

    /**
     * What the teacher is asked to approve: every page, its picture and the
     * words we read off it, plus the chapters we think are in there.
     *
     * @return array<string, mixed>
     */
    public function preview(IngestBatch $batch): array
    {
        $book = $batch->book;
        $pages = $book ? $book->pages()->orderBy('page_number')->get() : collect();

        $forSegmenting = $this->forSegmenting($pages);

        return [
            'batch' => $batch,
            'status_message' => $batch->statusMessage(),
            'book' => $book,
            'pages' => $pages,
            'suggested_chapters' => $batch->status === 'ready' ? $this->segmenter->segment($forSegmenting) : [],
            // How a picture book's pages would be grouped into chapters. Only the
            // pages this upload added: pages already in a chapter stay put.
            'suggested_parts' => $batch->status === 'ready'
                ? $this->segmenter->groupPages($this->forSegmenting($pages->whereNull('chapter_id')->values()))
                : [],
            'suggested_title' => $batch->status === 'ready'
                ? $this->segmenter->guessTitle($forSegmenting, (string) $book?->title)
                : null,
        ];
    }

    /**
     * Publish the book.
     *
     * Every book comes out as Book → Chapters → Content. `as_chapters` makes
     * a reader: chapters carry the story text (and get a generated quiz).
     * Leaving it off makes a picture book: the page images are the content,
     * grouped into chapters by their headings or into even parts. Either way
     * the book leaves draft and becomes assignable.
     *
     * @param  array<int, array<string, mixed>>|null  $chapters  the teacher's edited split (reader)
     * @param  array<int, array<string, mixed>>|null  $parts  the teacher's edited grouping (picture book)
     */
    public function commit(IngestBatch $batch, ?string $title, ?string $readingLevel, bool $asChapters, ?array $chapters = null, ?array $parts = null): Book
    {
        $book = $batch->book;

        if (! $book) {
            throw new RuntimeException('The book this upload belongs to no longer exists.');
        }

        if ($batch->status !== 'ready') {
            throw new RuntimeException('This upload is not finished being read yet.');
        }

        DB::transaction(function () use ($batch, $book, $title, $readingLevel, $asChapters, $chapters, $parts) {
            $this->releasePlaceholderChapters($book);

            $book->update(array_filter([
                'title' => $title,
                'reading_level' => $readingLevel,
            ], fn ($value) => filled($value)) + [
                'type' => $asChapters ? 'standard' : 'scanned',
                'status' => 'active',
            ]);

            if ($asChapters) {
                $this->buildChapters($book, $batch, $chapters);
            } else {
                $this->buildPageChapters($book, $parts);
            }

            $batch->update(['status' => 'committed']);
        });

        $this->logs->record(
            'material.published',
            "\"{$book->title}\" was published from \"{$batch->source_name}\".",
            null,
            $batch->teacher,
        );

        // Speak every page now, in the background, so "Listen" plays at once.
        foreach ($book->chapters()->pluck('id') as $chapterId) {
            PrepareChapterNarration::dispatch($chapterId);
        }

        return $book->refresh();
    }

    /**
     * Throw the draft away — the teacher decided the scan was no good, or
     * cancelled it while it was still being read. A running job notices the
     * batch is gone and tidies up the pages it had got to.
     */
    public function discard(IngestBatch $batch): void
    {
        // Not started, or failed and kept for a retry: no job will be around
        // to delete the upload.
        if (in_array($batch->status, ['queued', 'failed'], true)) {
            Storage::disk('local')->deleteDirectory("ingest/{$batch->id}");
        }

        $book = $batch->book;

        if ($book && $book->status === 'draft') {
            foreach ($book->pages as $page) {
                if ($page->image_path) {
                    Storage::disk('public')->delete($page->image_path);
                }
            }

            $book->pages()->delete();
            $book->delete();
        }

        $batch->delete();
    }

    // ============================================================
    //  Internals
    // ============================================================

    /**
     * @return list<array{image_path: ?string, text: ?string}>
     */
    private function pagesFromPdf(IngestBatch $batch, string $storedPath): array
    {
        $batch->update(['status' => 'rasterizing']);

        $absolute = Storage::disk('local')->path($storedPath);

        // Preferred path: real page images, each read for its words.
        if ($this->rasterizer->isAvailable()) {
            $workDir = dirname($absolute).'/pages';
            $images = $this->rasterizer->rasterize($absolute, $workDir);

            try {
                return $this->readImages($batch, $images);
            } finally {
                foreach ($images as $image) {
                    @unlink($image);
                }

                @rmdir($workDir);
            }
        }

        // Fallback: no renderer on this host, so send the PDF itself to be read
        // and keep the words. The pictures are lost, and the preview says so.
        $batch->update(['text_only' => true, 'status' => 'reading']);

        $texts = $this->pdfText->readPages(
            (string) file_get_contents($absolute),
            $batch->source_name ?: 'book.pdf',
        );

        return array_map(fn (string $text) => ['image_path' => null, 'text' => $text ?: null], $texts);
    }

    /**
     * @param  list<string>  $storedPaths
     * @return list<array{image_path: ?string, text: ?string}>
     */
    private function pagesFromImages(IngestBatch $batch, array $storedPaths): array
    {
        return $this->readImages(
            $batch,
            array_map(fn (string $path) => Storage::disk('local')->path($path), $storedPaths),
        );
    }

    /**
     * Keep each page picture and read the words off it, several pages at a
     * time. Reading them one by one left a forty-page book waiting on forty
     * calls in a row; the progress moves as each group finishes.
     *
     * @param  list<string>  $files  absolute paths of the page images, in order
     * @return list<array{image_path: ?string, text: ?string}>
     *
     * @throws IngestCancelled when the teacher cancels part way through
     */
    private function readImages(IngestBatch $batch, array $files): array
    {
        $batch->update(['status' => 'reading', 'pages_total' => count($files), 'pages_done' => 0]);

        $size = $this->ocr->concurrency();
        $pages = [];

        try {
            foreach (array_chunk($files, $size, true) as $chunk) {
                if ($this->wasCancelled($batch)) {
                    throw new IngestCancelled;
                }

                $texts = $this->readMany(array_map(fn (string $file) => (string) file_get_contents($file), $chunk));

                foreach ($chunk as $index => $file) {
                    $pages[$index] = [
                        'image_path' => Storage::disk('public')->putFile(
                            "book-pages/{$batch->book_id}",
                            new \Illuminate\Http\File($file),
                        ),
                        'text' => $texts[$index] ?? null,
                    ];
                }

                $batch->update(['pages_done' => count($pages)]);
            }
        } catch (IngestCancelled $cancelled) {
            $this->deleteImages(array_column($pages, 'image_path'));

            throw $cancelled;
        }

        return array_values($pages);
    }

    /**
     * OCR a group of pages. A page that will not read is not a failed upload —
     * picture pages have no words at all — so the failure is swallowed and the
     * teacher fixes it in the preview.
     *
     * @param  array<int, string>  $images
     * @return array<int, ?string>
     */
    private function readMany(array $images): array
    {
        if (! $this->ocr->isConfigured()) {
            return array_fill_keys(array_keys($images), null);
        }

        try {
            return $this->ocr->extractTextMany($images);
        } catch (Throwable $exception) {
            report($exception);

            return array_fill_keys(array_keys($images), null);
        }
    }

    /** @param  list<string>  $paths  the stored upload */
    private function deleteUpload(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /** Cancelling an upload deletes its row; the job notices it is gone. */
    private function wasCancelled(IngestBatch $batch): bool
    {
        return ! IngestBatch::whereKey($batch->id)->exists();
    }

    /** @param  list<?string>  $paths */
    private function deleteImages(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @param  array<int, array<string, mixed>>|null  $chapters */
    private function buildChapters(Book $book, IngestBatch $batch, ?array $chapters): void
    {
        if ($chapters === null) {
            // Only this upload's pages: ones already in a chapter stay there.
            $chapters = $this->segmenter->segment($this->forSegmenting(
                $book->pages()->whereNull('chapter_id')->orderBy('page_number')->get()
            ));
        }

        // Adding to a book that already has chapters continues its numbering.
        $offset = (int) ($book->chapters()->max('chapter_number') ?? 0);

        foreach ($chapters as $index => $chapter) {
            if (blank($chapter['story_text'] ?? null)) {
                continue;
            }

            $created = Chapter::create([
                'book_id' => $book->id,
                'chapter_number' => $offset + ($chapter['chapter_number'] ?? $index + 1),
                'title' => $chapter['title'] ?? 'Chapter '.($index + 1),
                'story_text' => $chapter['story_text'],
                // The first page of a chapter makes a natural illustration.
                'image_url' => $this->firstPageImage($book, $chapter['page_numbers'] ?? []),
            ]);

            // The scans the chapter came from stay attached to it.
            $this->attachPages($book, $created, $chapter['page_numbers'] ?? []);

            // Nobody types a quiz: one is written from the chapter's own text.
            $this->quizzes->generateIfUncurated($created);
        }
    }

    /**
     * A picture book is still Book → Chapters → Pages: group the pages this
     * upload added into chapters, by heading or into even parts.
     *
     * @param  array<int, array<string, mixed>>|null  $parts  the teacher's edited grouping
     */
    private function buildPageChapters(Book $book, ?array $parts): void
    {
        $pages = $book->pages()->whereNull('chapter_id')->orderBy('page_number')->get();

        if ($pages->isEmpty()) {
            return;
        }

        $parts ??= $this->segmenter->groupPages($this->forSegmenting($pages));
        $offset = (int) ($book->chapters()->max('chapter_number') ?? 0);
        $last = null;

        foreach (array_values($parts) as $index => $part) {
            $last = Chapter::create([
                'book_id' => $book->id,
                'chapter_number' => $offset + $index + 1,
                'title' => filled($part['title'] ?? null) ? $part['title'] : 'Chapter '.($index + 1),
                'image_url' => $this->firstPageImage($book, $part['page_numbers'] ?? []),
            ]);

            $this->attachPages($book, $last, $part['page_numbers'] ?? []);
        }

        // A page the edited grouping left out still belongs to the book: it
        // goes on the end rather than being orphaned outside every chapter.
        $leftOver = $book->pages()->whereNull('chapter_id');

        if ($leftOver->exists()) {
            $last ??= Chapter::create([
                'book_id' => $book->id,
                'chapter_number' => $offset + 1,
                'title' => 'Chapter 1',
            ]);

            $leftOver->update(['chapter_id' => $last->id]);
        }
    }

    /**
     * Free a draft's pages from the stand-in "Chapter 1" that was put round
     * them before the teacher chose how the book splits.
     *
     * A draft is not meant to have chapters yet, but a page can still land in
     * one — the chapter_id backfill wraps any scanned book's loose pages in a
     * "Chapter 1". Left alone, publishing numbers the real chapters after it
     * (2, 3, 4…) and finds no loose pages to put in them. A stand-in has no
     * story text and no quiz; a chapter with either is real and stays.
     */
    private function releasePlaceholderChapters(Book $book): void
    {
        if ($book->status !== 'draft') {
            return;
        }

        foreach ($book->chapters()->get() as $chapter) {
            if (filled($chapter->story_text) || $chapter->quizQuestions()->exists()) {
                continue;
            }

            $book->pages()->where('chapter_id', $chapter->id)->update(['chapter_id' => null]);
            $chapter->delete();
        }
    }

    /** @param  list<int>  $pageNumbers */
    private function attachPages(Book $book, Chapter $chapter, array $pageNumbers): void
    {
        if ($pageNumbers === []) {
            return;
        }

        $book->pages()
            ->whereIn('page_number', $pageNumbers)
            ->whereNull('chapter_id')
            ->update(['chapter_id' => $chapter->id]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BookPage>  $pages
     * @return list<array{page_number: int, text: ?string}>
     */
    private function forSegmenting($pages): array
    {
        return $pages->map(fn (BookPage $page) => [
            'page_number' => $page->page_number,
            'text' => $page->text,
        ])->values()->all();
    }

    /** @param  list<int>  $pageNumbers */
    private function firstPageImage(Book $book, array $pageNumbers): ?string
    {
        if ($pageNumbers === []) {
            return null;
        }

        return $book->pages()
            ->where('page_number', $pageNumbers[0])
            ->first()?->image_url;
    }

    private function fail(IngestBatch $batch, string $reason): void
    {
        $batch->update([
            'status' => 'failed',
            'error' => mb_substr($reason, 0, 1000),
            'completed_at' => now(),
        ]);
    }

    private function titleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[_\-]+/', ' ', $base) ?? $base);

        return $base === '' ? 'Untitled book' : mb_convert_case($base, MB_CASE_TITLE, 'UTF-8');
    }
}
