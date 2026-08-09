<?php

namespace App\Domain\Badge\Services;

use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Badge\Models\Badge;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public function __construct(
        private AchievementService $achievements,
        private SystemLogService $logs,
    ) {}

    /**
     * Badges a student has earned (with the earned_at pivot).
     *
     * @return Collection<int, Badge>
     */
    public function forStudent(Student $student): Collection
    {
        return $student->badges()->orderBy('name')->get();
    }

    /**
     * Award a badge to a student and add its points. No-op if already earned.
     *
     * @param  Teachers|null  $by  the teacher who awarded it, when done by hand
     */
    public function award(Student $student, Badge $badge, ?Teachers $by = null): void
    {
        if ($student->badges()->where('badges.id', $badge->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($student, $badge) {
            $student->badges()->attach($badge->id, ['earned_at' => now()]);
            $student->increment('points', $badge->points);
        });

        $this->logs->record(
            'badge.awarded',
            $by
                ? "{$by->full_name} awarded the \"{$badge->name}\" badge to {$student->full_name}."
                : "{$student->full_name} earned the \"{$badge->name}\" badge.",
            $student,
            $by,
        );

        // Earning a badge can complete a badge-count milestone.
        $this->achievements->sync($student->refresh());
    }

    /**
     * Remove a badge from a student and subtract its points (never below zero).
     */
    public function revoke(Student $student, Badge $badge, ?Teachers $by = null): void
    {
        if (! $student->badges()->where('badges.id', $badge->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($student, $badge) {
            $student->badges()->detach($badge->id);
            $student->points = max(0, $student->points - $badge->points);
            $student->save();
        });

        $this->logs->record(
            'badge.revoked',
            $by
                ? "{$by->full_name} revoked the \"{$badge->name}\" badge from {$student->full_name}."
                : "The \"{$badge->name}\" badge was revoked from {$student->full_name}.",
            $student,
            $by,
        );
    }
}
