<?php

namespace App\Domain\Teachers\Repositories;

use App\Domain\Teachers\Models\Teachers;

class TeachersRepository
{
    public function create(array $data): Teachers
    {
        return Teachers::create($data);
    }

    public function findByEmail(string $email): ?Teachers
    {
        return Teachers::where('email', $email)->first();
    }

    public function update(Teachers $teacher, array $data): Teachers
    {
        $teacher->update($data);

        return $teacher->refresh();
    }
}
