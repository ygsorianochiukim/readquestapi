<?php

namespace App\Domain\Pronunciation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One word of a read-aloud attempt, as Azure scored it.
 *
 * Azure has always returned these — the assessment is asked for word
 * granularity — but only the aggregate scores used to be kept. Storing them is
 * what lets a teacher see *which* words a child struggles with and lets the
 * reader colour the page back in when an old attempt is reopened.
 */
class PronunciationWord extends Model
{
    /**
     * Below this accuracy a word counts as wrong even when Azure raised no
     * error on it — the same bar the reader colours words red by.
     */
    public const CORRECT_ACCURACY = 60;

    protected $table = 'pronunciation_words';

    protected $fillable = [
        'pronunciation_attempt_id',
        'word_index',
        'word',
        'accuracy_score',
        'error_type',
        'offset_ms',
        'duration_ms',
        'retry_accuracy',
        'retried_at',
    ];

    protected $casts = [
        'word_index' => 'integer',
        'accuracy_score' => 'float',
        'offset_ms' => 'integer',
        'duration_ms' => 'integer',
        'retry_accuracy' => 'float',
        'retried_at' => 'datetime',
    ];

    protected $appends = ['is_corrected'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PronunciationAttempt::class, 'pronunciation_attempt_id');
    }

    /** Whether this word was read acceptably. Omissions and insertions are not. */
    public function isCorrect(): bool
    {
        return $this->error_type === 'None';
    }

    /**
     * Whether the pupil got this word right on a second go after the reading.
     * It stays a miss in the reading itself — the retry shows it was fixed.
     */
    public function getIsCorrectedAttribute(): bool
    {
        return $this->retry_accuracy !== null
            && $this->retry_accuracy >= self::CORRECT_ACCURACY;
    }
}
