<?php

namespace App\Domain\Progress\Services;

use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Book\Models\Book;
use App\Domain\Celebration\CelebrationBag;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Services\ChapterParagraphs;
use App\Domain\Progress\Models\ChapterGameResult;
use App\Domain\Progress\Models\ReadingProgress;
use App\Domain\Progress\Repositories\ProgressRepository;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Services\SystemLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProgressService
{
    /** A quiz counts as passed at or above this percentage. */
    public const QUIZ_PASS_PERCENT = 70;

    /** A read-aloud attempt counts as passed at or above this pronunciation score. */
    public const PRONUNCIATION_PASS = 60;

    /** Points for the first win of each mini-game type on a chapter. */
    public const GAME_POINTS = 10;

    /** Extra points when that first win had no mistakes. */
    public const GAME_PERFECT_BONUS = 5;

    public function __construct(
        private ProgressRepository $repository,
        private RewardService $rewards,
        private AchievementService $achievements,
        private SystemLogService $logs,
        private PageProgressService $pages,
        private CelebrationBag $celebrations,
        private ChapterParagraphs $paragraphs,
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
        $pageProgressMap = $this->pages->pageProgressFor($student);
        $books = $student->books()->with(['chapters', 'pages'])->get();

        $result = [];
        $previousBookComplete = true; // the first assigned book is always unlocked

        foreach ($books as $book) {
            // Page-based (picture) books are measured in pages, not chapters.
            // Their chapters only group the pages, and never run the learning
            // loop, so they are not counted as chapters to complete here.
            $isPageBased = $book->isPageBased() || $book->chapters->isEmpty();
            $chapters = $isPageBased ? collect() : $book->chapters;
            $total = $chapters->count();
            $completed = $chapters->filter(
                fn (Chapter $chapter) => optional($progressMap->get($chapter->id))->status === 'completed'
            )->count();

            $unlocked = $previousBookComplete;
            $currentChapter = $this->firstIncompleteChapter($chapters, $progressMap);

            $pages = $isPageBased
                ? $this->pages->summaryForBook($student, $book, $pageProgressMap)
                : null;

            $entry = [
                'id' => $book->id,
                'title' => $book->title,
                'description' => $book->description,
                'cover_image_url' => $book->cover_image_url,
                'reading_level' => $book->reading_level,
                'sequence' => $book->sequence,
                'type' => $book->type,
                'total_chapters' => $total,
                'completed_chapters' => $completed,
                'total_pages' => $pages['total'] ?? 0,
                'completed_pages' => $pages['completed'] ?? 0,
                'percent' => $pages
                    ? $pages['percent']
                    : ($total > 0 ? (int) round($completed / $total * 100) : 0),
                'is_locked' => ! $unlocked,
                'is_completed' => $pages
                    ? $pages['is_completed']
                    : ($total > 0 && $completed === $total),
                'current_chapter_id' => $currentChapter?->id,
            ];

            // A picture book is still Book → Chapters → Pages: list its
            // chapters with how far this pupil has read through each.
            if ($book->isPageBased()) {
                $entry['page_chapters'] = $this->pages->chaptersForBook($student, $book, $pageProgressMap);
            }

            $result[] = $entry;

            // Page-based books have no chapters to complete, so they leave the
            // gate as they found it instead of blocking everything after them.
            if ($total > 0) {
                $previousBookComplete = $completed === $total;
            }
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
        // Picture-book chapters are read page by page (see PageProgressService),
        // not through the chapter activities, so there is nothing to list here.
        if ($book->isPageBased()) {
            return [];
        }

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

    /**
     * A mini-game was won. Any one game satisfies the chapter's game step; each
     * game type pays points the first time only, so replays are for fun, not
     * for farming.
     *
     * @return array<string, mixed>
     */
    public function recordGameWin(Student $student, Chapter $chapter, string $gameType, int $mistakes): array
    {
        $perfect = $mistakes === 0;
        $points = self::GAME_POINTS + ($perfect ? self::GAME_PERFECT_BONUS : 0);

        $firstWin = DB::transaction(function () use ($student, $chapter, $gameType, $perfect, $points) {
            $result = ChapterGameResult::firstOrCreate(
                ['student_id' => $student->id, 'chapter_id' => $chapter->id, 'game_type' => $gameType],
                ['points_awarded' => $points, 'perfect' => $perfect, 'completed_at' => now()],
            );

            if ($result->wasRecentlyCreated) {
                $student->increment('points', $points);
            }

            return $result->wasRecentlyCreated;
        });

        if ($firstWin) {
            $this->logs->record(
                'game.completed',
                "{$student->full_name} won the {$gameType} game on chapter {$chapter->chapter_number} — \"{$chapter->title}\" (+{$points} points).",
                $student,
            );
        }

        // Reconciling syncs achievements, so the points above count toward
        // any points milestone on this same request.
        $progress = $this->markGameCompleted($student->refresh(), $chapter);

        return [
            'game_type' => $gameType,
            'points_earned' => $firstWin ? $points : 0,
            'first_completion' => $firstWin,
            'perfect' => $firstWin && $perfect,
            'games' => $this->gamesForChapter($student, $chapter),
            'total_points' => (int) $student->refresh()->points,
            'progress' => $this->presentProgress($progress),
        ];
    }

    /**
     * Which mini-games the student has won on a chapter, and what each paid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gamesForChapter(Student $student, Chapter $chapter): array
    {
        return ChapterGameResult::where('student_id', $student->id)
            ->where('chapter_id', $chapter->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ChapterGameResult $result) => [
                'game_type' => $result->game_type,
                'points_awarded' => $result->points_awarded,
                'perfect' => $result->perfect,
                'completed_at' => $result->completed_at,
            ])
            ->values()
            ->all();
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
     * A paragraph of the chapter was read aloud on its own. The chapter's
     * read-aloud is passed once every paragraph has been read and the best
     * tries average out at the pass mark — one hard sentence does not fail a
     * young reader, and reading a page again can only raise the average.
     */
    public function recordParagraphReading(Student $student, Chapter $chapter): ReadingProgress
    {
        $progress = $this->begin($student, $chapter);

        if ($this->readAloudSummary($student, $chapter)['passed']) {
            $progress->pronunciation_passed = true;
        }

        return $this->reconcile($student, $chapter, $progress);
    }

    /**
     * How far through reading the chapter aloud, page by page, the pupil is.
     *
     * @return array{pages_total: int, pages_read: int, average: ?int, passed: bool, pages: list<array{book_page_id: int, paragraph_index: int, best_score: ?int}>}
     */
    public function readAloudSummary(Student $student, Chapter $chapter): array
    {
        $paragraphs = $this->paragraphs->forChapter($chapter);

        // Best score per paragraph; a teacher's verdict counts over the machine's.
        $best = [];
        PronunciationAttempt::where('student_id', $student->id)
            ->where('chapter_id', $chapter->id)
            ->whereNotNull('paragraph_index')
            ->get(['book_page_id', 'paragraph_index', 'pron_score', 'teacher_score'])
            ->each(function (PronunciationAttempt $attempt) use (&$best) {
                $key = "{$attempt->book_page_id}:{$attempt->paragraph_index}";
                $score = (float) ($attempt->effective_score ?? 0);
                $best[$key] = max($best[$key] ?? 0, $score);
            });

        $pages = array_map(function (array $paragraph) use ($best) {
            $key = "{$paragraph['book_page_id']}:{$paragraph['paragraph_index']}";

            return [
                'book_page_id' => $paragraph['book_page_id'],
                'paragraph_index' => $paragraph['paragraph_index'],
                'best_score' => isset($best[$key]) ? (int) round($best[$key]) : null,
            ];
        }, $paragraphs);

        $scores = array_values(array_filter(array_column($pages, 'best_score'), fn ($score) => $score !== null));
        $average = $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
        $allRead = $pages !== [] && count($scores) === count($pages);

        return [
            'pages_total' => count($pages),
            'pages_read' => count($scores),
            'average' => $average,
            'passed' => $allRead && $average >= self::PRONUNCIATION_PASS,
            'pages' => $pages,
        ];
    }

    /**
     * Check one quiz answer the moment the child picks it, for instant
     * feedback. Records nothing: the graded submission is what counts, and
     * answers are only revealed one question at a time, after a guess.
     *
     * @return array{correct: bool, correct_answer: string}
     */
    public function checkQuizAnswer(Chapter $chapter, int $questionId, ?string $answer): array
    {
        $question = $chapter->quizQuestions()->whereKey($questionId)->firstOrFail();

        return [
            'correct' => $this->isAnswerCorrect($question, $answer),
            'correct_answer' => $question->correct_answer,
        ];
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
            $isCorrect = $this->isAnswerCorrect($question, $given);
            if ($isCorrect) {
                $correct++;
            }
            $review[] = [
                'question_id' => $question->id,
                'correct_answer' => $question->correct_answer,
                'given_answer' => $given,
                'is_correct' => $isCorrect,
                // Same facts under the names the summary screen reads.
                'selected' => $given,
                'correct' => $isCorrect,
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

        $this->logs->record(
            'quiz.submitted',
            "{$student->full_name} scored {$percent}% on the quiz for chapter {$chapter->chapter_number} — \"{$chapter->title}\".",
            $student,
        );

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

        // Any scored activity can move a milestone forward.
        $this->achievements->sync($student);

        return $progress->refresh();
    }

    private function onChapterCompleted(Student $student, Chapter $chapter): void
    {
        $this->celebrations->milestone('chapter_completed');

        $this->logs->record(
            'chapter.completed',
            "{$student->full_name} completed chapter {$chapter->chapter_number} — \"{$chapter->title}\".",
            $student,
        );

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
            $this->celebrations->milestone('book_completed');
            $this->awardByName($student, 'Bookworm');

            // Page-based books have no chapters to finish, so they are not part
            // of the "finished everything" check.
            $allAssignedComplete = $student->books()->with('chapters')->get()
                ->filter(fn (Book $assigned) => ! $assigned->isPageBased() && $assigned->chapters->isNotEmpty())
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

        // Walk back to the nearest earlier book that actually has chapters.
        // A page-based book can never be "completed", so it must not gate the
        // rest of the journey.
        for ($position = $index - 1; $position >= 0; $position--) {
            $previousBook = $assigned[$position];
            if ($previousBook->isPageBased() || $previousBook->chapters->isEmpty()) {
                continue;
            }

            return $this->isBookFullyCompleted($previousBook, $progressMap);
        }

        return true;
    }

    /** @param Collection<int, ReadingProgress> $progressMap */
    private function isBookFullyCompleted(Book $book, $progressMap): bool
    {
        // A picture book's chapters only group its pages; they are never
        // "completed" through the learning loop.
        if ($book->isPageBased()) {
            return false;
        }

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

    /** The one scoring rule, shared by instant checks and graded submissions. */
    private function isAnswerCorrect(QuizQuestion $question, ?string $given): bool
    {
        return $given !== null && $given === $question->correct_answer;
    }

    private function awardByName(Student $student, string $badgeName): void
    {
        $badge = Badge::where('name', $badgeName)->first();
        if ($badge) {
            $this->rewards->award($student, $badge);
        }
    }
}
