<?php

namespace App\Domain\Student\Models;

use App\Domain\Achievement\Models\Achievement;
use App\Domain\Badge\Models\Badge;
use App\Domain\Book\Models\Book;
use App\Domain\Progress\Models\ReadingProgress;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Student extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'students';

    /** Seen within this many minutes counts as using the app right now. */
    public const ONLINE_MINUTES = 2;

    protected $appends = ['is_online', 'is_present_today'];

    protected $fillable = [
        'teacher_id',
        'first_name',
        'last_name',
        'username',
        'password',
        'reading_level',
        'status',
        'profile_image_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'points' => 'integer',
            'notifications_read_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teachers::class, 'teacher_id');
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'student_badges')
            ->withPivot('earned_at', 'awarded_by')
            ->withTimestamps();
    }

    /** Milestones this student has unlocked from their own activity. */
    public function achievements(): BelongsToMany
    {
        return $this->belongsToMany(Achievement::class, 'student_achievements')
            ->withPivot('unlocked_at')
            ->withTimestamps();
    }

    /** Books this student has been assigned by their teacher. */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_assignments')
            ->withPivot('assigned_at')
            ->withTimestamps()
            ->orderBy('sequence');
    }

    /** Per-chapter reading progress records. */
    public function progress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Using the app right now. */
    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_MINUTES));
    }

    /** Has opened the app today — present, for a teacher taking the register. */
    public function getIsPresentTodayAttribute(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->isToday();
    }

    /**
     * Note that the student is here. Written at most once a minute, and without
     * touching updated_at, so a child reading does not look like an edit.
     */
    public function markSeen(): void
    {
        if ($this->last_seen_at?->gt(now()->subMinute())) {
            return;
        }

        $this->last_seen_at = now();
        static::whereKey($this->id)->toBase()->update(['last_seen_at' => $this->last_seen_at]);
    }
}
