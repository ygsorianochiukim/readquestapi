<?php

namespace App\Domain\Book\Repositories;

use App\Domain\Book\Models\Book;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Number the given books 1, 2, 3… in the order given. Books left out keep
     * their place after them, in the order they already had.
     *
     * @param  list<int>  $bookIds
     */
    public function reorder(array $bookIds): void
    {
        DB::transaction(function () use ($bookIds) {
            $rest = Book::whereNotIn('id', $bookIds)
                ->orderBy('sequence')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ([...$bookIds, ...$rest] as $position => $bookId) {
                Book::whereKey($bookId)->update(['sequence' => $position + 1]);
            }
        });
    }

    public function delete(Book $book): void
    {
        $book->delete();
    }
}
