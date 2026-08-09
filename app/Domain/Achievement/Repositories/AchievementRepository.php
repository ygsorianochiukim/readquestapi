<?php

namespace App\Domain\Achievement\Repositories;

use App\Domain\Achievement\Models\Achievement;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class AchievementRepository
{
    /**
     * The active achievement catalog, in display order.
     *
     * @return Collection<int, Achievement>
     */
    public function active(): Collection
    {
        return Achievement::where('status', 'active')
            ->orderBy('sequence')
            ->orderBy('threshold')
            ->get();
    }

    /**
     * @return Collection<int, Achievement>
     */
    public function all(): Collection
    {
        return Achievement::orderBy('sequence')->orderBy('threshold')->get();
    }

    /**
     * Achievements a student has already unlocked, keyed by achievement id.
     *
     * @return SupportCollection<int, Achievement>
     */
    public function unlockedFor(Student $student): SupportCollection
    {
        return $student->achievements()->get()->keyBy('id');
    }

    public function unlock(Student $student, Achievement $achievement, $unlockedAt): void
    {
        $student->achievements()->syncWithoutDetaching([
            $achievement->id => ['unlocked_at' => $unlockedAt],
        ]);
    }
}
