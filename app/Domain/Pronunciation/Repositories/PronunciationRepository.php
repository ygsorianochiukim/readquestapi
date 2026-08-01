<?php

namespace App\Domain\Pronunciation\Repositories;

use App\Domain\Pronunciation\Models\PronunciationAttempt;
use Illuminate\Database\Eloquent\Collection;

class PronunciationRepository
{
    public function create(array $data): PronunciationAttempt
    {
        return PronunciationAttempt::create($data);
    }

    /**
     * @return Collection<int, PronunciationAttempt>
     */
    public function forStudent(int $studentId): Collection
    {
        return PronunciationAttempt::where('student_id', $studentId)
            ->latest()
            ->get();
    }

    /** Count of unvalidated attempts across all of a teacher's students. */
    public function pendingCountForTeacher(int $teacherId): int
    {
        return PronunciationAttempt::where('is_validated', false)
            ->whereHas('student', fn ($query) => $query->where('teacher_id', $teacherId))
            ->count();
    }

    public function markValidated(PronunciationAttempt $attempt): PronunciationAttempt
    {
        $attempt->update([
            'is_validated' => true,
            'validated_at' => now(),
        ]);

        return $attempt->refresh();
    }
}
