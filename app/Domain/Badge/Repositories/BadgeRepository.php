<?php

namespace App\Domain\Badge\Repositories;

use App\Domain\Badge\Models\Badge;
use Illuminate\Database\Eloquent\Collection;

class BadgeRepository
{
    /**
     * @return Collection<int, Badge>
     */
    public function all(): Collection
    {
        return Badge::orderBy('name')->get();
    }

    public function create(array $data): Badge
    {
        return Badge::create($data);
    }

    public function update(Badge $badge, array $data): Badge
    {
        $badge->update($data);

        return $badge->refresh();
    }

    public function delete(Badge $badge): void
    {
        $badge->delete();
    }
}
