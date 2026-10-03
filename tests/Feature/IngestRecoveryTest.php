<?php

use App\Domain\Ingest\Jobs\ProcessIngestBatch;
use App\Domain\Ingest\Models\IngestBatch;
use Illuminate\Support\Facades\Queue;

/**
 * An upload must never sit on "Working…" for ever. With no queue worker it is
 * read in-request; a run that died part-way becomes a failure the teacher can
 * see and retry.
 */
function queuedUpload($test, $teacher): IngestBatch
{
    // No worker: the job is queued and nothing runs it. The real queue is
    // back afterwards, so whatever the API runs itself really runs.
    $queue = app('queue');
    Queue::fake();
    uploadPages($test, $teacher, ['The cat sat on the mat.'])->assertCreated();
    Queue::swap($queue);

    return IngestBatch::latest('id')->firstOrFail();
}

it('reads an upload itself when no queue worker picks it up', function () {
    $teacher = makeTeacher();
    $batch = queuedUpload($this, $teacher);

    expect($batch->status)->toBe('queued');

    // Polled too soon: still the worker's to take.
    $this->withHeaders(teacherHeaders($teacher))->getJson("/api/v1/ingest/{$batch->id}")->assertOk();
    expect($batch->refresh()->status)->toBe('queued');

    $this->travel(20)->seconds();
    $this->withHeaders(teacherHeaders($teacher))->getJson("/api/v1/ingest/{$batch->id}")->assertOk();

    expect($batch->refresh()->status)->toBe('ready')
        ->and($batch->book->pages()->count())->toBe(1);
});

it('never reads the same upload twice', function () {
    $teacher = makeTeacher();
    $batch = queuedUpload($this, $teacher);

    expect($batch->claim())->toBeTrue()
        ->and($batch->claim())->toBeFalse();

    // A late worker finds it taken and leaves it alone.
    (new ProcessIngestBatch($batch->id))->handle(app(\App\Domain\Ingest\Services\DocumentIngestService::class));
    expect($batch->refresh()->status)->toBe('starting')
        ->and($batch->book->pages()->count())->toBe(0);
});

it('turns a run that died part-way into a failure, and retries it from the kept file', function () {
    $teacher = makeTeacher();
    $batch = queuedUpload($this, $teacher);
    $batch->update(['status' => 'reading', 'pages_total' => 1]);

    $this->travel(20)->minutes();

    $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/ingest')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.is_finished', true);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$batch->id}/retry")
        ->assertOk();

    // Read again from the file kept on the server — no second upload.
    expect($batch->refresh()->status)->toBe('ready')
        ->and($batch->error)->toBeNull()
        ->and($batch->book->pages()->count())->toBe(1);
});

it('only retries an upload that failed', function () {
    $teacher = makeTeacher();
    $batch = queuedUpload($this, $teacher);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$batch->id}/retry")
        ->assertStatus(422);
});
