<?php

namespace App\Domain\SystemLog\Services;

use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Models\SystemLog;
use App\Domain\SystemLog\Repositories\SystemLogRepository;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail. Every call is best-effort: logging must never break
 * the action it is recording.
 */
class SystemLogService
{
    public function __construct(private SystemLogRepository $repository) {}

    /** Record an entry against a student, a teacher, or neither. */
    public function record(
        string $action,
        string $description,
        ?Student $student = null,
        ?Teachers $teacher = null,
    ): ?SystemLog {
        try {
            return $this->repository->create([
                'student_id' => $student?->id,
                'teacher_id' => $teacher?->id,
                'action' => $action,
                'description' => $description,
                'ip_address' => $this->clientIp(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record an entry for whoever is currently authenticated, picking the right
     * column based on the actor's type.
     */
    public function recordFor(
        Student|Teachers|null $actor,
        string $action,
        string $description,
        ?Student $student = null,
    ): ?SystemLog {
        if ($actor instanceof Teachers) {
            return $this->record($action, $description, $student, $actor);
        }

        if ($actor instanceof Student) {
            return $this->record($action, $description, $actor);
        }

        return $this->record($action, $description, $student);
    }

    /** @param array<string, mixed> $filters */
    public function forTeacher(int $teacherId, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->forTeacher($teacherId, $filters);
    }

    /** @return array<int, string> */
    public function actionsForTeacher(int $teacherId): array
    {
        return $this->repository->actionsForTeacher($teacherId);
    }

    private function clientIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null;
        }
    }
}
