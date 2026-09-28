<?php

namespace App\Domain\Book\Repositories;

use App\Domain\Book\Models\Book;
use Illuminate\Database\Eloquent\Collection;

class BookRepository
{
    /**
     * @return Collection<int, Book>
     */
    public function all(): Collection
    {
        // Drafts are books an upload is still building. They belong on the
        // upload screen, which owns them until the teacher publishes; showing
        // them here would offer half-read books for assignment.
        return Book::withCount(['chapters', 'pages'])
            ->where('status', '!=', 'draft')
            ->orderBy('sequence')
            ->get();
    }

    public function create(array $data): Book
    {
        return Book::create($data);
    }

    public function update(Book $book, array $data): Book
    {
        $book->update($data);

        return $book->refresh();
    }

    public function delete(Book $book): void
    {
        $book->delete();
    }
}
