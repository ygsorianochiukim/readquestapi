<?php

namespace App\Domain\Progress\Repositories;

use App\Domain\Progress\Models\ReadingProgress;
use Illuminate\Database\Eloquent\Collection;

class ProgressRepository
{
    /** Get (or create) the progress row for a student + chapter. */
    public function firstOrCreateFor(int $studentId, int $chapterId): ReadingProgress
    {
        return ReadingProgress::firstOrCreate(
            ['student_id' => $studentId, 'chapter_id' => $chapterId],
        );
    }

    public function findFor(int $studentId, int $chapterId): ?ReadingProgress
    {
        return ReadingProgress::where('student_id', $studentId)
            ->where('chapter_id', $chapterId)
            ->first();
    }

    /**
     * All progress rows for a student, keyed by chapter_id.
     *
     * @return Collection<int, ReadingProgress>
     */
    public function forStudent(int $studentId): Collection
    {
        return ReadingProgress::where('student_id', $studentId)
            ->get()
            ->keyBy('chapter_id');
    }

    public function save(ReadingProgress $progress): ReadingProgress
    {
        $progress->save();

        return $progress;
    }
}
