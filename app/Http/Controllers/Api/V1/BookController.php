<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Services\BookService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBookRequest;
use App\Http\Requests\UpdateBookRequest;
use Illuminate\Http\JsonResponse;

class BookController extends Controller
{
    public function __construct(private BookService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->list()]);
    }

    public function store(CreateBookRequest $request): JsonResponse
    {
        $book = $this->service->create($request->validated());

        return response()->json(['data' => $book], 201);
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
