<?php

namespace App\Domain\Achievement\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A milestone a student works toward (e.g. "Finish 5 chapters"). Unlike a badge,
 * which a teacher can hand out, an achievement is earned purely from tracked
 * progress and always shows how far along the student is.
 */
class Achievement extends Model
{
    protected $table = 'achievements';

    protected $fillable = [
        'code',
        'name',
        'description',
        'icon',
        'metric',
        'threshold',
        'points',
        'sequence',
        'status',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'points' => 'integer',
        'sequence' => 'integer',
    ];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_achievements')
            ->withPivot('unlocked_at')
            ->withTimestamps();
    }
}
