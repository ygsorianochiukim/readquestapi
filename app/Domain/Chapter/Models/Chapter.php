<?php

namespace App\Domain\Chapter\Models;

use App\Domain\Book\Models\Book;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapter extends Model
{
    protected $table = 'chapters';

    protected $fillable = [
        'book_id',
        'chapter_number',
        'title',
        'story_text',
        'image_url',
        'audio_url',
    ];

    protected $casts = [
        'chapter_number' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function quizQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }
}
