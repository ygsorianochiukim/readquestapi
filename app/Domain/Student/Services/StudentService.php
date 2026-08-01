<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Models\Student;
use App\Domain\Student\Repositories\StudentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class StudentService
{
    public function __construct(private StudentRepository $repository) {}

    /**
     * Verify a student's username/password and return the matching student, or null.
     */
    public function attempt(string $username, string $password): ?Student
    {
        $student = $this->repository->findByUsername($username);

        if (! $student || ! Hash::check($password, $student->password)) {
            return null;
        }

        return $student;
    }

    /**
     * @return Collection<int, Student>
     */
    public function listForTeacher(int $teacherId): Collection
    {
        return $this->repository->forTeacher($teacherId);
    }

    public function create(array $data): Student
    {
        return $this->repository->create($data);
    }

    public function update(Student $student, array $data): Student
    {
        // Never overwrite the password with an empty value on update.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $this->repository->update($student, $data);
    }

    public function delete(Student $student): void
    {
        $this->repository->delete($student);
    }
}
