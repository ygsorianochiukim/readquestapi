<?php

namespace App\Domain\Chapter\Repositories;

use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use Illuminate\Database\Eloquent\Collection;

class ChapterRepository
{
    /**
     * @return Collection<int, Chapter>
     */
    public function forBook(Book $book): Collection
    {
        return $book->chapters()->withCount('quizQuestions')->get();
    }

    public function create(Book $book, array $data): Chapter
    {
        return $book->chapters()->create($data);
    }

    public function update(Chapter $chapter, array $data): Chapter
    {
        $chapter->update($data);

        return $chapter->refresh();
    }

    public function delete(Chapter $chapter): void
    {
        $chapter->delete();
    }
}
