<?php

namespace App\Domain\Achievement\Services;

use App\Domain\Achievement\Models\Achievement;
use App\Domain\Achievement\Repositories\AchievementRepository;
use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Models\ReadingProgress;
use App\Domain\Progress\Services\PageProgressService;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Services\SystemLogService;
use Illuminate\Support\Facades\DB;

/**
 * Achievements are milestones computed from a student's tracked activity.
 * Each one names a metric and a threshold; when the metric reaches the
 * threshold the achievement unlocks and its points are added to the student.
 */
class AchievementService
{
    /** Metrics an achievement may be measured against. */
    public const METRICS = [
        'chapters_completed',
        'books_completed',
        'pages_read',
        'stories_read',
        'read_alouds_passed',
        'games_completed',
        'perfect_quizzes',
        'clear_reads',
        'badges_earned',
        'points',
    ];

    public function __construct(
        private AchievementRepository $repository,
        private SystemLogService $logs,
        private PageProgressService $pages,
    ) {}

    /**
     * The full catalog annotated with this student's progress toward each one.
     *
     * @return array<string, mixed>
     */
    public function forStudent(Student $student): array
    {
        $metrics = $this->metricsFor($student);
        $unlocked = $this->repository->unlockedFor($student);

        $items = $this->repository->active()->map(function (Achievement $achievement) use ($metrics, $unlocked) {
            $current = $metrics[$achievement->metric] ?? 0;
            $threshold = max(1, $achievement->threshold);
            $earned = $unlocked->get($achievement->id);

            return [
                'id' => $achievement->id,
                'code' => $achievement->code,
                'name' => $achievement->name,
                'description' => $achievement->description,
                'icon' => $achievement->icon,
                'metric' => $achievement->metric,
                'metric_label' => $this->metricLabel($achievement->metric),
                'threshold' => $threshold,
                'points' => $achievement->points,
                'current' => min($current, $threshold),
                'percent' => (int) min(100, round($current / $threshold * 100)),
                'is_unlocked' => $earned !== null,
                'unlocked_at' => $earned?->pivot?->unlocked_at,
            ];
        })->values()->all();

        return [
            'achievements' => $items,
            'unlocked' => collect($items)->where('is_unlocked', true)->count(),
            'total' => count($items),
            'metrics' => $metrics,
        ];
    }

    /**
     * Unlock any achievement whose threshold the student has now reached.
     * Idempotent, so it is safe to call after every scored activity.
     *
     * @return array<int, Achievement> the achievements unlocked by this call
     */
    public function sync(Student $student): array
    {
        $metrics = $this->metricsFor($student);
        $unlocked = $this->repository->unlockedFor($student);
        $newlyUnlocked = [];

        foreach ($this->repository->active() as $achievement) {
            if ($unlocked->has($achievement->id)) {
                continue;
            }

            $current = $metrics[$achievement->metric] ?? 0;
            if ($current < max(1, $achievement->threshold)) {
                continue;
            }

            DB::transaction(function () use ($student, $achievement) {
                $this->repository->unlock($student, $achievement, now());

                if ($achievement->points > 0) {
                    $student->increment('points', $achievement->points);
                }
            });

            $this->logs->record(
                'achievement.unlocked',
                "{$student->full_name} unlocked the achievement \"{$achievement->name}\".",
                $student,
            );

            $newlyUnlocked[] = $achievement;
        }

        return $newlyUnlocked;
    }

    /**
     * Current value of every tracked metric for a student.
     *
     * @return array<string, int>
     */
    public function metricsFor(Student $student): array
    {
        $progress = ReadingProgress::where('student_id', $student->id)->get();

        return [
            'chapters_completed' => $progress->where('status', 'completed')->count(),
            'books_completed' => $this->booksCompleted($student, $progress),
            'pages_read' => $this->pages->completedPageCount($student),
            'stories_read' => $progress->where('story_read', true)->count(),
            'read_alouds_passed' => $progress->where('pronunciation_passed', true)->count(),
            'games_completed' => $progress->where('game_completed', true)->count(),
            'perfect_quizzes' => $progress->where('quiz_score', 100)->count(),
            'clear_reads' => PronunciationAttempt::where('student_id', $student->id)
                ->where('pron_score', '>=', 90)
                ->count(),
            'badges_earned' => $student->badges()->count(),
            'points' => (int) $student->points,
        ];
    }

    /** How many of the student's assigned books have every chapter completed. */
    private function booksCompleted(Student $student, $progress): int
    {
        $completedChapterIds = $progress->where('status', 'completed')->pluck('chapter_id')->all();

        return $student->books()->with('chapters')->get()
            ->filter(function (Book $book) use ($completedChapterIds) {
                $chapters = $book->chapters;

                return $chapters->isNotEmpty()
                    && $chapters->every(
                        fn (Chapter $chapter) => in_array($chapter->id, $completedChapterIds, true)
                    );
            })
            ->count();
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'chapters_completed' => 'chapters finished',
            'books_completed' => 'books finished',
            'pages_read' => 'pages finished',
            'stories_read' => 'stories read',
            'read_alouds_passed' => 'read-alouds passed',
            'games_completed' => 'games played',
            'perfect_quizzes' => 'perfect quizzes',
            'clear_reads' => 'clear read-alouds (90%+)',
            'badges_earned' => 'badges earned',
            'points' => 'points',
            default => str_replace('_', ' ', $metric),
        };
    }
}
