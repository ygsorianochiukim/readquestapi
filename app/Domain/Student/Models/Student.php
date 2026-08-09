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
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teachers::class, 'teacher_id');
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'student_badges')
            ->withPivot('earned_at')
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
}
