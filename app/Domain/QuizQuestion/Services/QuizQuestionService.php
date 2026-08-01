<?php

namespace App\Domain\QuizQuestion\Services;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\QuizQuestion\Repositories\QuizQuestionRepository;
use Illuminate\Database\Eloquent\Collection;

class QuizQuestionService
{
    public function __construct(private QuizQuestionRepository $repository) {}

    /**
     * @return Collection<int, QuizQuestion>
     */
    public function listForChapter(Chapter $chapter): Collection
    {
        return $this->repository->forChapter($chapter);
    }

    public function create(Chapter $chapter, array $data): QuizQuestion
    {
        return $this->repository->create($chapter, $data);
    }

    public function update(QuizQuestion $question, array $data): QuizQuestion
    {
        return $this->repository->update($question, $data);
    }

    public function delete(QuizQuestion $question): void
    {
        $this->repository->delete($question);
    }
}
