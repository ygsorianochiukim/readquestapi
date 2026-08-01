<?php

namespace App\Domain\Student\Repositories;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Collection;

class StudentRepository
{
    /**
     * @return Collection<int, Student>
     */
    public function forTeacher(int $teacherId): Collection
    {
        return Student::where('teacher_id', $teacherId)
            ->latest()
            ->get();
    }

    public function findByUsername(string $username): ?Student
    {
        return Student::where('username', $username)->first();
    }

    public function create(array $data): Student
    {
        return Student::create($data);
    }

    public function update(Student $student, array $data): Student
    {
        $student->update($data);

        return $student->refresh();
    }

    public function delete(Student $student): void
    {
        $student->delete();
    }
}
