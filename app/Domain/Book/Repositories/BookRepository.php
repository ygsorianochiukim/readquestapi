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
        return Book::withCount('chapters')
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
