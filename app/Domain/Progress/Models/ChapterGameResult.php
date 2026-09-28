<?php

namespace App\Domain\Progress\Models;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The first win of one mini-game type on one chapter, and the points it paid.
 */
class ChapterGameResult extends Model
{
    /** The mini-games a chapter offers. */
    public const TYPES = ['scramble', 'missing-word', 'sentence-builder'];

    protected $table = 'chapter_game_results';

    protected $fillable = [
        'student_id',
        'chapter_id',
        'game_type',
        'points_awarded',
        'perfect',
        'completed_at',
    ];

    protected $casts = [
        'points_awarded' => 'integer',
        'perfect' => 'boolean',
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
}
