<?php

use App\Domain\Ingest\Models\IngestBatch;
use App\Domain\Ingest\Services\ChapterContentAgent;
use App\Domain\Ingest\Services\PdfRasterizer;
use App\Domain\Ocr\Services\OpenAiPdfReader;
use App\Domain\Ocr\Services\PdfTextReader;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** A Responses API answer carrying this JSON. */
function openAiAnswer(array $json): array
{
    return [
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => json_encode($json)]],
        ]],
    ];
}

/**
 * OpenAI reading a PDF into these pages and, when given, sorting them with
 * these verdicts — each call answered by the schema it asked for.
 *
 * @param  list<array{page_number: int, text: string}>  $pages
 * @param  list<array{page: int, kind: string, chapter: string, text: string}>|null  $verdicts
 */
function fakeOpenAi(array $pages, ?array $verdicts = null): void
{
    config()->set('services.openai.key', 'sk-test');
    config()->set('services.openai.model', 'test-model');
    config()->set('services.openai.base_url', 'https://openai.test/v1');

    Http::fake(function (Request $request) use ($pages, $verdicts) {
        $asked = $request['text']['format']['name'] ?? null;

        if ($asked === 'chapter_content') {
            return $verdicts === null
                ? Http::response(['error' => ['message' => 'agent not faked']], 500)
                : Http::response(openAiAnswer(['pages' => $verdicts]));
        }

        return Http::response(openAiAnswer(['pages' => $pages]));
    });
}

/** A host with no PDF renderer, which is when the PDF is read as text. */
function withoutRasterizer(): void
{
    app()->instance(PdfRasterizer::class, Mockery::mock(PdfRasterizer::class, fn ($mock) => $mock->shouldReceive('isAvailable')->andReturn(false)));
}

it('reads a PDF with OpenAI, one entry per page, in page order', function () {
    fakeOpenAi([
        ['page_number' => 2, 'text' => 'In the forest, they are greeted by many big, green trees.'],
        ['page_number' => 1, 'text' => "Chapter 1\nJean reads the clue again."],
    ]);

    $pages = app(OpenAiPdfReader::class)->readPages('%PDF-1.4 fake', 'hunt.pdf');

    expect($pages)->toBe([
        "Chapter 1\nJean reads the clue again.",
        'In the forest, they are greeted by many big, green trees.',
    ]);

    Http::assertSent(function (Request $request) {
        $file = $request['input'][0]['content'][0];

        return $request->url() === 'https://openai.test/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'test-model'
            && $file['type'] === 'input_file'
            && $file['filename'] === 'hunt.pdf'
            && $file['file_data'] === 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 fake');
    });
});

it('explains what OpenAI objected to', function () {
    config()->set('services.openai.key', 'sk-test');
    config()->set('services.openai.base_url', 'https://openai.test/v1');
    Http::fake(['openai.test/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

    app(OpenAiPdfReader::class)->readPages('%PDF-1.4 fake');
})->throws(RuntimeException::class, 'Invalid API key');

it('uses OpenAI over Azure when it has a key', function () {
    config()->set('services.azure_vision.key', 'azure-key');
    config()->set('services.azure_vision.endpoint', 'https://vision.test');
    fakeOpenAi([['page_number' => 1, 'text' => 'Hello']]);

    expect(app(PdfTextReader::class)->readPages('%PDF-1.4 fake'))->toBe(['Hello']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'vision.test'));
});

it('falls back to Azure when there is no OpenAI key', function () {
    config()->set('services.openai.key', null);
    config()->set('services.azure_vision.key', 'azure-key');
    config()->set('services.azure_vision.endpoint', 'https://vision.test');

    Http::fake(function (Request $request) {
        return str_contains($request->url(), 'read/analyze')
            ? Http::response('', 202, ['Operation-Location' => 'https://vision.test/op/1'])
            : Http::response([
                'status' => 'succeeded',
                'analyzeResult' => ['readResults' => [['lines' => [['text' => 'From Azure']]]]],
            ]);
    });

    expect(app(PdfTextReader::class)->readPages('%PDF-1.4 fake'))->toBe(['From Azure']);
});

/** Raw pages the way the scan of The Scavenger Hunt came back: story mixed with everything else. */
function scavengerHuntScan(): array
{
    $footer = 'DEPED COPY. All rights reserved. No part of this material may be reproduced.';

    return [
        ['page_number' => 1, 'text' => "Story by Nathalie Louge\nGOVERNMENT PROPERTY. NOT FOR SALE.\nThe Scavenger Hunt"],
        ['page_number' => 2, 'text' => "1\nChapter 1\n{$footer}\nChapter 1\nJean reads the clue again, \"A deep\nplace full of trees.\""],
        ['page_number' => 3, 'text' => "2 {$footer}\nIn the forest, they are greeted by many\nbig, green trees."],
        ['page_number' => 4, 'text' => "3\nSkill Builder 1\nFill in the missing letters.\n{$footer}"],
        ['page_number' => 5, 'text' => "4\nChapter 2\n{$footer}\n\"When we face the sun, it is east,\" Jean says."],
        ['page_number' => 6, 'text' => 'DEPED-USAID BASA PILIPINAS Leveled Reader in English GRADE 3'],
    ];
}

/** How the agent sorts that scan. */
function scavengerHuntVerdicts(): array
{
    return [
        ['page' => 1, 'kind' => 'front_matter', 'chapter' => '', 'text' => ''],
        ['page' => 2, 'kind' => 'story', 'chapter' => 'Chapter 1', 'text' => 'Jean reads the clue again, "A deep place full of trees."'],
        ['page' => 3, 'kind' => 'story', 'chapter' => '', 'text' => 'In the forest, they are greeted by many big, green trees.'],
        ['page' => 4, 'kind' => 'activity', 'chapter' => '', 'text' => ''],
        ['page' => 5, 'kind' => 'story', 'chapter' => 'Chapter 2', 'text' => '"When we face the sun, it is east," Jean says.'],
        ['page' => 6, 'kind' => 'back_matter', 'chapter' => '', 'text' => ''],
    ];
}

it('keeps only the chapter pages, cleaned, with each heading opening its chapter', function () {
    fakeOpenAi([], scavengerHuntVerdicts());

    $pages = array_map(fn (array $page) => ['image_path' => null, 'text' => $page['text']], scavengerHuntScan());

    expect(app(ChapterContentAgent::class)->keepChapterContent($pages))->toBe([
        ['image_path' => null, 'text' => "Chapter 1\nJean reads the clue again, \"A deep place full of trees.\""],
        ['image_path' => null, 'text' => 'In the forest, they are greeted by many big, green trees.'],
        ['image_path' => null, 'text' => "Chapter 2\n\"When we face the sun, it is east,\" Jean says."],
    ]);
});

it('passes the pages through untouched when the agent cannot run', function () {
    fakeOpenAi([]);

    $pages = [['image_path' => null, 'text' => '1 DEPED COPY. The cat sat.']];

    expect(app(ChapterContentAgent::class)->keepChapterContent($pages))->toBe($pages);
});

it('passes the pages through when the agent finds no story at all', function () {
    fakeOpenAi([], [['page' => 1, 'kind' => 'front_matter', 'chapter' => '', 'text' => '']]);

    $pages = [['image_path' => null, 'text' => 'The cat sat on the mat.']];

    expect(app(ChapterContentAgent::class)->keepChapterContent($pages))->toBe($pages);
});

it('turns an uploaded PDF into the chapters alone, with no renderer installed', function () {
    Storage::fake('local');
    Storage::fake('public');
    withoutRasterizer();
    fakeOpenAi(scavengerHuntScan(), scavengerHuntVerdicts());

    $teacher = makeTeacher();

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->post('/api/v1/ingest', [
            'files' => [UploadedFile::fake()->createWithContent('hunt.pdf', '%PDF-1.4 fake')],
        ])
        ->assertCreated();

    $batch = IngestBatch::find($response->json('data.id'));
    $texts = $batch->book->pages()->orderBy('page_number')->pluck('text')->implode("\n");

    expect($batch->status)->toBe('ready')
        ->and($batch->text_only)->toBeTruthy()
        // The cover, the Skill Builder and the back cover are gone.
        ->and($batch->book->pages)->toHaveCount(3)
        ->and($texts)->not->toContain('DEPED COPY')
        ->and($texts)->not->toContain('Skill Builder')
        ->and($texts)->not->toContain('NOT FOR SALE');

    $preview = $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/ingest/{$batch->id}")
        ->assertOk();

    $chapters = collect($preview->json('data.suggested_chapters'));

    expect($chapters->pluck('title')->all())->toBe(['Chapter 1', 'Chapter 2'])
        ->and($chapters->pluck('page_numbers')->all())->toBe([[1, 2], [3]])
        ->and($chapters[0]['story_text'])->toStartWith('Jean reads the clue again');
});

it('puts the pages in the real chapters even when a stand-in "Chapter 1" got there first', function () {
    Storage::fake('local');
    Storage::fake('public');
    withoutRasterizer();
    fakeOpenAi(scavengerHuntScan(), scavengerHuntVerdicts());

    $teacher = makeTeacher();

    $batchId = $this->withHeaders(teacherHeaders($teacher))
        ->post('/api/v1/ingest', [
            'files' => [UploadedFile::fake()->createWithContent('hunt.pdf', '%PDF-1.4 fake')],
        ])
        ->assertCreated()
        ->json('data.id');

    $book = IngestBatch::find($batchId)->book;

    // What the chapter_id backfill does to a scanned draft: one "Chapter 1"
    // wrapped round every loose page.
    $standIn = $book->chapters()->create(['chapter_number' => 1, 'title' => 'Chapter 1']);
    $book->pages()->update(['chapter_id' => $standIn->id]);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$batchId}/commit", ['as_chapters' => true])
        ->assertOk();

    $chapters = $book->chapters()->orderBy('chapter_number')->withCount('pages')->get();

    expect($chapters->pluck('chapter_number')->all())->toBe([1, 2])
        ->and($chapters->pluck('title')->all())->toBe(['Chapter 1', 'Chapter 2'])
        ->and($chapters->pluck('pages_count')->all())->toBe([2, 1])
        ->and($book->pages()->whereNull('chapter_id')->count())->toBe(0);
});
