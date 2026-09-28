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
        // A draft is a book an upload is still building. It is kept out of the
        // library listing, and guessing its id must not be a way in either.
        abort_if($book->status === 'draft', 404, 'That book is not available yet.');

        return response()->json([
            'data' => $book->load(['pages', 'chapters']),
        ]);
    }
}
