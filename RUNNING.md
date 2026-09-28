# Running ReadQuest API

Notes that are easy to miss and cost an afternoon when they are.

## Azure keys

Both features that make the app what it is talk to Azure. Without them the API
answers `503` with a message saying so, rather than failing quietly.

```
AZURE_SPEECH_KEY=…
AZURE_SPEECH_REGION=…          # e.g. southeastasia
AZURE_SPEECH_VOICE=en-US-JennyNeural

AZURE_VISION_KEY=…
AZURE_VISION_ENDPOINT=https://<resource>.cognitiveservices.azure.com
```

One Speech resource covers three things: narration (text to speech), the
authoritative read-aloud score, and the short-lived tokens the browser uses for
**live**, word-by-word assessment. The key never leaves the server —
`POST /api/v1/speech/token` mints a ten-minute token instead.

## The queue worker is not optional

Uploading reading material (`POST /api/v1/ingest`) queues `ProcessIngestBatch`,
which rasterizes the file and sends every page to Azure Vision. A forty-page PDF
is forty round trips; it cannot run inside a request.

```
php artisan queue:work
```

`QUEUE_CONNECTION=database` is already set, and the `jobs` table already exists.
**Without a worker running, uploads sit at "Waiting to start…" forever** and the
teacher gets no error, because nothing has failed — nothing has run.

In production, run it under a supervisor (`supervisord`, a systemd unit, or
`php artisan queue:listen` behind a process manager).

## Turning PDFs into page pictures

Children read the page in front of them, so a scanned book has to keep its
illustrations. `PdfRasterizer` uses whichever of these the host has, in order:

1. the PHP **imagick** extension,
2. **pdftoppm** (`poppler-utils`),
3. **Ghostscript** (`gs`).

If none are installed, the upload still works: the PDF goes to Azure Vision
whole and the **words** are recovered, but the page images are lost and the book
becomes text-only. `GET /api/v1/ingest` reports this up front as
`meta.can_render_pdf_pages`, and the upload screen warns the teacher *before*
they upload so they can send page photos instead.

Uploading page images never needs any of this.

## Storage

Page images and recordings go to the `public` disk, so the symlink has to exist:

```
php artisan storage:link
```

## Tests

```
php artisan test
```

They run against in-memory SQLite and fake every Azure call, so no keys are
needed and nothing is billed.
