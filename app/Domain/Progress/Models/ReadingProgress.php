<?php

namespace App\Domain\Progress\Models;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $fillable = [
        'student_id',
        'chapter_id',
        'status',
        'is_unlocked',
        'story_read',
        'pronunciation_passed',
        'game_completed',
        'quiz_passed',
        'quiz_score',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'is_unlocked' => 'boolean',
        'story_read' => 'boolean',
        'pronunciation_passed' => 'boolean',
        'game_completed' => 'boolean',
        'quiz_passed' => 'boolean',
        'quiz_score' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** All four required activities are done. */
    public function activitiesComplete(): bool
    {
        return $this->story_read
            && $this->pronunciation_passed
            && $this->game_completed
            && $this->quiz_passed;
    }
}
