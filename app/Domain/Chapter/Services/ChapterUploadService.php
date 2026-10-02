<?php

namespace App\Domain\Chapter\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Ingest\Services\ChapterContentAgent;
use App\Domain\Ingest\Services\ChapterSegmenter;
use App\Domain\Ingest\Services\PdfRasterizer;
use App\Domain\Ocr\Services\PageReader;
use App\Domain\Ocr\Services\PdfTextReader;
use App\Domain\Speech\Jobs\PrepareChapterNarration;
use App\Domain\QuizQuestion\Services\QuizGeneratorService;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Adding a chapter to a book by scanning it.
 *
 * Teachers used to add a chapter by typing its story into a box. Now they
 * photograph or upload its printed pages: the pages are read here, the chapter
 * is made from what was read, and — for a reader — its quiz is written from
 * that text too. The only typing left is fixing what the scanner misread.
 */
class ChapterUploadService
{
    public function __construct(
        private PageReader $ocr,
        private PdfTextReader $pdfText,
        private PdfRasterizer $rasterizer,
        private ChapterSegmenter $segmenter,
        private ChapterContentAgent $contentAgent,
        private QuizGeneratorService $quizzes,
    ) {}

    /**
     * @param  list<UploadedFile>  $files  one PDF, or photos of the chapter's pages in order
     *
     * @throws RuntimeException with a message the teacher can act on
     */
    public function createFromUpload(Book $book, array $files, ?string $title = null): Chapter
    {
        if ($files === []) {
            throw new RuntimeException('Choose a PDF or photos of the chapter\'s pages.');
        }

        // A reader's chapter *is* its words; without a scanner there would be
        // nothing to put in it. A picture book's pages are worth keeping even
        // when they cannot be read.
        if (! $book->isPageBased() && ! $this->ocr->isConfigured()) {
            throw new RuntimeException('Scanning is not set up yet. Add AZURE_VISION_KEY and AZURE_VISION_ENDPOINT to enable it.');
        }

        $pages = $this->isPdf($files[0])
            ? $this->pagesFromPdf($book, $files[0])
            : $this->pagesFromImages($book, $files);

        // The chapter's own words only: no footers, page numbers or exercises.
        $pages = $this->contentAgent->keepChapterContent($pages);

        $texts =array_values(array_filter(array_column($pages, 'text'), 'filled'));

        if (! $book->isPageBased() && $texts === []) {
            $this->forget($pages);

            throw new RuntimeException('No words were found on those pages. Try a clearer, straighter photo of each page.');
        }

        return DB::transaction(function () use ($book, $pages, $texts, $title) {
            $number = (int) ($book->chapters()->max('chapter_number') ?? 0) + 1;
            $heading = $texts !== [] ? $this->segmenter->headingOf($texts[0]) : null;

            $chapter = Chapter::create([
                'book_id' => $book->id,
                'chapter_number' => $number,
                'title' => filled($title) ? $title : ($heading ?? "Chapter {$number}"),
                // A picture book's content is its pages; a reader's is the text.
                'story_text' => $book->isPageBased() ? null : $this->storyText($texts),
                'image_url' => collect($pages)->pluck('image_path')->filter()->map(
                    fn (string $path) => Storage::disk('public')->url($path)
                )->first(),
            ]);

            $nextPage = (int) ($book->pages()->max('page_number') ?? 0);

            foreach ($pages as $page) {
                BookPage::create([
                    'book_id' => $book->id,
                    'chapter_id' => $chapter->id,
                    'page_number' => ++$nextPage,
                    'image_path' => $page['image_path'],
                    'text' => $page['text'],
                ]);
            }

            if (! $book->isPageBased()) {
                $this->quizzes->generateIfUncurated($chapter);
            }

            // Speak its pages in the background once the chapter is saved.
            PrepareChapterNarration::dispatch($chapter->id)->afterCommit();

            return $chapter->loadCount('quizQuestions');
        });
    }

    // ============================================================
    //  Internals
    // ============================================================

    /** @param  list<string>  $texts */
    private function storyText(array $texts): string
    {
        return trim(implode("\n\n", array_map(
            fn (string $text) => $this->segmenter->bodyOf($text),
            $texts,
        )));
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{image_path: ?string, text: ?string}>
     */
    private function pagesFromImages(Book $book, array $files): array
    {
        $pages = [];

        foreach ($files as $file) {
            $bytes = (string) file_get_contents($file->getRealPath());

            $pages[] = [
                'image_path' => $file->store("book-pages/{$book->id}", 'public'),
                'text' => $this->read($bytes),
            ];
        }

        return $pages;
    }

    /** @return list<array{image_path: ?string, text: ?string}> */
    private function pagesFromPdf(Book $book, UploadedFile $file): array
    {
        if ($this->rasterizer->isAvailable()) {
            $workDir = storage_path('app/chapter-uploads/'.Str::uuid());
            $pages = [];

            try {
                foreach ($this->rasterizer->rasterize($file->getRealPath(), $workDir) as $image) {
                    $pages[] = [
                        'image_path' => Storage::disk('public')->putFile("book-pages/{$book->id}", new File($image)),
                        'text' => $this->read((string) file_get_contents($image)),
                    ];
                    @unlink($image);
                }
            } finally {
                @rmdir($workDir);
            }

            return $pages;
        }

        // No renderer on this host: the words can still be read from the PDF
        // itself, but its pictures cannot be kept.
        if ($book->isPageBased() || ! $this->pdfText->isConfigured()) {
            throw new RuntimeException('This server cannot turn PDF pages into pictures. Upload photos of the pages instead.');
        }

        $texts = $this->pdfText->readPages(
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalName() ?: 'chapter.pdf',
        );

        return array_map(fn (string $text) => ['image_path' => null, 'text' => blank($text) ? null : $text], $texts);
    }

    /** OCR one page. A picture page with no words is not a failure. */
    private function read(string $bytes): ?string
    {
        if (! $this->ocr->isConfigured()) {
            return null;
        }

        try {
            $text = $this->ocr->extractText($bytes);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return blank($text) ? null : $text;
    }

    /** @param  list<array{image_path: ?string, text: ?string}>  $pages */
    private function forget(array $pages): void
    {
        foreach ($pages as $page) {
            if ($page['image_path']) {
                Storage::disk('public')->delete($page['image_path']);
            }
        }
    }

    private function isPdf(UploadedFile $file): bool
    {
        return strtolower((string) $file->getClientOriginalExtension()) === 'pdf'
            || $file->getMimeType() === 'application/pdf';
    }
}
