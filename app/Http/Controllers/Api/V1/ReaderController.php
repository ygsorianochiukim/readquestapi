<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ReaderController extends Controller
{
    /** Books available to read (any authenticated user). */
    public function books(): JsonResponse
    {
        $books = Book::where('status', 'active')
            ->withCount(['pages', 'chapters'])
            ->orderBy('sequence')
            ->get();

        return response()->json(['data' => $books]);
    }

    /** A single book with its pages and chapters for the reader. */
    public function show(Book $book): JsonResponse
    {
        return response()->json([
            'data' => $book->load(['pages', 'chapters']),
        ]);
    }
}
