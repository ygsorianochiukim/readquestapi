<?php

namespace App\Domain\QuizQuestion\Services;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\QuizQuestion\Repositories\QuizQuestionRepository;
use Illuminate\Database\Eloquent\Collection;

class QuizQuestionService
{
    public function __construct(
        private QuizQuestionRepository $repository,
        private QuizGeneratorService $generator,
    ) {}

    /**
     * @return Collection<int, QuizQuestion>
     */
    public function listForChapter(Chapter $chapter): Collection
    {
        return $this->repository->forChapter($chapter);
    }

    /**
     * Write the chapter's generated questions again from its text. Questions
     * the teacher edited are kept.
     *
     * @return Collection<int, QuizQuestion>
     */
    public function regenerate(Chapter $chapter): Collection
    {
        $this->generator->regenerate($chapter);

        return $this->repository->forChapter($chapter);
    }

    /**
     * A teacher's correction. Once edited, the question is theirs: a later
     * regenerate leaves it alone.
     */
    public function update(QuizQuestion $question, array $data): QuizQuestion
    {
        return $this->repository->update($question, $data + ['is_generated' => false]);
    }

    public function delete(QuizQuestion $question): void
    {
        $this->repository->delete($question);
    }
}
