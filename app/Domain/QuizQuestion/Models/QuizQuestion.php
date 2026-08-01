<?php

namespace App\Domain\QuizQuestion\Models;

use App\Domain\Chapter\Models\Chapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $table = 'quiz_questions';

    protected $fillable = [
        'chapter_id',
        'question_text',
        'choices',
        'correct_answer',
    ];

    protected $casts = [
        'choices' => 'array',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
