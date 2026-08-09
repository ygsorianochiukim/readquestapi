<?php

namespace App\Domain\SystemLog\Repositories;

use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Models\SystemLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SystemLogRepository
{
    public function create(array $data): SystemLog
    {
        return SystemLog::create($data);
    }

    /**
     * Entries a teacher is allowed to see: their own actions plus anything
     * their students did.
     *
     * @param  array<string, mixed>  $filters  action, student_id, per_page
     */
    public function forTeacher(int $teacherId, array $filters = []): LengthAwarePaginator
    {
        $studentIds = Student::where('teacher_id', $teacherId)->pluck('id');

        $query = SystemLog::with(['student', 'teacher'])
            ->where(function ($scope) use ($teacherId, $studentIds) {
                $scope->where('teacher_id', $teacherId)
                    ->orWhereIn('student_id', $studentIds);
            });

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }

        if (! empty($filters['search'])) {
            $query->where('description', 'like', '%'.$filters['search'].'%');
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 25);
    }

    /**
     * The distinct action names present in a teacher's visible log (for filters).
     *
     * @return array<int, string>
     */
    public function actionsForTeacher(int $teacherId): array
    {
        $studentIds = Student::where('teacher_id', $teacherId)->pluck('id');

        return SystemLog::where(function ($scope) use ($teacherId, $studentIds) {
            $scope->where('teacher_id', $teacherId)
                ->orWhereIn('student_id', $studentIds);
        })
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }
}
