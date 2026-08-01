<?php

namespace App\Domain\Badge\Services;

use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Repositories\BadgeRepository;
use Illuminate\Database\Eloquent\Collection;

class BadgeService
{
    public function __construct(private BadgeRepository $repository) {}

    /**
     * @return Collection<int, Badge>
     */
    public function list(): Collection
    {
        return $this->repository->all();
    }

    public function create(array $data): Badge
    {
        return $this->repository->create($data);
    }

    public function update(Badge $badge, array $data): Badge
    {
        return $this->repository->update($badge, $data);
    }

    public function delete(Badge $badge): void
    {
        $this->repository->delete($badge);
    }
}
