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
        // pages_count: a picture book's chapter is measured in pages.
        return $book->chapters()->withCount(['quizQuestions', 'pages'])->get();
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
