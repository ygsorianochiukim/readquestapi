<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Services\BookService;
use App\Domain\Progress\Services\ClassProgressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Books are made by uploading reading material (see IngestController), never
 * as an empty shell typed in here. This is for listing, editing a book's
 * details (title, level, cover) and removing it.
 */
class BookController extends Controller
{
    public function __construct(
        private BookService $service,
        private ClassProgressService $classProgress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->list(),
            // How far this teacher's own class has got through each book, so
            // the shelf shows whether the material is actually being read.
            'progress' => $this->classProgress->bookSummaries($request->user()),
        ]);
    }

    public function show(Book $book): JsonResponse
    {
        return response()->json(['data' => $book->load('chapters')]);
    }

    public function update(UpdateBookRequest $request, Book $book): JsonResponse
    {
        $book = $this->service->update($book, $request->validated());

        return response()->json(['data' => $book]);
    }

    public function destroy(Book $book): JsonResponse
    {
        $this->service->delete($book);

        return response()->json(['message' => 'Book deleted successfully.']);
    }
}
