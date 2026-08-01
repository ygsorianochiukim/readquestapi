<?php

namespace App\Domain\Badge\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    protected $table = 'badges';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'criteria',
        'points',
        'status',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_badges')
            ->withPivot('earned_at')
            ->withTimestamps();
    }
}
