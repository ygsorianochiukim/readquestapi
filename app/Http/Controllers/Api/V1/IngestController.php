<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\BookPage;
use App\Domain\Book\Services\BookPageService;
use App\Domain\Ingest\Models\IngestBatch;
use App\Domain\Ingest\Services\DocumentIngestService;
use App\Domain\Ingest\Services\PdfRasterizer;
use App\Http\Controllers\Controller;
use App\Http\Requests\RescanBookPageRequest;
use App\Http\Requests\StartIngestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Uploading reading material: drop a file in, watch it being read, check what
 * came out, publish it. There is no separate "add a book" step — the book is a
 * product of the upload.
 */
class IngestController extends Controller
{
    public function __construct(
        private DocumentIngestService $ingest,
        private PdfRasterizer $rasterizer,
        private BookPageService $pages,
    ) {}

    /** The teacher's recent uploads, so an interrupted one can be picked back up. */
    public function index(Request $request): JsonResponse
    {
        $batches = IngestBatch::with('book:id,title,type,status')
            ->where('teacher_id', $request->user()->id)
            ->latest()
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $batches,
            'meta' => [
                // The UI warns before the upload, not after: on a host with no
                // renderer a PDF loses its pictures, and a teacher uploading a
                // picture book needs to know that while they can still choose
                // to send photos instead.
                'can_render_pdf_pages' => $this->rasterizer->isAvailable(),
            ],
        ]);
    }

    /** Start a new upload. */
    public function store(StartIngestRequest $request): JsonResponse
    {
        try {
            $batch = $this->ingest->start(
                $request->user(),
                array_values($request->file('files')),
                $request->input('title'),
                $request->input('book_id') ? (int) $request->input('book_id') : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $batch], 201);
    }

    /** Poll this while the file is being read; it is also the preview. */
    public function show(Request $request, IngestBatch $batch): JsonResponse
    {
        $this->assertOwns($request, $batch);

        return response()->json(['data' => $this->ingest->preview($batch)]);
    }

    /** Correct the words we read off a page before publishing. */
    public function updatePage(Request $request, IngestBatch $batch, BookPage $page): JsonResponse
    {
        $this->assertOwns($request, $batch);

        abort_if($page->book_id !== $batch->book_id, 404, 'That page is not part of this upload.');

        $data = $request->validate([
            'text' => ['present', 'nullable', 'string'],
        ]);

        $page->update(['text' => $data['text']]);

        return response()->json(['data' => $page->refresh()]);
    }

    /**
     * Read one page again — from a replacement photo if the teacher sends one
     * (a blurry or cropped scan), otherwise from the picture already stored.
     * A page that came back empty is fixed by scanning it, not by typing.
     */
    public function rescanPage(RescanBookPageRequest $request, IngestBatch $batch, BookPage $page): JsonResponse
    {
        $this->assertOwns($request, $batch);

        abort_if($page->book_id !== $batch->book_id, 404, 'That page is not part of this upload.');

        try {
            $page = $this->pages->rescan($page, $request->file('image'));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $page]);
    }

    /** Publish the book. */
    public function commit(Request $request, IngestBatch $batch): JsonResponse
    {
        $this->assertOwns($request, $batch);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'reading_level' => ['nullable', 'string', 'max:50'],
            // Either way the book is Book → Chapters → Content. Off keeps the
            // page images as the content (a picture book, its pages grouped
            // into chapters); on turns the text into story chapters (a reader).
            'as_chapters' => ['nullable', 'boolean'],
            'chapters' => ['nullable', 'array'],
            'chapters.*.title' => ['required_with:chapters', 'string', 'max:255'],
            'chapters.*.chapter_number' => ['required_with:chapters', 'integer', 'min:1'],
            'chapters.*.story_text' => ['required_with:chapters', 'string'],
            'chapters.*.page_numbers' => ['nullable', 'array'],
            'parts' => ['nullable', 'array'],
            'parts.*.title' => ['nullable', 'string', 'max:255'],
            'parts.*.page_numbers' => ['required_with:parts', 'array'],
            'parts.*.page_numbers.*' => ['integer'],
        ]);

        try {
            $book = $this->ingest->commit(
                $batch,
                $data['title'] ?? null,
                $data['reading_level'] ?? null,
                (bool) ($data['as_chapters'] ?? false),
                // An empty list means "work it out", not "no chapters at all".
                ($data['chapters'] ?? null) ?: null,
                ($data['parts'] ?? null) ?: null,
            );
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $book]);
    }

    /** Throw the draft away. */
    public function destroy(Request $request, IngestBatch $batch): JsonResponse
    {
        $this->assertOwns($request, $batch);

        $this->ingest->discard($batch);

        return response()->json(['message' => 'Upload discarded.']);
    }

    private function assertOwns(Request $request, IngestBatch $batch): void
    {
        abort_if(
            $batch->teacher_id !== $request->user()->id,
            403,
            'This upload does not belong to you.',
        );
    }
}
