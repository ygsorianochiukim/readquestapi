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

    public function create(array $data): Book
    {
        return $this->repository->create($data);
    }

    public function update(Book $book, array $data): Book
    {
        return $this->repository->update($book, $data);
    }

    public function delete(Book $book): void
    {
        $this->repository->delete($book);
    }
}
