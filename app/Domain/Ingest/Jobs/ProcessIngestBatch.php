<?php

namespace App\Domain\Ingest\Jobs;

use App\Domain\Ingest\Models\IngestBatch;
use App\Domain\Ingest\Services\DocumentIngestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads an uploaded document in the background.
 *
 * A forty-page PDF is forty round trips to Azure at roughly a second each; no
 * teacher is going to sit on a spinning request for that long, and no PHP
 * timeout would let them. The batch row carries the progress so the upload
 * screen can show what is happening.
 */
class ProcessIngestBatch implements ShouldQueue
{
    use Queueable;

    /** Long, because the work is dominated by waiting on Azure. */
    public int $timeout = 1800;

    /**
     * One retry. OCR failures are already swallowed per page, so a job that
     * dies has hit something structural, and running it twice more will not
     * change that — but it will duplicate pages.
     */
    public int $tries = 2;

    // Takes the id, not the model: the batch is written to constantly while the
    // job runs, and a serialised copy would be stale the moment it is queued.
    public function __construct(public int $batchId) {}

    public function handle(DocumentIngestService $ingest): void
    {
        $batch = IngestBatch::find($this->batchId);

        // Gone (cancelled), or already being read by another run — the queue
        // worker and the in-request fallback race for it, and only one wins.
        if (! $batch || ! $batch->claim()) {
            return;
        }

        // Run in-request (no worker) this must outlive PHP's time limit and the
        // teacher closing the tab; under a worker both are no-ops.
        @set_time_limit(0);
        ignore_user_abort(true);

        $ingest->process($batch->refresh());
    }

    /** The teacher is watching this row; it must not just go quiet. */
    public function failed(Throwable $exception): void
    {
        IngestBatch::where('id', $this->batchId)->update([
            'status' => 'failed',
            'error' => mb_substr($exception->getMessage(), 0, 1000),
            'completed_at' => now(),
        ]);
    }
}
