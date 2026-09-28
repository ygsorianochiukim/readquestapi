<?php

namespace App\Domain\Pronunciation\Services;

use App\Domain\Progress\Services\ProgressService;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Repositories\PronunciationRepository;
use App\Domain\Student\Models\Student;
use Illuminate\Support\Collection;

/**
 * The individual reading report a teacher reads: how a child is doing at
 * reading aloud, and — the part teachers actually ask for — which words they
 * keep getting wrong.
 */
class ReadingReportService
{
    public function __construct(
        private PronunciationRepository $repository,
        private ReadingPaceService $pace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(Student $student): array
    {
        $attempts = $this->repository->forStudent($student->id);

        return [
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'reading_level' => $student->reading_level,
            ],
            'summary' => $this->summary($attempts),
            'trend' => $this->trend($attempts),
            'missed_words' => $this->repository->mostMissedWords($student->id),
            'attempts' => $attempts->map(fn (PronunciationAttempt $attempt) => $this->presentAttempt($attempt))->all(),
            'pace_band' => $this->paceBand($this->pace->band($student->reading_level)),
        ];
    }

    /**
     * Headline numbers.
     *
     * Averages are taken over the *effective* score, so a teacher who has
     * corrected a bad automatic score sees their own correction reflected here
     * rather than the machine's original.
     *
     * @param  Collection<int, PronunciationAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function summary(Collection $attempts): array
    {
        $scored = $attempts->filter(fn (PronunciationAttempt $attempt) => $attempt->effective_score !== null);

        return [
            'total_attempts' => $attempts->count(),
            'passed_attempts' => $attempts->filter(fn (PronunciationAttempt $attempt) => $attempt->passed)->count(),
            'average_score' => $scored->isEmpty() ? null : round($scored->avg(fn ($attempt) => $attempt->effective_score), 1),
            'best_score' => $scored->isEmpty() ? null : round($scored->max(fn ($attempt) => $attempt->effective_score), 1),
            'average_accuracy' => $this->averageOf($attempts, 'accuracy_score'),
            'average_fluency' => $this->averageOf($attempts, 'fluency_score'),
            'average_completeness' => $this->averageOf($attempts, 'completeness_score'),
            'average_prosody' => $this->averageOf($attempts, 'prosody_score'),
            'average_diction' => $this->averageOf($attempts, 'diction_score'),
            'average_wpm' => $this->averageOf($attempts, 'words_per_minute'),
            // How often the child reads at a sensible speed, which is a
            // different question from how well they pronounce.
            'pace_counts' => [
                'too_slow' => $attempts->where('pace', 'too_slow')->count(),
                'good' => $attempts->where('pace', 'good')->count(),
                'too_fast' => $attempts->where('pace', 'too_fast')->count(),
            ],
            'awaiting_review' => $attempts->filter(
                fn (PronunciationAttempt $attempt) => ! $attempt->is_validated && $attempt->teacher_score === null
            )->count(),
            'pass_mark' => ProgressService::PRONUNCIATION_PASS,
        ];
    }

    /**
     * Scores oldest-first, for the progress line on the report. Capped so a
     * prolific reader's chart stays readable.
     *
     * @param  Collection<int, PronunciationAttempt>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function trend(Collection $attempts): array
    {
        return $attempts
            ->filter(fn (PronunciationAttempt $attempt) => $attempt->effective_score !== null)
            ->sortBy('created_at')
            ->take(-30)
            ->map(fn (PronunciationAttempt $attempt) => [
                'at' => $attempt->created_at?->toDateTimeString(),
                'score' => round($attempt->effective_score, 1),
                'accuracy' => $attempt->accuracy_score,
                'fluency' => $attempt->fluency_score,
                'diction' => $attempt->diction_score,
                'words_per_minute' => $attempt->words_per_minute,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentAttempt(PronunciationAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'created_at' => $attempt->created_at?->toDateTimeString(),
            'chapter_id' => $attempt->chapter_id,
            'book_page_id' => $attempt->book_page_id,
            'score' => $attempt->effective_score,
            'auto_score' => $attempt->pron_score,
            'teacher_score' => $attempt->teacher_score,
            'teacher_note' => $attempt->teacher_note,
            'accuracy_score' => $attempt->accuracy_score,
            'fluency_score' => $attempt->fluency_score,
            'completeness_score' => $attempt->completeness_score,
            'prosody_score' => $attempt->prosody_score,
            'diction_score' => $attempt->diction_score,
            'words_per_minute' => $attempt->words_per_minute,
            'pace' => $attempt->pace,
            'is_off_script' => $attempt->is_off_script,
            'is_validated' => $attempt->is_validated,
            'passed' => $attempt->passed,
            'audio_url' => $attempt->audio_url,
            'missed_word_count' => count($attempt->missedWords()),
        ];
    }

    /**
     * @param  Collection<int, PronunciationAttempt>  $attempts
     */
    private function averageOf(Collection $attempts, string $field): ?float
    {
        $values = $attempts->pluck($field)->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : round($values->avg(), 1);
    }

    /** @param  array{int, int}  $band */
    private function paceBand(array $band): array
    {
        return ['min_wpm' => $band[0], 'max_wpm' => $band[1]];
    }
}
