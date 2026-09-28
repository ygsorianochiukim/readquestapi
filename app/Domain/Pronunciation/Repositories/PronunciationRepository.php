<?php

namespace App\Domain\Pronunciation\Repositories;

use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Models\PronunciationWord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PronunciationRepository
{
    public function create(array $data): PronunciationAttempt
    {
        return PronunciationAttempt::create($data);
    }

    /**
     * Store the per-word verdicts for an attempt.
     *
     * Inserted in one statement rather than a model per word: a page of a
     * picture book is a handful of words, but a chapter is several hundred, and
     * that is several hundred round trips on every read-aloud.
     *
     * @param  list<array<string, mixed>>  $words
     */
    public function saveWords(PronunciationAttempt $attempt, array $words): void
    {
        if ($words === []) {
            return;
        }

        $now = now();

        $rows = [];
        foreach ($words as $index => $word) {
            $rows[] = [
                'pronunciation_attempt_id' => $attempt->id,
                'word_index' => $index,
                // The column is a plain string; a stray very long "word" from a
                // bad recognition must not fail the whole insert.
                'word' => mb_substr((string) $word['word'], 0, 255),
                'accuracy_score' => $word['accuracy_score'] ?? null,
                'error_type' => $word['error_type'] ?? 'None',
                'offset_ms' => $word['offset_ms'] ?? null,
                'duration_ms' => $word['duration_ms'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PronunciationWord::insert($chunk);
        }
    }

    /**
     * @return Collection<int, PronunciationAttempt>
     */
    public function forStudent(int $studentId): Collection
    {
        return PronunciationAttempt::with('words')
            ->where('student_id', $studentId)
            ->latest()
            ->get();
    }

    /**
     * A teacher's review queue: every attempt by their students, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PronunciationAttempt>
     */
    public function reviewQueue(int $teacherId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = PronunciationAttempt::with(['student:id,first_name,last_name', 'chapter:id,book_id,chapter_number,title', 'bookPage:id,book_id,page_number'])
            ->whereHas('student', fn ($students) => $students->where('teacher_id', $teacherId));

        if (! empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }

        // "Pending" means nobody has looked at it yet — neither confirmed nor
        // overridden.
        if (($filters['status'] ?? null) === 'pending') {
            $query->where('is_validated', false)->whereNull('teacher_score');
        } elseif (($filters['status'] ?? null) === 'reviewed') {
            $query->where(fn ($reviewed) => $reviewed->where('is_validated', true)->orWhereNotNull('teacher_score'));
        }

        if (($filters['only_failed'] ?? false)) {
            $query->whereRaw('COALESCE(teacher_score, pron_score) < ?', [60]);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->latest()->paginate($perPage);
    }

    /** Count of unvalidated attempts across all of a teacher's students. */
    public function pendingCountForTeacher(int $teacherId): int
    {
        return PronunciationAttempt::where('is_validated', false)
            ->whereNull('teacher_score')
            ->whereHas('student', fn ($query) => $query->where('teacher_id', $teacherId))
            ->count();
    }

    /**
     * The words a pupil gets wrong most often, worst first.
     *
     * Lower-cased before grouping so "Fox" and "fox" are one entry, and
     * omissions count: a word skipped every time is a word the child cannot
     * read, which is exactly what the teacher is looking for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostMissedWords(int $studentId, int $limit = 20): array
    {
        return PronunciationWord::query()
            ->join('pronunciation_attempts', 'pronunciation_attempts.id', '=', 'pronunciation_words.pronunciation_attempt_id')
            ->where('pronunciation_attempts.student_id', $studentId)
            ->where('pronunciation_words.error_type', '!=', 'None')
            ->groupBy(DB::raw('LOWER(pronunciation_words.word)'))
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->get([
                DB::raw('LOWER(pronunciation_words.word) as word'),
                DB::raw('COUNT(*) as times_missed'),
                DB::raw('AVG(pronunciation_words.accuracy_score) as average_accuracy'),
                DB::raw('MAX(pronunciation_attempts.created_at) as last_missed_at'),
            ])
            ->map(fn ($row) => [
                'word' => $row->word,
                'times_missed' => (int) $row->times_missed,
                'average_accuracy' => $row->average_accuracy === null ? null : round((float) $row->average_accuracy, 1),
                'last_missed_at' => $row->last_missed_at,
            ])
            ->all();
    }

    /** Record a pupil's second go at one word. The latest retry wins. */
    public function recordWordRetry(PronunciationWord $word, float $accuracy): PronunciationWord
    {
        $word->update([
            'retry_accuracy' => $accuracy,
            'retried_at' => now(),
        ]);

        return $word->refresh();
    }

    public function markValidated(PronunciationAttempt $attempt): PronunciationAttempt
    {
        $attempt->update([
            'is_validated' => true,
            'validated_at' => now(),
        ]);

        return $attempt->refresh();
    }

    /** Record a teacher's own verdict on an attempt. A null score clears it. */
    public function applyTeacherScore(
        PronunciationAttempt $attempt,
        ?float $score,
        ?string $note,
    ): PronunciationAttempt {
        $attempt->update([
            'teacher_score' => $score,
            'teacher_note' => $note,
            // Setting a score by hand *is* reviewing it.
            'is_validated' => true,
            'validated_at' => now(),
        ]);

        return $attempt->refresh();
    }
}
