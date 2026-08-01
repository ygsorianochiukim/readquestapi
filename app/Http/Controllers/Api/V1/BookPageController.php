<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Book\Services\BookPageService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBookPageRequest;
use App\Http\Requests\UploadBookPageRequest;
use Illuminate\Http\JsonResponse;

class BookPageController extends Controller
{
    public function __construct(private BookPageService $service) {}

    public function index(Book $book): JsonResponse
    {
        return response()->json(['data' => $this->service->forBook($book)]);
    }

    public function store(UploadBookPageRequest $request, Book $book): JsonResponse
    {
        $page = $this->service->createFromUpload($book, $request->file('image'));

        return response()->json(['data' => $page], 201);
    }

    public function update(UpdateBookPageRequest $request, BookPage $page): JsonResponse
    {
        $page = $this->service->updateText($page, $request->input('text'));

        return response()->json(['data' => $page]);
    }

    public function destroy(BookPage $page): JsonResponse
    {
        $this->service->delete($page);

        return response()->json(['message' => 'Page deleted successfully.']);
    }
}
