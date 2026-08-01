<?php

namespace App\Domain\Badge\Services;

use App\Domain\Badge\Models\Badge;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RewardService
{
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
     */
    public function award(Student $student, Badge $badge): void
    {
        if ($student->badges()->where('badges.id', $badge->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($student, $badge) {
            $student->badges()->attach($badge->id, ['earned_at' => now()]);
            $student->increment('points', $badge->points);
        });
    }

    /**
     * Remove a badge from a student and subtract its points (never below zero).
     */
    public function revoke(Student $student, Badge $badge): void
    {
        if (! $student->badges()->where('badges.id', $badge->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($student, $badge) {
            $student->badges()->detach($badge->id);
            $student->points = max(0, $student->points - $badge->points);
            $student->save();
        });
    }
}
