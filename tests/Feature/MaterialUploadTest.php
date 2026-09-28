<?php

use App\Domain\Book\Models\Book;
use App\Domain\Ingest\Models\IngestBatch;
use App\Domain\Ingest\Services\ChapterSegmenter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Azure Vision reading pages, one OCR result per call, in the order given.
 *
 * @param  list<string>  $pages
 */
function fakeVision(array $pages): void
{
    config()->set('services.azure_vision.key', 'test-key');
    config()->set('services.azure_vision.endpoint', 'https://vision.test');

    $call = 0;

    Http::fake(function (Request $request) use ($pages, &$call) {
        if (str_contains($request->url(), 'read/analyze')) {
            return Http::response('', 202, ['Operation-Location' => 'https://vision.test/op/'.$call++]);
        }

        // The poll. Which page it is for is in the operation URL.
        $index = (int) str_replace('https://vision.test/op/', '', $request->url());

        return Http::response([
            'status' => 'succeeded',
            'analyzeResult' => [
                'readResults' => [[
                    'lines' => array_map(
                        fn (string $line) => ['text' => $line],
                        explode("\n", $pages[$index] ?? ''),
                    ),
                ]],
            ],
        ]);
    });
}

/** Upload page photos, which is the path that needs no PDF renderer. */
function uploadPages($test, $teacher, array $texts, array $payload = [])
{
    Storage::fake('local');
    Storage::fake('public');
    fakeVision($texts);

    $files = array_map(
        fn (int $index) => UploadedFile::fake()->image("page-{$index}.jpg", 800, 1200),
        range(1, count($texts)),
    );

    return $test->withHeaders(teacherHeaders($teacher))
        ->post('/api/v1/ingest', ['files' => $files] + $payload);
}

it('makes a book out of an upload, with no "add book" step first', function () {
    $teacher = makeTeacher();

    $response = uploadPages($this, $teacher, [
        "Chapter 1\nThe cat sat on the mat and looked at the dog.",
        'The dog ran away across the wide green field.',
    ])->assertCreated();

    $batch = IngestBatch::find($response->json('data.id'));

    expect($batch)->not->toBeNull()
        // The queue runs inline under test, so it is already finished.
        ->and($batch->status)->toBe('ready')
        ->and($batch->pages_total)->toBe(2)
        ->and($batch->pages_done)->toBe(2);

    $book = Book::find($batch->book_id);

    // Created by the upload, and a draft until the teacher approves it.
    expect($book)->not->toBeNull()
        ->and($book->status)->toBe('draft')
        ->and($book->pages)->toHaveCount(2)
        ->and($book->pages[0]->text)->toContain('The cat sat on the mat');
});

it('keeps a draft out of the library until it is approved', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $response = uploadPages($this, $teacher, ['The cat sat on the mat and looked around.']);
    $batch = IngestBatch::find($response->json('data.id'));

    // A child must never see a book that is still being checked.
    $library = $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/reader/books')
        ->assertOk();

    expect($library->json('data'))->toHaveCount(0);

    // Nor should it be offered for assignment on the teacher's book list.
    expect($this->withHeaders(teacherHeaders($teacher))->getJson('/api/v1/books')->json('data'))
        ->toHaveCount(0);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$batch->id}/commit", ['reading_level' => 'Level 2'])
        ->assertOk();

    expect($this->withHeaders(studentHeaders($student))->getJson('/api/v1/reader/books')->json('data'))
        ->toHaveCount(1);
});

it('shows the teacher what it read before anything is published', function () {
    $teacher = makeTeacher();

    $response = uploadPages($this, $teacher, [
        "Chapter 1\nThe cat sat on the mat and looked at the dog.",
        "Chapter 2\nThe dog ran away across the wide green field today.",
    ]);

    $preview = $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/ingest/{$response->json('data.id')}")
        ->assertOk()
        ->json('data');

    expect($preview['pages'])->toHaveCount(2)
        ->and($preview['pages'][0]['image_url'])->not->toBeNull()
        ->and($preview['status_message'])->toBe('Ready for you to check.')
        // And where it thinks the chapters are, so that is not typed either.
        ->and($preview['suggested_chapters'])->toHaveCount(2)
        ->and($preview['suggested_chapters'][0]['title'])->toBe('Chapter 1');
});

it('lets a teacher fix what the scanner misread', function () {
    $teacher = makeTeacher();

    $response = uploadPages($this, $teacher, ['The cat sat on the rnat and looked around.']);
    $batchId = $response->json('data.id');
    $batch = IngestBatch::find($batchId);
    $page = $batch->book->pages()->first();

    $this->withHeaders(teacherHeaders($teacher))
        ->patchJson("/api/v1/ingest/{$batchId}/pages/{$page->id}", [
            'text' => 'The cat sat on the mat and looked around.',
        ])
        ->assertOk();

    expect($page->refresh()->text)->toContain('on the mat');
});

it('turns the pages into chapters when the teacher asks for a reader', function () {
    $teacher = makeTeacher();

    $response = uploadPages($this, $teacher, [
        "Chapter 1\nThe cat sat on the mat and looked at the dog.",
        "Chapter 2\nThe dog ran away across the wide green field today.",
    ]);

    $book = $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$response->json('data.id')}/commit", [
            'title' => 'Cat And Dog',
            'reading_level' => 'Level 1',
            'as_chapters' => true,
        ])
        ->assertOk()
        ->json('data');

    $saved = Book::with('chapters')->find($book['id']);

    expect($saved->title)->toBe('Cat And Dog')
        ->and($saved->type)->toBe('standard')
        ->and($saved->status)->toBe('active')
        ->and($saved->chapters)->toHaveCount(2)
        ->and($saved->chapters[0]->story_text)->toContain('The cat sat on the mat')
        // The heading line itself is not story text a child has to read twice.
        ->and($saved->chapters[0]->story_text)->not->toContain('Chapter 1');
});

it('throws the whole draft away when the scan was no good', function () {
    $teacher = makeTeacher();

    $response = uploadPages($this, $teacher, ['The cat sat on the mat.']);
    $batch = IngestBatch::find($response->json('data.id'));
    $bookId = $batch->book_id;

    $this->withHeaders(teacherHeaders($teacher))
        ->deleteJson("/api/v1/ingest/{$batch->id}")
        ->assertOk();

    expect(Book::find($bookId))->toBeNull()
        ->and(IngestBatch::find($batch->id))->toBeNull();
});

it('refuses a mixed upload rather than reading half of it', function () {
    $teacher = makeTeacher();
    Storage::fake('local');

    $this->withHeaders(teacherHeaders($teacher))
        ->post('/api/v1/ingest', [
            'files' => [
                UploadedFile::fake()->create('book.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('page-1.jpg'),
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('files');
});

it("will not show one teacher another's upload", function () {
    $mine = makeTeacher();
    $response = uploadPages($this, $mine, ['The cat sat on the mat.']);

    $this->withHeaders(teacherHeaders(makeTeacher()))
        ->getJson("/api/v1/ingest/{$response->json('data.id')}")
        ->assertForbidden();
});

it('finds the chapters without being told where they are', function () {
    $segmenter = app(ChapterSegmenter::class);

    $chapters = $segmenter->segment([
        ['page_number' => 1, 'text' => "THE BRAVE LITTLE FOX\nby Maria Santos"],
        ['page_number' => 2, 'text' => "Chapter 1: The Forest\nThe fox lived deep in the forest near the river."],
        ['page_number' => 3, 'text' => 'Every morning it went out to look for its breakfast.'],
        ['page_number' => 4, 'text' => "CHAPTER 2\nOne day the fox met a rabbit beside the old stone wall."],
    ]);

    expect($chapters)->toHaveCount(2)
        ->and($chapters[0]['title'])->toBe('Chapter 1: The Forest')
        // Pages after a heading belong to it until the next one.
        ->and($chapters[0]['page_numbers'])->toEqual([2, 3])
        // Shouting headings are title-cased rather than left in block capitals.
        ->and($chapters[1]['title'])->toBe('Chapter 2')
        ->and($chapters[1]['page_numbers'])->toEqual([4]);
});

it('keeps the whole book as one chapter when it has no headings', function () {
    $segmenter = app(ChapterSegmenter::class);

    $chapters = $segmenter->segment([
        ['page_number' => 1, 'text' => 'The cat sat on the mat and looked at the dog.'],
        ['page_number' => 2, 'text' => 'The dog ran away across the field.'],
    ]);

    // Guessing a split that is not there would be worse than not guessing.
    expect($chapters)->toHaveCount(1)
        ->and($chapters[0]['page_numbers'])->toEqual([1, 2]);
});
