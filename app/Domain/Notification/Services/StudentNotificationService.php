<?php

namespace App\Domain\Notification\Services;

use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Student\Models\Student;
use Illuminate\Support\Carbon;

/**
 * What a child is told when they open their notifications: the badges they
 * were given and what their teacher said about their reading.
 *
 * Built from the awards and reviews themselves rather than stored copies, so a
 * badge taken back or a note edited is never shown out of date.
 */
class StudentNotificationService
{
    private const LIMIT = 30;

    /**
     * @return array{data: list<array<string, mixed>>, unread: int}
     */
    public function forStudent(Student $student): array
    {
        $teacher = $student->teacher?->full_name ?? 'Your teacher';
        $readAt = $student->notifications_read_at;

        $items = collect()
            ->concat($this->badges($student, $teacher))
            ->concat($this->reviews($student, $teacher))
            ->filter(fn (array $item) => $item['created_at'] !== null)
            ->sortByDesc(fn (array $item) => $item['created_at']->getTimestamp())
            ->take(self::LIMIT)
            ->map(fn (array $item) => [
                ...$item,
                'unread' => $readAt === null || $item['created_at']->gt($readAt),
                'created_at' => $item['created_at']->toIso8601String(),
            ])
            ->values();

        return [
            'data' => $items->all(),
            'unread' => $items->where('unread', true)->count(),
        ];
    }

    public function markRead(Student $student): void
    {
        $student->forceFill(['notifications_read_at' => now()])->save();
    }

    /** @return list<array<string, mixed>> */
    private function badges(Student $student, string $teacher): array
    {
        return $student->badges()->get()->map(function ($badge) use ($teacher) {
            $fromTeacher = $badge->pivot->awarded_by !== null;

            return [
                'id' => "badge-{$badge->id}",
                'type' => 'badge',
                'title' => $fromTeacher ? "{$teacher} gave you a badge!" : 'You earned a badge!',
                'message' => trim("{$badge->name}".($badge->points ? " · +{$badge->points} points" : '')),
                'icon' => $badge->icon,
                'score' => null,
                'passed' => null,
                'created_at' => $this->time($badge->pivot->earned_at ?? $badge->pivot->created_at),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function reviews(Student $student, string $teacher): array
    {
        return PronunciationAttempt::with(['chapter:id,chapter_number,title', 'bookPage:id,page_number'])
            ->where('student_id', $student->id)
            ->where('is_validated', true)
            ->whereNotNull('validated_at')
            ->latest('validated_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (PronunciationAttempt $attempt) use ($teacher) {
                $what = $this->readingName($attempt);
                $score = $attempt->effective_score === null ? null : (int) round($attempt->effective_score);
                $note = trim((string) $attempt->teacher_note);

                $title = match (true) {
                    $note !== '' => "{$teacher} left a note on your reading",
                    $attempt->teacher_score !== null => "{$teacher} scored your reading",
                    default => "{$teacher} checked your reading",
                };

                return [
                    'id' => "review-{$attempt->id}",
                    'type' => 'review',
                    'title' => $title,
                    'message' => $note !== ''
                        ? "{$what}: “{$note}”"
                        : $what.($score !== null ? " — {$score}%" : ''),
                    'icon' => null,
                    'score' => $score,
                    'passed' => $attempt->passed,
                    'created_at' => $this->time($attempt->validated_at),
                ];
            })
            ->all();
    }

    private function readingName(PronunciationAttempt $attempt): string
    {
        if ($attempt->chapter) {
            $chapter = "Chapter {$attempt->chapter->chapter_number}";
            $title = trim((string) $attempt->chapter->title);
            $name = $title !== '' && strcasecmp($title, $chapter) !== 0 ? "{$chapter}: {$title}" : $chapter;

            return $attempt->bookPage ? "{$name}, page {$attempt->bookPage->page_number}" : $name;
        }

        return $attempt->bookPage ? "Page {$attempt->bookPage->page_number}" : 'Your reading';
    }

    private function time(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
