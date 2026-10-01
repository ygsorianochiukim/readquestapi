<?php

namespace App\Domain\Book\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Repositories\BookRepository;
use Illuminate\Database\Eloquent\Collection;

class BookService
{
    public function __construct(private BookRepository $repository) {}

    /**
     * @return Collection<int, Book>
     */
    public function list(): Collection
    {
        return $this->repository->all();
    }

    public function update(Book $book, array $data): Book
    {
        return $this->repository->update($book, $data);
    }

    /**
     * Set the reading order: pupils meet the books in this order, and each
     * unlocks when the one before it is finished.
     *
     * @param  list<int>  $bookIds
     * @return Collection<int, Book>
     */
    public function reorder(array $bookIds): Collection
    {
        $this->repository->reorder($bookIds);

        return $this->repository->all();
    }

    public function delete(Book $book): void
    {
        $this->repository->delete($book);
    }
}
