<?php

namespace App\Domain\QuizQuestion\Repositories;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Collection;

class QuizQuestionRepository
{
    /**
     * @return Collection<int, QuizQuestion>
     */
    public function forChapter(Chapter $chapter): Collection
    {
        return $chapter->quizQuestions()->get();
    }

    public function create(Chapter $chapter, array $data): QuizQuestion
    {
        return $chapter->quizQuestions()->create($data);
    }

    public function update(QuizQuestion $question, array $data): QuizQuestion
    {
        $question->update($data);

        return $question->refresh();
    }

    public function delete(QuizQuestion $question): void
    {
        $question->delete();
    }
}
