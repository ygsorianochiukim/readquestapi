<?php

namespace App\Domain\Progress\Services;

use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Models\ReadingProgress;
use App\Domain\Progress\Repositories\ProgressRepository;
use App\Domain\Student\Models\Student;
use Illuminate\Support\Collection;

class ProgressService
{
    /** A quiz counts as passed at or above this percentage. */
    public const QUIZ_PASS_PERCENT = 70;

    /** A read-aloud attempt counts as passed at or above this pronunciation score. */
    public const PRONUNCIATION_PASS = 60;

    public function __construct(
        private ProgressRepository $repository,
        private RewardService $rewards,
    ) {}

    // ============================================================
    //  Read models (for the student experience + teacher monitoring)
    // ============================================================

    /**
     * Overview of every book assigned to a student: completion + lock state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overviewForStudent(Student $student): array
    {
        $progressMap = $this->repository->forStudent($student->id);
        $books = $student->books()->with('chapters')->get();

        $result = [];
        $previousBookComplete = true; // the first assigned book is always unlocked

        foreach ($books as $book) {
            $chapters = $book->chapters;
            $total = $chapters->count();
            $completed = $chapters->filter(
                fn (Chapter $chapter) => optional($progressMap->get($chapter->id))->status === 'completed'
            )->count();

            $unlocked = $previousBookComplete;
            $currentChapter = $this->firstIncompleteChapter($chapters, $progressMap);

            $result[] = [
                'id' => $book->id,
                'title' => $book->title,
                'description' => $book->description,
                'cover_image_url' => $book->cover_image_url,
                'reading_level' => $book->reading_level,
                'sequence' => $book->sequence,
                'type' => $book->type,
                'total_chapters' => $total,
                'completed_chapters' => $completed,
                'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
                'is_locked' => ! $unlocked,
                'is_completed' => $total > 0 && $completed === $total,
                'current_chapter_id' => $currentChapter?->id,
            ];

            $previousBookComplete = $total > 0 && $completed === $total;
        }

        return $result;
    }

    /**
     * Chapters of a book annotated with the student's progress + lock state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chaptersForBook(Student $student, Book $book): array
    {
        $progressMap = $this->repository->forStudent($student->id);
        $chapters = $book->chapters()->withCount('quizQuestions')->get();
        $bookUnlocked = $this->isBookUnlocked($student, $book, $progressMap);

        $result = [];
        $previousCompleted = true;

        foreach ($chapters as $chapter) {
            $progress = $progressMap->get($chapter->id);
            $completed = optional($progress)->status === 'completed';
            $unlocked = $bookUnlocked && $previousCompleted;

            $result[] = [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'image_url' => $chapter->image_url,
                'has_quiz' => $chapter->quiz_questions_count > 0,
                'is_locked' => ! $unlocked,
                'progress' => $this->presentProgress($progress),
            ];

            $previousCompleted = $completed;
        }

        return $result;
    }

    /**
     * Detailed, per-book/per-chapter progress for a student (teacher monitoring & reports).
     *
     * @return array<string, mixed>
     */
    public function detailForStudent(Student $student): array
    {
        $books = $student->books()->with('chapters')->get();

        $out = [];
        $totalChapters = 0;
        $completedChapters = 0;

        foreach ($books as $book) {
            $chapters = $this->chaptersForBook($student, $book);
            $done = collect($chapters)->filter(
                fn ($chapter) => ($chapter['progress']['status'] ?? null) === 'completed'
            )->count();

            $totalChapters += count($chapters);
            $completedChapters += $done;

            $out[] = [
                'id' => $book->id,
                'title' => $book->title,
                'reading_level' => $book->reading_level,
                'total_chapters' => count($chapters),
                'completed_chapters' => $done,
                'chapters' => $chapters,
            ];
        }

        return [
            'books' => $out,
            'total_chapters' => $totalChapters,
            'completed_chapters' => $completedChapters,
            'percent' => $totalChapters > 0 ? (int) round($completedChapters / $totalChapters * 100) : 0,
        ];
    }

    /** Whether a chapter is currently accessible to the student (book assigned + unlocked chain). */
    public function isChapterUnlocked(Student $student, Chapter $chapter): bool
    {
        $book = $chapter->book;
        if (! $book) {
            return false;
        }

        $progressMap = $this->repository->forStudent($student->id);
        if (! $this->isBookUnlocked($student, $book, $progressMap)) {
            return false;
        }

        // Every earlier chapter of this book must be completed.
        $earlier = $book->chapters()
            ->where('chapter_number', '<', $chapter->chapter_number)
            ->get();

        foreach ($earlier as $previous) {
            if (optional($progressMap->get($previous->id))->status !== 'completed') {
                return false;
            }
        }

        return true;
    }

    // ============================================================
    //  Activity completion (each returns the fresh progress row)
    // ============================================================

    public function markStoryRead(Student $student, Chapter $chapter): ReadingProgress
    {
        $progress = $this->begin($student, $chapter);
        $progress->story_read = true;

        return $this->reconcile($student, $chapter, $progress);
    }

    public function markGameCompleted(Student $student, Chapter $chapter): ReadingProgress
    {
        $progress = $this->begin($student, $chapter);
        $progress->game_completed = true;

        return $this->reconcile($student, $chapter, $progress);
    }

    /** Called when a read-aloud attempt is scored, so a chapter can auto-advance. */
    public function recordPronunciation(Student $student, Chapter $chapter, ?float $pronScore): ReadingProgress
    {
        $progress = $this->begin($student, $chapter);
        if ($pronScore !== null && $pronScore >= self::PRONUNCIATION_PASS) {
            $progress->pronunciation_passed = true;
        }

        return $this->reconcile($student, $chapter, $progress);
    }

    /**
     * Grade a submitted quiz and update progress.
     *
     * @param  array<int|string, string>  $answers  map of question id => chosen answer
     * @return array<string, mixed>
     */
    public function submitQuiz(Student $student, Chapter $chapter, array $answers): array
    {
        $questions = $chapter->quizQuestions()->get();
        $total = $questions->count();

        $correct = 0;
        $review = [];
        foreach ($questions as $question) {
            $given = $answers[$question->id] ?? null;
            $isCorrect = $given !== null && $given === $question->correct_answer;
            if ($isCorrect) {
                $correct++;
            }
            $review[] = [
                'question_id' => $question->id,
                'correct_answer' => $question->correct_answer,
                'given_answer' => $given,
                'is_correct' => $isCorrect,
            ];
        }

        $percent = $total > 0 ? (int) round($correct / $total * 100) : 100;
        $passed = $percent >= self::QUIZ_PASS_PERCENT;

        $progress = $this->begin($student, $chapter);
        $progress->quiz_score = max((int) $progress->quiz_score, $percent);
        if ($passed) {
            $progress->quiz_passed = true;
        }
        $progress = $this->reconcile($student, $chapter, $progress);

        if ($percent === 100) {
            $this->awardByName($student, 'Quiz Master');
        }

        return [
            'score' => $percent,
            'correct' => $correct,
            'total' => $total,
            'passed' => $passed,
            'review' => $review,
            'progress' => $this->presentProgress($progress),
        ];
    }

    // ============================================================
    //  Internals
    // ============================================================

    private function begin(Student $student, Chapter $chapter): ReadingProgress
    {
        $progress = $this->repository->firstOrCreateFor($student->id, $chapter->id);

        if ($progress->status === 'not_started') {
            $progress->status = 'in_progress';
            $progress->is_unlocked = true;
            $progress->started_at ??= now();
        }

        return $progress;
    }

    /** Persist the row, and if all required activities are done, complete it and cascade unlocks + badges. */
    private function reconcile(Student $student, Chapter $chapter, ReadingProgress $progress): ReadingProgress
    {
        $quizRequired = $chapter->quizQuestions()->exists();

        $done = $progress->story_read
            && $progress->pronunciation_passed
            && $progress->game_completed
            && (! $quizRequired || $progress->quiz_passed);

        if ($done && $progress->status !== 'completed') {
            $progress->status = 'completed';
            $progress->completed_at = now();
        }

        $this->repository->save($progress);

        if ($progress->status === 'completed') {
            $this->onChapterCompleted($student, $chapter);
        }

        return $progress->refresh();
    }

    private function onChapterCompleted(Student $student, Chapter $chapter): void
    {
        // First chapter ever completed.
        $this->awardByName($student, 'First Steps');

        $book = $chapter->book;
        if (! $book) {
            return;
        }

        // Unlock the next chapter in the same book.
        $next = $book->chapters()
            ->where('chapter_number', '>', $chapter->chapter_number)
            ->orderBy('chapter_number')
            ->first();

        if ($next) {
            $nextProgress = $this->repository->firstOrCreateFor($student->id, $next->id);
            if (! $nextProgress->is_unlocked) {
                $nextProgress->is_unlocked = true;
                $this->repository->save($nextProgress);
            }
        }

        // Whole-book / all-books badges.
        $progressMap = $this->repository->forStudent($student->id);

        if ($this->isBookFullyCompleted($book, $progressMap)) {
            $this->awardByName($student, 'Bookworm');

            $allAssignedComplete = $student->books()->with('chapters')->get()
                ->every(fn (Book $assigned) => $this->isBookFullyCompleted($assigned, $progressMap));

            if ($allAssignedComplete) {
                $this->awardByName($student, 'Reading Star');
            }
        }
    }

    /** @param Collection<int, ReadingProgress> $progressMap */
    private function isBookUnlocked(Student $student, Book $book, $progressMap): bool
    {
        $assigned = $student->books()->with('chapters')->get();

        $index = $assigned->search(fn (Book $candidate) => $candidate->id === $book->id);
        if ($index === false) {
            return false; // not assigned to this student
        }
        if ($index === 0) {
            return true;
        }

        $previousBook = $assigned[$index - 1];

        return $this->isBookFullyCompleted($previousBook, $progressMap);
    }

    /** @param Collection<int, ReadingProgress> $progressMap */
    private function isBookFullyCompleted(Book $book, $progressMap): bool
    {
        $chapters = $book->relationLoaded('chapters') ? $book->chapters : $book->chapters()->get();
        if ($chapters->isEmpty()) {
            return false;
        }

        return $chapters->every(
            fn (Chapter $chapter) => optional($progressMap->get($chapter->id))->status === 'completed'
        );
    }

    /** @param Collection<int, Chapter> $chapters */
    private function firstIncompleteChapter($chapters, $progressMap): ?Chapter
    {
        foreach ($chapters as $chapter) {
            if (optional($progressMap->get($chapter->id))->status !== 'completed') {
                return $chapter;
            }
        }

        return $chapters->first();
    }

    /** @return array<string, mixed>|null */
    private function presentProgress(?ReadingProgress $progress): ?array
    {
        if (! $progress) {
            return null;
        }

        return [
            'status' => $progress->status,
            'story_read' => $progress->story_read,
            'pronunciation_passed' => $progress->pronunciation_passed,
            'game_completed' => $progress->game_completed,
            'quiz_passed' => $progress->quiz_passed,
            'quiz_score' => $progress->quiz_score,
            'completed_at' => $progress->completed_at,
        ];
    }

    private function awardByName(Student $student, string $badgeName): void
    {
        $badge = Badge::where('name', $badgeName)->first();
        if ($badge) {
            $this->rewards->award($student, $badge);
        }
    }
}
