<?php

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Ingest\Models\IngestBatch;
use App\Domain\Ingest\Services\ChapterSegmenter;
use App\Domain\Progress\Models\PageProgress;
use App\Domain\Progress\Services\ProgressService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Every book is Book → Chapters → Content, and books only come from uploads.
*/

/** Azure Vision answering each read with the next text in the list. */
function fakeVisionReads(array $pages): void
{
    config()->set('services.azure_vision.key', 'test-key');
    config()->set('services.azure_vision.endpoint', 'https://vision.test');

    $call = 0;

    Http::fake(function (Request $request) use ($pages, &$call) {
        if (str_contains($request->url(), 'read/analyze')) {
            return Http::response('', 202, ['Operation-Location' => 'https://vision.test/op/'.$call++]);
        }

        $index = (int) str_replace('https://vision.test/op/', '', $request->url());

        return Http::response([
            'status' => 'succeeded',
            'analyzeResult' => [
                'readResults' => [[
                    'lines' => array_map(fn (string $line) => ['text' => $line], explode("\n", $pages[$index] ?? '')),
                ]],
            ],
        ]);
    });
}

/** Upload page photos through the material upload and return the batch. */
function uploadPicturePages($test, $teacher, array $texts): IngestBatch
{
    Storage::fake('local');
    Storage::fake('public');
    fakeVisionReads($texts);

    $files = array_map(
        fn (int $index) => UploadedFile::fake()->image("page-{$index}.jpg", 600, 800),
        range(1, count($texts)),
    );

    $response = $test->withHeaders(teacherHeaders($teacher))
        ->post('/api/v1/ingest', ['files' => $files])
        ->assertCreated();

    return IngestBatch::find($response->json('data.id'));
}

/** A published picture book with its pages already grouped into chapters. */
function makePictureBookWithChapters(array $pagesPerChapter): Book
{
    $book = Book::create(['title' => 'Picture Book', 'type' => 'scanned', 'status' => 'active', 'sequence' => 1]);
    $pageNumber = 0;

    foreach ($pagesPerChapter as $index => $count) {
        $chapter = Chapter::create([
            'book_id' => $book->id,
            'chapter_number' => $index + 1,
            'title' => 'Part '.($index + 1),
        ]);

        for ($i = 0; $i < $count; $i++) {
            BookPage::create([
                'book_id' => $book->id,
                'chapter_id' => $chapter->id,
                'page_number' => ++$pageNumber,
                'text' => null, // picture-only: finished on the read mark alone
            ]);
        }
    }

    return $book->refresh();
}

it('groups a picture book into chapters by its headings when it is published', function () {
    $teacher = makeTeacher();

    $batch = uploadPicturePages($this, $teacher, [
        'THE LOST GOAT',
        "Chapter 1\nThe goat walked up the hill.",
        '',
        "Chapter 2\nThe goat came home.",
    ]);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$batch->id}/commit", [])
        ->assertOk();

    $book = Book::with(['chapters', 'pages'])->find($batch->book_id);

    expect($book->type)->toBe('scanned')
        ->and($book->chapters)->toHaveCount(2)
        ->and($book->chapters[0]->title)->toBe('Chapter 1')
        // No page is left outside a chapter — not the cover, not the blank page.
        ->and($book->pages->whereNull('chapter_id'))->toHaveCount(0)
        ->and($book->chapters[0]->pages()->pluck('page_number')->all())->toEqual([1, 2, 3])
        ->and($book->chapters[1]->pages()->pluck('page_number')->all())->toEqual([4]);
});

it('keeps a short picture book without headings as one chapter, and splits a long one into parts', function () {
    $segmenter = app(ChapterSegmenter::class);

    $short = $segmenter->groupPages(array_map(
        fn (int $n) => ['page_number' => $n, 'text' => null],
        range(1, 6),
    ));

    expect($short)->toHaveCount(1)
        ->and($short[0]['title'])->toBe('Chapter 1')
        ->and($short[0]['page_numbers'])->toEqual(range(1, 6));

    $long = $segmenter->groupPages(array_map(
        fn (int $n) => ['page_number' => $n, 'text' => 'A picture of a dog.'],
        range(1, 20),
    ));

    expect($long)->toHaveCount(3)
        ->and($long[0]['title'])->toBe('Part 1')
        ->and(array_merge(...array_column($long, 'page_numbers')))->toEqual(range(1, 20));
});

it('uses the teacher\'s edited grouping for a picture book', function () {
    $teacher = makeTeacher();
    $batch = uploadPicturePages($this, $teacher, ['One.', 'Two.', 'Three.']);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$batch->id}/commit", [
            'parts' => [
                ['title' => 'Morning', 'page_numbers' => [1, 2]],
                ['title' => 'Night', 'page_numbers' => [3]],
            ],
        ])
        ->assertOk();

    $chapters = Book::find($batch->book_id)->chapters()->withCount('pages')->get();

    expect($chapters->pluck('title')->all())->toEqual(['Morning', 'Night'])
        ->and($chapters->pluck('pages_count')->all())->toEqual([2, 1]);
});

it('gives the student library each picture book\'s chapters and progress', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makePictureBookWithChapters([2, 3]);
    assignBook($student, $book);

    $firstPage = $book->pages()->first();
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pages/{$firstPage->id}/read")
        ->assertOk();

    $entry = collect(
        $this->withHeaders(studentHeaders($student))->getJson('/api/v1/student/progress')->assertOk()->json('data')
    )->firstWhere('id', $book->id);

    $chapters = $book->chapters()->get();

    expect($entry['page_chapters'])->toEqual([
        ['id' => $chapters[0]->id, 'title' => 'Part 1', 'sequence' => 1, 'page_count' => 2, 'pages_completed' => 1, 'first_page_id' => $firstPage->id],
        [
            'id' => $chapters[1]->id, 'title' => 'Part 2', 'sequence' => 2, 'page_count' => 3, 'pages_completed' => 0,
            'first_page_id' => $book->pages()->where('page_number', 3)->value('id'),
        ],
    ])
        // Still measured in pages, and still not a chapter book to the gate.
        ->and($entry['total_chapters'])->toBe(0)
        ->and($entry['total_pages'])->toBe(5)
        ->and($entry['completed_pages'])->toBe(1);
});

it('lists one chapter\'s pages when the pupil asks for it', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makePictureBookWithChapters([2, 3]);
    assignBook($student, $book);
    $second = $book->chapters()->where('chapter_number', 2)->first();

    $data = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$book->id}/pages?chapter={$second->id}")
        ->assertOk()
        ->json('data');

    expect($data['pages'])->toHaveCount(3)
        ->and(collect($data['pages'])->pluck('chapter_id')->unique()->all())->toEqual([$second->id])
        ->and($data['chapter']['page_count'])->toBe(3)
        ->and($data['total_pages'])->toBe(5);

    $all = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$book->id}/pages")
        ->json('data.pages');

    expect($all)->toHaveCount(5)->and($all[0])->toHaveKey('chapter_id');

    $other = makePictureBookWithChapters([1]);
    $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$book->id}/pages?chapter={$other->chapters()->first()->id}")
        ->assertNotFound();
});

it('does not let a picture book with chapters lock the next book', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $picture = makePictureBookWithChapters([2]);
    $next = makeBook(1, ['sequence' => 2]);
    assignBook($student, $picture);
    assignBook($student, $next);

    $overview = collect(app(ProgressService::class)->overviewForStudent($student));

    expect($overview->firstWhere('id', $next->id)['is_locked'])->toBeFalse();
});

it('shows a teacher how many pupils finished each picture-book chapter', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makePictureBookWithChapters([2, 1]);
    assignBook($student, $book);

    foreach ($book->chapters()->first()->pages as $page) {
        PageProgress::create([
            'student_id' => $student->id,
            'book_page_id' => $page->id,
            'is_read' => true,
            'completed_at' => now(),
        ]);
    }

    $progress = $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/books/{$book->id}/chapters")
        ->assertOk()
        ->json('progress');

    [$first, $second] = $book->chapters()->pluck('id')->all();

    expect($progress[$first]['completed'])->toBe(1)
        ->and($progress[$second]['completed'])->toBe(0);
});

it('no longer makes empty books or typed chapters by hand', function () {
    $teacher = makeTeacher();
    $book = makeBook(1);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/books', ['title' => 'Typed book'])
        ->assertStatus(405);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/books/{$book->id}/chapters", ['chapter_number' => 2, 'title' => 'Typed', 'story_text' => 'Typed.'])
        ->assertStatus(405);

    // Editing a book's details still works.
    $this->withHeaders(teacherHeaders($teacher))
        ->putJson("/api/v1/books/{$book->id}", ['title' => 'Renamed'])
        ->assertOk();

    expect($book->refresh()->title)->toBe('Renamed');
});

it('adds a chapter to a reader from scanned pages, with a quiz written for it', function () {
    $teacher = makeTeacher();
    $book = makeBook(1);
    Storage::fake('public');
    fakeVisionReads([
        "Chapter 2: The Market\nMaria walked to the busy market with her basket.",
        'She bought fresh mangoes, sweet bananas and a small pumpkin for dinner.',
    ]);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->post("/api/v1/books/{$book->id}/chapters/upload", [
            'files' => [
                UploadedFile::fake()->image('p1.jpg', 600, 800),
                UploadedFile::fake()->image('p2.jpg', 600, 800),
            ],
        ])
        ->assertCreated();

    $chapter = Chapter::find($response->json('data.id'));

    expect($chapter->chapter_number)->toBe(2)
        ->and($chapter->title)->toBe('Chapter 2: The Market')
        ->and($chapter->story_text)->toContain('busy market')
        ->and($chapter->story_text)->toContain('mangoes')
        ->and($chapter->story_text)->not->toContain('Chapter 2')
        ->and($chapter->pages()->count())->toBe(2)
        ->and($chapter->quizQuestions()->count())->toBeGreaterThan(0)
        ->and($chapter->quizQuestions()->first()->is_generated)->toBeTrue();
});

it('adds a chapter of pages to a picture book', function () {
    $teacher = makeTeacher();
    $book = makePictureBookWithChapters([2]);
    Storage::fake('public');
    fakeVisionReads(['', '']);

    $this->withHeaders(teacherHeaders($teacher))
        ->post("/api/v1/books/{$book->id}/chapters/upload", [
            'files' => [UploadedFile::fake()->image('p1.jpg'), UploadedFile::fake()->image('p2.jpg')],
        ])
        ->assertCreated();

    $chapter = $book->chapters()->where('chapter_number', 2)->first();

    expect($chapter)->not->toBeNull()
        ->and($chapter->story_text)->toBeNull()
        ->and($chapter->pages()->pluck('page_number')->all())->toEqual([3, 4]);
});

it('re-scans a page that came back without words, from a new photo', function () {
    $teacher = makeTeacher();
    $batch = uploadPicturePages($this, $teacher, ['']);
    $page = $batch->book->pages()->first();

    expect($page->text)->toBeNull();

    // A second Http::fake() would sit behind the first; start a clean fake
    // that answers the two re-scans in order.
    Http::swap(new Illuminate\Http\Client\Factory);
    fakeVisionReads(['The sun is hot today.', 'The sun is very hot today.']);

    $this->withHeaders(teacherHeaders($teacher))
        ->post("/api/v1/ingest/{$batch->id}/pages/{$page->id}/rescan", [
            'image' => UploadedFile::fake()->image('retake.jpg', 600, 800),
        ])
        ->assertOk()
        ->assertJsonPath('data.text', 'The sun is hot today.');

    // Re-reading the stored picture works without a new photo too.
    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pages/{$page->id}/rescan")
        ->assertOk()
        ->assertJsonPath('data.text', 'The sun is very hot today.');
});
