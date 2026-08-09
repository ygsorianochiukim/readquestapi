<?php

use App\Domain\Ocr\Services\OcrService;
use App\Domain\SystemLog\Models\SystemLog;
use Illuminate\Http\UploadedFile;

/**
 * A real (1x1) PNG. UploadedFile::fake()->image() needs the GD extension,
 * which is not enabled here, so the bytes are supplied directly.
 */
function fakeImage(string $name = 'page1.png'): UploadedFile
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );

    return UploadedFile::fake()->createWithContent($name, $png);
}

/** A stand-in for Azure Vision so the tests never call the network. */
function fakeOcr(string $text = "Once upon a time\nthe fox ran home."): void
{
    $fake = new class($text) extends OcrService
    {
        public function __construct(private string $text) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function extractText(string $imageBytes): string
        {
            return $this->text;
        }
    };

    app()->instance(OcrService::class, $fake);
}

it('reads text out of an uploaded page image', function () {
    fakeOcr();
    $teacher = makeTeacher();

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/ocr/extract', [
            'file' => fakeImage(),
        ])
        ->assertOk();

    expect($response->json('data.text'))->toContain('Once upon a time')
        ->and($response->json('data.lines'))->toBe(2)
        ->and(SystemLog::where('action', 'ocr.extracted')->count())->toBe(1);
});

it('rejects a request without an image', function () {
    fakeOcr();
    $teacher = makeTeacher();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/ocr/extract', [])
        ->assertStatus(422);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/ocr/extract', [
            'file' => UploadedFile::fake()->createWithContent('story.pdf', '%PDF-1.4 fake pdf'),
        ])
        ->assertStatus(422);
});

it('says so plainly when scanning is not configured', function () {
    $unconfigured = new class extends OcrService
    {
        public function isConfigured(): bool
        {
            return false;
        }
    };
    app()->instance(OcrService::class, $unconfigured);

    $teacher = makeTeacher();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/ocr/extract', [
            'file' => fakeImage(),
        ])
        ->assertStatus(503)
        ->assertJsonPath('message', 'Scanning is not set up yet. Add AZURE_VISION_KEY and AZURE_VISION_ENDPOINT to enable it.');
});

it('reports a friendly error when the scan fails', function () {
    $failing = new class extends OcrService
    {
        public function isConfigured(): bool
        {
            return true;
        }

        public function extractText(string $imageBytes): string
        {
            throw new RuntimeException('Azure Vision OCR failed.');
        }
    };
    app()->instance(OcrService::class, $failing);

    $teacher = makeTeacher();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/ocr/extract', [
            'file' => fakeImage('blurry.png'),
        ])
        ->assertStatus(502);
});

it('keeps scanning away from pupils', function () {
    fakeOcr();
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $this->withHeaders(studentHeaders($student))
        ->postJson('/api/v1/ocr/extract', [
            'file' => fakeImage(),
        ])
        ->assertStatus(403);
});

it('passes Azure\'s reason through as an instruction the teacher can act on', function (string $azureReason, string $expected) {
    $failing = new class($azureReason) extends OcrService
    {
        public function __construct(private string $reason) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function extractText(string $imageBytes): string
        {
            throw new RuntimeException($this->reason);
        }
    };
    app()->instance(OcrService::class, $failing);

    $response = $this->withHeaders(teacherHeaders(makeTeacher()))
        ->postJson('/api/v1/ocr/extract', ['file' => fakeImage()])
        ->assertStatus(502);

    expect($response->json('message'))->toContain($expected);
})->with([
    ['InvalidImageSize: The input image is too large.', 'up to 4 MB'],
    ['InvalidImageDimension: dimension out of range.', '50×50'],
    ['TooManyRequests: Rate limit is exceeded.', 'Wait about a minute'],
    ['InvalidImageFormat: unsupported.', 'JPG or PNG'],
]);
