<?php

namespace App\Domain\Pronunciation\Models;

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PronunciationAttempt extends Model
{
    protected $table = 'pronunciation_attempts';

    protected $fillable = [
        'student_id',
        'book_page_id',
        'chapter_id',
        'reference_text',
        'recognized_text',
        'audio_path',
        'accuracy_score',
        'fluency_score',
        'completeness_score',
        'pron_score',
        'text_match_score',
        'is_off_script',
        'is_validated',
        'validated_at',
    ];

    protected $casts = [
        'accuracy_score' => 'float',
        'fluency_score' => 'float',
        'completeness_score' => 'float',
        'pron_score' => 'float',
        'text_match_score' => 'float',
        'is_off_script' => 'boolean',
        'is_validated' => 'boolean',
        'validated_at' => 'datetime',
    ];

    protected $appends = ['audio_url'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function bookPage(): BelongsTo
    {
        return $this->belongsTo(BookPage::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function getAudioUrlAttribute(): ?string
    {
        return $this->audio_path
            ? Storage::disk('public')->url($this->audio_path)
            : null;
    }
}
