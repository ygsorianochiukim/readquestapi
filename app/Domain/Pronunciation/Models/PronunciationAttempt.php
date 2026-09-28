<?php

namespace App\Domain\Pronunciation\Models;

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Services\ProgressService;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class PronunciationAttempt extends Model
{
    protected $table = 'pronunciation_attempts';

    protected $fillable = [
        'student_id',
        'book_page_id',
        'chapter_id',
        'paragraph_index',
        'reference_text',
        'recognized_text',
        'audio_path',
        'accuracy_score',
        'fluency_score',
        'completeness_score',
        'prosody_score',
        'diction_score',
        'pron_score',
        'text_match_score',
        'is_off_script',
        'words_per_minute',
        'pace',
        'duration_ms',
        'teacher_score',
        'teacher_note',
        'is_validated',
        'validated_at',
    ];

    protected $casts = [
        'accuracy_score' => 'float',
        'fluency_score' => 'float',
        'completeness_score' => 'float',
        'prosody_score' => 'float',
        'diction_score' => 'float',
        'pron_score' => 'float',
        'text_match_score' => 'float',
        'teacher_score' => 'float',
        'is_off_script' => 'boolean',
        'is_validated' => 'boolean',
        'words_per_minute' => 'integer',
        'duration_ms' => 'integer',
        'validated_at' => 'datetime',
    ];

    protected $appends = ['audio_url', 'effective_score', 'passed'];

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

    /** @return HasMany<PronunciationWord, $this> */
    public function words(): HasMany
    {
        return $this->hasMany(PronunciationWord::class)->orderBy('word_index');
    }

    public function getAudioUrlAttribute(): ?string
    {
        return $this->audio_path
            ? Storage::disk('public')->url($this->audio_path)
            : null;
    }

    /**
     * The score that counts: a teacher's manual verdict when there is one,
     * otherwise what the machine decided. Everything that shows or gates on a
     * score reads this, so an override actually changes what the pupil sees.
     */
    public function getEffectiveScoreAttribute(): ?float
    {
        return $this->teacher_score ?? $this->pron_score;
    }

    /** Whether this attempt cleared the read-aloud bar. */
    public function getPassedAttribute(): bool
    {
        $score = $this->effective_score;

        return $score !== null && $score >= ProgressService::PRONUNCIATION_PASS;
    }

    /** The words the pupil got wrong, for reports and the repeat-after-me loop. */
    public function missedWords(): array
    {
        return $this->words
            ->filter(fn (PronunciationWord $word) => ! $word->isCorrect())
            ->values()
            ->all();
    }
}
