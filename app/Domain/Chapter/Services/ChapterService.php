<?php

namespace App\Domain\Chapter\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Repositories\ChapterRepository;
use Illuminate\Database\Eloquent\Collection;

class ChapterService
{
    public function __construct(private ChapterRepository $repository) {}

    /**
     * @return Collection<int, Chapter>
     */
    public function listForBook(Book $book): Collection
    {
        return $this->repository->forBook($book);
    }

    public function create(Book $book, array $data): Chapter
    {
        return $this->repository->create($book, $data);
    }

    public function update(Chapter $chapter, array $data): Chapter
    {
        return $this->repository->update($chapter, $data);
    }

    public function delete(Chapter $chapter): void
    {
        $this->repository->delete($chapter);
    }
}
