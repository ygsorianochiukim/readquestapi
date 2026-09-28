<?php

namespace App\Domain\Chapter\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Services\BookPageService;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Repositories\ChapterRepository;
use App\Domain\QuizQuestion\Services\QuizGeneratorService;
use Illuminate\Database\Eloquent\Collection;

class ChapterService
{
    public function __construct(
        private ChapterRepository $repository,
        private QuizGeneratorService $quizzes,
        private BookPageService $pages,
    ) {}

    /**
     * @return Collection<int, Chapter>
     */
    public function listForBook(Book $book): Collection
    {
        return $this->repository->forBook($book);
    }

    /**
     * Save a teacher's edit — a title, or a fix to what the scanner misread.
     * When the story text changes, the generated quiz is rewritten to match
     * (unless the teacher has curated the questions themselves).
     */
    public function update(Chapter $chapter, array $data): Chapter
    {
        $textChanged = array_key_exists('story_text', $data)
            && (string) $data['story_text'] !== (string) $chapter->story_text;

        $chapter = $this->repository->update($chapter, $data);

        if ($textChanged && filled($chapter->story_text)) {
            $this->quizzes->generateIfUncurated($chapter);
        }

        return $chapter;
    }

    /**
     * Remove a chapter. A picture book's chapter *is* its pages, so they go
     * with it rather than being left outside every chapter.
     */
    public function delete(Chapter $chapter): void
    {
        if ($chapter->book?->isPageBased()) {
            foreach ($chapter->pages()->get() as $page) {
                $this->pages->delete($page);
            }
        }

        $this->repository->delete($chapter);
    }
}
