<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Book\Services\BookPageService;
use App\Domain\Chapter\Models\Chapter;
use App\Http\Controllers\Controller;
use App\Http\Requests\RescanBookPageRequest;
use App\Http\Requests\UpdateBookPageRequest;
use App\Http\Requests\UploadBookPageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class BookPageController extends Controller
{
    public function __construct(private BookPageService $service) {}

    public function index(Book $book): JsonResponse
    {
        return response()->json([
            'data' => $this->service->forBook($book),
            // The chapters the pages hang under, so the page list can be shown
            // as Book → Chapters → Pages.
            'chapters' => $book->chapters()->get(['id', 'book_id', 'chapter_number', 'title']),
        ]);
    }

    public function store(UploadBookPageRequest $request, Book $book): JsonResponse
    {
        $chapter = $request->filled('chapter_id')
            ? Chapter::where('book_id', $book->id)->findOrFail($request->integer('chapter_id'))
            : null;

        $page = $this->service->createFromUpload($book, $request->file('image'), $chapter);

        return response()->json(['data' => $page], 201);
    }

    /** Add a page by typing its sentences into a chapter. */
    public function storeText(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'chapter_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:5000'],
        ]);

        $chapter = Chapter::where('book_id', $book->id)->findOrFail($data['chapter_id']);

        return response()->json(['data' => $this->service->createText($book, $chapter, $data['text'])], 201);
    }

    /** Make a chapter's pages from its story text, a few sentences to a page. */
    public function generate(Request $request, Chapter $chapter): JsonResponse
    {
        $data = $request->validate([
            'sentences_per_page' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $pages = $this->service->generateForChapter($chapter, (int) ($data['sentences_per_page'] ?? 5));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $pages], 201);
    }

    public function update(UpdateBookPageRequest $request, BookPage $page): JsonResponse
    {
        $page = $this->service->updateText($page, $request->input('text'));

        return response()->json(['data' => $page]);
    }

    /** Read the page again, optionally from a replacement photo. */
    public function rescan(RescanBookPageRequest $request, BookPage $page): JsonResponse
    {
        try {
            $page = $this->service->rescan($page, $request->file('image'));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $page]);
    }

    public function destroy(BookPage $page): JsonResponse
    {
        $this->service->delete($page);

        return response()->json(['message' => 'Page deleted successfully.']);
    }
}
