<?php

namespace App\Domain\Teachers\Services;

use App\Domain\Teachers\Models\Teachers;
use App\Domain\Teachers\Repositories\TeachersRepository;
use Illuminate\Support\Facades\Hash;

class TeachersService
{
    public function __construct(private TeachersRepository $repository) {}

    public function register(array $data): Teachers
    {
        return $this->repository->create($data);
    }

    /**
     * Verify credentials and return the matching teacher, or null.
     */
    public function attempt(string $email, string $password): ?Teachers
    {
        $teacher = $this->repository->findByEmail($email);

        if (! $teacher || ! Hash::check($password, $teacher->password)) {
            return null;
        }

        return $teacher;
    }
}
