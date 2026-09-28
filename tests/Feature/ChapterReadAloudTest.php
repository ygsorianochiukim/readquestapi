<?php

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Services\ChapterParagraphs;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Student\Models\Student;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A reader chapter made of two pages, three paragraphs in all:
 * "The fox ran fast." and "The dog sat down." on the first, under its
 * heading, and "The cat slept all day." on the second.
 *
 * @return array{0: Chapter, 1: BookPage, 2: BookPage}
 */
function pagedChapter(Student $student): array
{
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();

    $first = BookPage::create([
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'page_number' => 1,
        'text' => "Chapter 1\nThe fox ran fast.\nThe dog sat down.",
    ]);
    $second = BookPage::create([
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'page_number' => 2,
        'text' => 'The cat slept all day.',
    ]);

    return [$chapter, $first, $second];
}

/**
 * Azure scoring a reading of whatever reference text it is sent, at this
 * score, and hearing the pupil say exactly that text.
 */
function fakeParagraphScoring(float $score = 90.0): void
{
    config()->set('services.azure_speech.key', 'test-key');
    config()->set('services.azure_speech.region', 'eastus');

    Http::fake(function (Request $request) use ($score) {
        // Both passes hear the paragraph the test says is being read.
        $reference = (string) app('test.paragraph');
        $spoken = strtolower(preg_replace('/[^\w\s]/', '', $reference));
        $words = collect(preg_split('/\s+/', trim($spoken)))->map(fn ($word) => [
            'Word' => $word,
            'PronunciationAssessment' => ['ErrorType' => 'None', 'AccuracyScore' => 95.0],
        ])->all();

        return Http::response([
            'RecognitionStatus' => 'Success',
            'DisplayText' => $reference,
            'NBest' => [[
                'Display' => $reference,
                'Lexical' => $spoken,
                'PronunciationAssessment' => [
                    'AccuracyScore' => $score,
                    'FluencyScore' => $score,
                    'CompletenessScore' => $score,
                    'PronScore' => $score,
                ],
                'Words' => $words,
            ]],
        ]);
    });
}

function readParagraph(TestCase $test, Student $student, Chapter $chapter, BookPage $page, int $paragraph): TestResponse
{
    Storage::fake('public');
    app()->instance('test.paragraph', app(ChapterParagraphs::class)->of($page)[$paragraph] ?? '');

    return $test->withHeaders(studentHeaders($student))
        ->post('/api/v1/pronunciation', [
            'audio' => UploadedFile::fake()->create('reading.wav', 16),
            'chapter_id' => $chapter->id,
            'book_page_id' => $page->id,
            'paragraph_index' => $paragraph,
        ]);
}

function chapterReadAloudPassed(TestCase $test, Student $student, Chapter $chapter): bool
{
    return (bool) collect(
        $test->withHeaders(studentHeaders($student))
            ->getJson("/api/v1/student/books/{$chapter->book_id}/progress")
            ->json('data.chapters')
    )->firstWhere('id', $chapter->id)['progress']['pronunciation_passed'];
}

it('scores one paragraph against that paragraph alone', function () {
    $student = makeStudent(makeTeacher());
    [$chapter, $first] = pagedChapter($student);
    fakeParagraphScoring();

    $response = readParagraph($this, $student, $chapter, $first, 1)->assertCreated();

    expect($response->json('data.reference_text'))->toBe('The dog sat down.')
        ->and($response->json('data.paragraph_index'))->toBe(1)
        ->and($response->json('meta.read_aloud.pages_total'))->toBe(3)
        ->and($response->json('meta.read_aloud.pages_read'))->toBe(1)
        ->and($response->json('meta.read_aloud.passed'))->toBeFalse()
        // One page read well is not the whole chapter read.
        ->and(chapterReadAloudPassed($this, $student, $chapter))->toBeFalse();
});

it('passes the chapter once every page has been read aloud', function () {
    $student = makeStudent(makeTeacher());
    [$chapter, $first, $second] = pagedChapter($student);
    fakeParagraphScoring();

    readParagraph($this, $student, $chapter, $first, 0)->assertCreated();
    readParagraph($this, $student, $chapter, $first, 1)->assertCreated();
    $last = readParagraph($this, $student, $chapter, $second, 0)->assertCreated();

    expect($last->json('meta.read_aloud.pages_read'))->toBe(3)
        ->and($last->json('meta.read_aloud.passed'))->toBeTrue()
        ->and(chapterReadAloudPassed($this, $student, $chapter))->toBeTrue();

    $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/chapters/{$chapter->id}/read-aloud")
        ->assertOk()
        ->assertJsonPath('data.pages_read', 3)
        ->assertJsonPath('data.passed', true);
});

it('does not pass the chapter when the pages average below the pass mark', function () {
    $student = makeStudent(makeTeacher());
    [$chapter, $first, $second] = pagedChapter($student);
    fakeParagraphScoring(40.0);

    readParagraph($this, $student, $chapter, $first, 0)->assertCreated();
    readParagraph($this, $student, $chapter, $first, 1)->assertCreated();
    $last = readParagraph($this, $student, $chapter, $second, 0)->assertCreated();

    expect($last->json('meta.read_aloud.pages_read'))->toBe(3)
        ->and($last->json('meta.read_aloud.passed'))->toBeFalse()
        ->and(chapterReadAloudPassed($this, $student, $chapter))->toBeFalse();
});

it('refuses a page that belongs to another chapter', function () {
    $student = makeStudent(makeTeacher());
    [$chapter] = pagedChapter($student);
    [, $otherPage] = pagedChapter($student);
    fakeParagraphScoring();

    readParagraph($this, $student, $chapter, $otherPage, 0)->assertStatus(422);
});

it('refuses a paragraph the page does not have', function () {
    $student = makeStudent(makeTeacher());
    [$chapter, , $second] = pagedChapter($student);
    fakeParagraphScoring();

    readParagraph($this, $student, $chapter, $second, 5)->assertStatus(422);
});

/** Azure Speech saying back which words it was asked to speak. */
function fakeNarration(): void
{
    config()->set('services.azure_speech.key', 'test-key');
    config()->set('services.azure_speech.region', 'eastus');

    Http::fake(fn (Request $request) => Http::response('MP3:'.strip_tags($request->body()), 200));
}

it('narrates one paragraph of a page on its own', function () {
    Storage::fake('local');
    $student = makeStudent(makeTeacher());
    [, $first] = pagedChapter($student);
    fakeNarration();

    $response = $this->withHeaders(studentHeaders($student))
        ->get("/api/v1/pages/{$first->id}/narration?paragraph=1")
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');

    expect($response->getContent())->toBe('MP3:The dog sat down.');
});

it('makes every page of a chapter speak ahead of time', function () {
    Storage::fake('local');
    $student = makeStudent(makeTeacher());
    [$chapter] = pagedChapter($student);
    fakeNarration();

    \App\Domain\Speech\Jobs\PrepareChapterNarration::dispatchSync($chapter->id);

    // Three paragraphs, three clips — and asking for one now needs no Azure call.
    expect(Storage::files('narration'))->toHaveCount(3);
    Http::assertSentCount(3);
});
