<?php

namespace App\Domain\Ingest\Models;

use App\Domain\Book\Models\Book;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One upload of reading material, from the moment a teacher drops a file in to
 * the moment they approve what came out of it.
 *
 * It exists so the teacher can watch a long PDF being read — "page 7 of 40" —
 * and so a run that dies halfway leaves something to look at and retry rather
 * than a half-filled book with no explanation.
 */
class IngestBatch extends Model
{
    protected $table = 'ingest_batches';

    protected $fillable = [
        'teacher_id',
        'book_id',
        'source_name',
        'source_path',
        'source_type',
        'status',
        'pages_total',
        'pages_done',
        'text_only',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'pages_total' => 'integer',
        'pages_done' => 'integer',
        'text_only' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected $appends = ['percent', 'is_finished'];

    /** Stages during which a run is (or should be) reading the file. */
    public const WORKING = ['starting', 'rasterizing', 'analyzing', 'reading'];

    /**
     * Claim the batch for one run. Only a queued batch can be claimed, and
     * only once, so the queue worker and the in-request fallback can never
     * both read the same file and fill the book twice.
     */
    public function claim(): bool
    {
        return static::whereKey($this->id)
            ->where('status', 'queued')
            ->update(['status' => 'starting', 'updated_at' => now()]) === 1;
    }

    /** Queued, but nothing has picked it up — no queue worker is running. */
    public function isWaitingTooLong(int $seconds): bool
    {
        return $this->status === 'queued' && $this->updated_at?->lt(now()->subSeconds($seconds));
    }

    /**
     * Stuck part-way: the run that was reading it has died (a restart, or a
     * PHP time limit) and nothing will ever move it on. Every stage writes its
     * progress, and no single call to Azure or OpenAI outlasts this.
     */
    public function hasStalled(int $minutes): bool
    {
        return in_array($this->status, self::WORKING, true)
            && $this->updated_at?->lt(now()->subMinutes($minutes));
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teachers::class, 'teacher_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** Roughly how long one page takes when a whole PDF is read in one call. */
    private const SECONDS_PER_PAGE = 4;

    /** Page count assumed when a text-only PDF's pages could not be counted. */
    private const ASSUMED_PAGES = 20;

    /** Roughly how long sorting out the story pages takes. */
    private const ANALYZING_SECONDS = 30;

    /**
     * How far along the read is. Reading the pages is 0–90 and sorting them
     * 90–99; only a finished upload shows 100, so a long last step never sits
     * on a "done" bar.
     *
     * A text-only PDF (no renderer on the host) is read in a single call with
     * nothing to count along the way, so its progress is estimated from the
     * time elapsed — otherwise it sits on 0% for minutes, and a frozen bar
     * looks broken.
     */
    public function getPercentAttribute(): int
    {
        if ($this->status === 'ready' || $this->status === 'committed') {
            return 100;
        }

        // Each stage writes the row as it starts, and nothing else does until
        // the next stage, so updated_at is when the current stage began.
        $elapsed = $this->updated_at ? max(0, $this->updated_at->diffInSeconds(now(), true)) : 0;

        if ($this->status === 'analyzing') {
            return 90 + (int) floor(9 * $this->easeOut($elapsed / self::ANALYZING_SECONDS));
        }

        if ($this->status === 'reading' && $this->text_only && $this->pages_done === 0) {
            $expected = max(20, ($this->pages_total ?: self::ASSUMED_PAGES) * self::SECONDS_PER_PAGE);

            return (int) floor(90 * $this->easeOut($elapsed / $expected));
        }

        if ($this->pages_total < 1) {
            return 0;
        }

        return (int) min(90, round($this->pages_done / $this->pages_total * 90));
    }

    /**
     * 0 → 1 for an estimate: on time at 1, then slowing so it keeps creeping
     * forward without ever reaching the end if the read runs long.
     */
    private function easeOut(float $ratio): float
    {
        return $ratio <= 1
            ? 0.85 * $ratio
            : 0.85 + 0.15 * (1 - exp(-($ratio - 1)));
    }

    public function getIsFinishedAttribute(): bool
    {
        return in_array($this->status, ['ready', 'committed', 'failed'], true);
    }

    /** A short line for the teacher, matching whichever stage this is at. */
    public function statusMessage(): string
    {
        return match ($this->status) {
            'queued' => 'Waiting to start…',
            'starting' => 'Starting…',
            'rasterizing' => 'Splitting the file into pages…',
            'analyzing' => 'Finding the chapters and leaving out covers, footers and exercises…',
            'reading' => $this->text_only && $this->pages_total > 0 && $this->pages_done === 0
                ? "Reading all {$this->pages_total} pages…"
                : ($this->pages_total > 0
                ? "Reading page {$this->pages_done} of {$this->pages_total}…"
                : 'Reading the pages…'),
            'ready' => 'Ready for you to check.',
            'committed' => 'Published.',
            'failed' => $this->error ?? 'Something went wrong.',
            default => $this->status,
        };
    }
}
