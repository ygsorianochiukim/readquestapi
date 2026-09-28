<?php

namespace App\Domain\Celebration;

use App\Domain\Achievement\Models\Achievement;
use App\Domain\Badge\Models\Badge;

/**
 * Everything worth celebrating that happened during this request.
 *
 * A badge is earned deep inside RewardService, three or four calls below the
 * controller that has to tell the child about it. Threading a return value back
 * up through progress, pronunciation and achievement services would touch every
 * signature in the chain and be dropped by the first caller that forgot. So the
 * services push into one bag, bound as a singleton for the life of the request,
 * and the controller empties it on the way out.
 *
 * Nothing here is persisted: a badge the child was already shown must not be
 * shown again on the next request, and the bag dying with the request is what
 * guarantees that.
 */
class CelebrationBag
{
    /** @var array<int, Badge> */
    private array $badges = [];

    /** @var array<int, Achievement> */
    private array $achievements = [];

    /** Set when the activity just finished something bigger than itself. */
    private ?string $milestone = null;

    public function badgeEarned(Badge $badge): void
    {
        // The same badge can be reached twice in one request (a read-aloud that
        // both passes a chapter and finishes the book); show it once.
        $this->badges[$badge->id] = $badge;
    }

    /** @param  array<int, Achievement>  $achievements */
    public function achievementsUnlocked(array $achievements): void
    {
        foreach ($achievements as $achievement) {
            $this->achievements[$achievement->id] = $achievement;
        }
    }

    /** `chapter_completed`, `book_completed`, `page_completed` — the loudest one wins. */
    public function milestone(string $milestone): void
    {
        $rank = ['page_completed' => 1, 'chapter_completed' => 2, 'book_completed' => 3];

        if ($this->milestone === null || ($rank[$milestone] ?? 0) > ($rank[$this->milestone] ?? 0)) {
            $this->milestone = $milestone;
        }
    }

    public function isEmpty(): bool
    {
        return $this->badges === [] && $this->achievements === [] && $this->milestone === null;
    }

    /**
     * What to hand the client, ready to drive the celebration modal.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'badges' => array_values(array_map(fn (Badge $badge) => [
                'id' => $badge->id,
                'name' => $badge->name,
                'description' => $badge->description,
                'icon' => $badge->icon,
                'points' => $badge->points,
            ], $this->badges)),
            'achievements' => array_values(array_map(fn (Achievement $achievement) => [
                'id' => $achievement->id,
                'code' => $achievement->code,
                'name' => $achievement->name,
                'description' => $achievement->description,
                'icon' => $achievement->icon,
                'points' => $achievement->points,
            ], $this->achievements)),
            'milestone' => $this->milestone,
        ];
    }
}
