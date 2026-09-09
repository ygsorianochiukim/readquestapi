<?php

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Pronunciation\Services\ReadingMatchService;
use App\Domain\Student\Models\Student;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The words on the page every test in this file reads from. */
function pageText(): string
{
    return 'The quick brown fox jumps over the lazy dog.';
}

/** A one-page scanned book, assigned to the pupil so progress is tracked. */
function readAloudPage(Student $student): BookPage
{
    $book = Book::create([
        'title' => 'Read Aloud Book',
        'sequence' => 1,
        'status' => 'active',
        'type' => 'scanned',
    ]);

    assignBook($student, $book);

    return BookPage::create([
        'book_id' => $book->id,
        'page_number' => 1,
        'text' => pageText(),
    ]);
}

/**
 * Word-level results the way the scoring pass returns them. Every word is a
 * page word — that pass recognises against the reference text, so it can only
 * ever report which page words it believes it matched.
 *
 * @return list<array<string, mixed>>
 */
function azureWords(string $text, string $errorType): array
{
    return collect(preg_split('/\s+/', trim($text)))
        ->map(fn (string $word) => [
            'Word' => strtolower(trim($word, '.,!?')),
            'PronunciationAssessment' => [
                'ErrorType' => $errorType,
                'AccuracyScore' => $errorType === 'None' ? 95.0 : 0.0,
            ],
        ])
        ->all();
}

/**
 * Stand in for Azure's two passes over the same audio.
 *
 * The scoring pass (the one carrying the Pronunciation-Assessment header) is
 * told what the page says, so it always returns strong scores and a transcript
 * built out of page words — exactly what it does in production, and what used
 * to let an off-script reading pass with the page echoed back at the pupil.
 *
 * The plain pass knows nothing about the page and returns what was really said.
 *
 * @param  string  $spoken  what the pupil actually said ('' for silence)
 * @param  list<array<string, mixed>>|null  $matchedPageWords  what the scoring pass claims it heard
 */
function fakeAzure(string $spoken, ?array $matchedPageWords = null, bool $transcriptFails = false): void
{
    config()->set('services.azure_speech.key', 'test-key');
    config()->set('services.azure_speech.region', 'eastus');

    $matchedPageWords ??= azureWords(pageText(), 'Omission');

    Http::fake(function (Request $request) use ($spoken, $matchedPageWords, $transcriptFails) {
        if ($request->hasHeader('Pronunciation-Assessment')) {
            return Http::response([
                'RecognitionStatus' => 'Success',
                // Azure echoes the page back here whatever was said.
                'DisplayText' => pageText(),
                'NBest' => [[
                    'Display' => pageText(),
                    'Lexical' => strtolower(rtrim(pageText(), '.')),
                    'PronunciationAssessment' => [
                        'AccuracyScore' => 92.0,
                        'FluencyScore' => 90.0,
                        'CompletenessScore' => 95.0,
                        'PronScore' => 91.0,
                    ],
                    'Words' => $matchedPageWords,
                ]],
            ]);
        }

        if ($transcriptFails) {
            return Http::response('', 500);
        }

        if (blank($spoken)) {
            return Http::response(['RecognitionStatus' => 'NoMatch']);
        }

        return Http::response([
            'RecognitionStatus' => 'Success',
            'DisplayText' => $spoken,
            'NBest' => [['Lexical' => $spoken, 'Display' => $spoken]],
        ]);
    });
}

/** Submit a recording of the pupil reading (or not reading) the page. */
function submitReading(TestCase $test, Student $student, BookPage $page): TestResponse
{
    Storage::fake('public');

    return $test->withHeaders(studentHeaders($student))
        ->post('/api/v1/pronunciation', [
            'audio' => UploadedFile::fake()->create('reading.wav', 16),
            'book_page_id' => $page->id,
        ]);
}

it("scores a reading on the words it actually heard, not on Azure's alignment", function () {
    $student = makeStudent(makeTeacher());
    $page = readAloudPage($student);

    // The pupil said something with none of the page's words in it.
    fakeAzure('i want to play video games');

    $response = submitReading($this, $student, $page)->assertCreated();

    expect($response->json('data.text_match_score'))->toEqual(0.0)
        ->and($response->json('data.is_off_script'))->toBeTrue()
        // Azure said 91; what came back is the match, not the alignment score.
        ->and($response->json('data.pron_score'))->toEqual(0.0);
});

it('previews the words the pupil said, not the page words Azure claims to have matched', function () {
    $student = makeStudent(makeTeacher());
    $page = readAloudPage($student);

    // The pupil talked about something else entirely, but the scoring pass —
    // as Azure really behaves — reports a couple of page words it thinks it
    // heard. That is the "We heard: 1 story" the pupils were seeing.
    fakeAzure('my name is juan and i like basketball', [
        ...azureWords('the dog', 'None'),
        ...azureWords('quick brown fox jumps over the lazy', 'Omission'),
    ]);

    $response = submitReading($this, $student, $page)->assertCreated();

    expect($response->json('data.recognized_text'))->toBe('my name is juan and i like basketball')
        ->and($response->json('data.recognized_text'))->not->toContain('dog')
        ->and($response->json('data.is_off_script'))->toBeTrue();
});

it('falls back to the matched page words when the plain pass fails', function () {
    $student = makeStudent(makeTeacher());
    $page = readAloudPage($student);

    fakeAzure(
        'anything at all',
        [...azureWords('the quick brown', 'None'), ...azureWords('fox jumps over the lazy dog', 'Omission')],
        transcriptFails: true,
    );

    $response = submitReading($this, $student, $page)->assertCreated();

    expect($response->json('data.recognized_text'))->toBe('the quick brown');
});

it('reports silence as nothing heard', function () {
    $student = makeStudent(makeTeacher());
    $page = readAloudPage($student);

    fakeAzure('', []);

    $response = submitReading($this, $student, $page)->assertCreated();

    expect($response->json('data.recognized_text'))->toBeNull()
        ->and($response->json('data.pron_score'))->toEqual(0.0)
        ->and($response->json('data.is_off_script'))->toBeTrue();
});

it('does not finish the page on an off-script reading', function () {
    $student = makeStudent(makeTeacher());
    $page = readAloudPage($student);

    fakeAzure('i want to play video games');

    submitReading($this, $student, $page)->assertCreated();

    $progress = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$page->book_id}/pages")
        ->assertOk()
        ->json('data.pages.0');

    expect($progress['pronunciation_passed'])->toBeFalse()
        ->and($progress['is_completed'])->toBeFalse();
});

it('still passes a pupil who reads the page', function () {
    $student = makeStudent(makeTeacher());
    $page = readAloudPage($student);

    fakeAzure(strtolower(rtrim(pageText(), '.')), azureWords(pageText(), 'None'));

    $response = submitReading($this, $student, $page)->assertCreated();

    expect($response->json('data.text_match_score'))->toEqual(100.0)
        ->and($response->json('data.is_off_script'))->toBeFalse()
        // Capped by the match, so Azure's own score comes through untouched.
        ->and($response->json('data.pron_score'))->toEqual(91.0);

    $progress = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$page->book_id}/pages")
        ->json('data.pages.0');

    expect($progress['pronunciation_passed'])->toBeTrue()
        ->and($progress['is_completed'])->toBeTrue();
});

it('marks a part-read page down without calling it off-script', function () {
    $match = app(ReadingMatchService::class);

    // Read faithfully but only halfway: penalised for what was skipped, yet
    // every word said was on the page, so it is not a wrong-page reading.
    $partial = $match->evaluate(pageText(), 'the quick brown fox jumps');
    expect($partial['match'])->toBeLessThan(100.0)
        ->and($partial['on_page'])->toEqual(100.0);

    // Nothing on the page: both collapse.
    $wrong = $match->evaluate(pageText(), 'i want to play video games');
    expect($wrong['match'])->toEqual(0.0)
        ->and($wrong['on_page'])->toEqual(0.0);

    // The page's own words, out of order, is not reading it.
    expect($match->evaluate('one two three four', 'four three two one')['match'])
        ->toBeLessThan(50.0);

    // Silence.
    expect($match->evaluate(pageText(), null))->toEqual(['match' => 0.0, 'on_page' => 0.0]);
});
