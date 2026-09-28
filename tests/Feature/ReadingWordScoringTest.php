<?php

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Models\PronunciationWord;
use App\Domain\Pronunciation\Services\ReadingPaceService;
use App\Domain\Student\Models\Student;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Ten words, so a pace can be measured from them. */
function wordPageText(): string
{
    return 'the quick brown fox jumps over the very lazy dog';
}

function wordPage(Student $student): BookPage
{
    $book = Book::create([
        'title' => 'Word Book',
        'sequence' => 1,
        'status' => 'active',
        'type' => 'scanned',
        'reading_level' => 'Level 1',
    ]);

    assignBook($student, $book);

    return BookPage::create([
        'book_id' => $book->id,
        'page_number' => 1,
        'text' => wordPageText(),
    ]);
}

/**
 * Azure's word list, with real offsets so pace can be derived.
 *
 * @param  array<string, string>  $errors  word => ErrorType for the ones that went wrong
 * @return list<array<string, mixed>>
 */
function timedWords(int $msPerWord, array $errors = []): array
{
    $ticks = 10000; // 100ns ticks per millisecond
    $offset = 500;  // half a second of throat-clearing before the first word

    $out = [];
    foreach (explode(' ', wordPageText()) as $index => $word) {
        $errorType = $errors[$word] ?? 'None';

        $out[] = [
            'Word' => $word,
            'Offset' => ($offset + $index * $msPerWord) * $ticks,
            'Duration' => (int) ($msPerWord * 0.8) * $ticks,
            'PronunciationAssessment' => [
                'ErrorType' => $errorType,
                'AccuracyScore' => $errorType === 'None' ? 95.0 : 22.0,
            ],
        ];
    }

    return $out;
}

/**
 * The same words after the assessment service has normalised them — which is
 * the shape the pace service is handed.
 *
 * @param  array<string, string>  $errors
 * @return list<array<string, mixed>>
 */
function pacedWords(int $msPerWord, array $errors = []): array
{
    return array_map(fn (array $word) => [
        'word' => $word['Word'],
        'accuracy_score' => $word['PronunciationAssessment']['AccuracyScore'],
        'error_type' => $word['PronunciationAssessment']['ErrorType'],
        'offset_ms' => (int) ($word['Offset'] / 10000),
        'duration_ms' => (int) ($word['Duration'] / 10000),
    ], timedWords($msPerWord, $errors));
}

/** @param  list<array<string, mixed>>  $words */
function fakeTimedAzure(array $words, ?string $spoken = null): void
{
    config()->set('services.azure_speech.key', 'test-key');
    config()->set('services.azure_speech.region', 'eastus');

    $spoken ??= wordPageText();

    Http::fake(function (Request $request) use ($words, $spoken) {
        if ($request->hasHeader('Pronunciation-Assessment')) {
            return Http::response([
                'RecognitionStatus' => 'Success',
                'Duration' => 60_000_000, // six seconds
                'DisplayText' => wordPageText(),
                'NBest' => [[
                    'Lexical' => wordPageText(),
                    'PronunciationAssessment' => [
                        'AccuracyScore' => 92.0,
                        'FluencyScore' => 90.0,
                        'CompletenessScore' => 95.0,
                        'ProsodyScore' => 81.5,
                        'PronScore' => 91.0,
                    ],
                    'Words' => $words,
                ]],
            ]);
        }

        return Http::response([
            'RecognitionStatus' => 'Success',
            'DisplayText' => $spoken,
            'NBest' => [['Lexical' => $spoken]],
        ]);
    });
}

function submitTimedReading($test, Student $student, BookPage $page)
{
    Storage::fake('public');

    return $test->withHeaders(studentHeaders($student))
        ->post('/api/v1/pronunciation', [
            'audio' => UploadedFile::fake()->create('reading.wav', 16),
            'book_page_id' => $page->id,
        ]);
}

it('keeps every word Azure scored, in page order', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    fakeTimedAzure(timedWords(500, ['quick' => 'Mispronunciation', 'lazy' => 'Omission']));

    $response = submitTimedReading($this, $student, $page)->assertCreated();

    $words = $response->json('data.words');

    expect($words)->toHaveCount(10)
        ->and($words[0]['word'])->toBe('the')
        ->and($words[0]['word_index'])->toBe(0)
        // The word list is what the reader colours the page with, so the two
        // wrong ones have to be identifiable by name.
        ->and($words[1]['error_type'])->toBe('Mispronunciation')
        ->and($words[8]['error_type'])->toBe('Omission')
        // Offsets arrive in 100ns ticks and are stored as milliseconds, which
        // is what "play just this word back" needs.
        ->and($words[1]['offset_ms'])->toBe(1000);

    expect(PronunciationWord::count())->toBe(10);
});

it('measures pace from the words rather than the length of the recording', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    // The recording is six seconds long, but the reading inside it only runs
    // from the first word to the last — about five. Measured on the words, that
    // is 122 wpm, which is racing for a Level 1 reader.
    fakeTimedAzure(timedWords(500));

    $response = submitTimedReading($this, $student, $page)->assertCreated();

    expect($response->json('data.words_per_minute'))->toBe(122)
        ->and($response->json('data.pace'))->toBe('too_fast')
        ->and($response->json('meta.pace_hint'))->toContain('Slow down');
});

it('calls a word-by-word reader slow', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    fakeTimedAzure(timedWords(3000)); // ~20 wpm

    expect(submitTimedReading($this, $student, $page)->json('data.pace'))->toBe('too_slow');
});

it('leaves a steady reader alone', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    fakeTimedAzure(timedWords(1200)); // ~51 wpm, inside the Level 1 band

    $response = submitTimedReading($this, $student, $page)->assertCreated();

    expect($response->json('data.pace'))->toBe('good')
        ->and($response->json('meta.pace_hint'))->toBeNull();
});

it('judges pace against the level of the book, not one fixed target', function () {
    $pace = app(ReadingPaceService::class);

    // ~92 wpm is a Level 1 reader racing ahead and a Level 5 reader labouring.
    // The same number, three different verdicts.
    $words = pacedWords(667);

    expect($pace->evaluate($words, 'Level 1')['pace'])->toBe('too_fast')
        ->and($pace->evaluate($words, 'Level 5')['pace'])->toBe('too_slow')
        ->and($pace->evaluate($words, 'Level 3')['pace'])->toBe('good')
        // No level on the book falls back to a wide band rather than guessing.
        ->and($pace->evaluate($words, null)['pace'])->toBe('good');
});

it('says nothing about pace when there is too little to go on', function () {
    $pace = app(ReadingPaceService::class);

    expect($pace->evaluate([], 'Level 1'))->toEqual(['words_per_minute' => null, 'pace' => null]);

    // Four words is not a reading, however fast they came out.
    expect($pace->evaluate(array_slice(pacedWords(500), 0, 4), 'Level 1')['pace'])->toBeNull();

    // Words with no timings at all — Azure does sometimes leave them off.
    $untimed = array_map(
        fn (array $word) => ['word' => $word['word'], 'error_type' => 'None'],
        pacedWords(500),
    );
    expect($pace->evaluate($untimed, 'Level 1')['pace'])->toBeNull();
});

it('records intonation alongside the other dimensions', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    fakeTimedAzure(timedWords(1200));

    expect(submitTimedReading($this, $student, $page)->json('data.prosody_score'))->toEqual(81.5);
});

it('tells the pupil what they just earned', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    fakeTimedAzure(timedWords(1200));

    $response = submitTimedReading($this, $student, $page)->assertCreated();

    // Reading the page finishes it, and that is worth a modal.
    expect($response->json('celebrations.milestone'))->toBe('page_completed')
        ->and($response->json('meta.pass_mark'))->toBe(60);
});

it('names the words a pupil keeps getting wrong', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $page = wordPage($student);

    // The same word fails three readings running.
    foreach (range(1, 3) as $ignored) {
        fakeTimedAzure(timedWords(1200, ['quick' => 'Mispronunciation', 'lazy' => 'Omission']));
        submitTimedReading($this, $student, $page)->assertCreated();
    }

    $report = $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/students/{$student->id}/reading-report")
        ->assertOk()
        ->json('data');

    $missed = collect($report['missed_words'])->keyBy('word');

    expect($missed->keys()->all())->toContain('quick', 'lazy')
        ->and($missed['quick']['times_missed'])->toBe(3)
        ->and($report['summary']['total_attempts'])->toBe(3)
        // The band the child is being measured against belongs on the report;
        // a wpm number alone means nothing to a teacher.
        ->and($report['pace_band'])->toEqual(['min_wpm' => 30, 'max_wpm' => 70]);
});

it('asks Azure for phonemes and scores diction from them', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    $words = timedWords(1200, ['lazy' => 'Omission']);
    // Every spoken word's sounds come back at 80 and 60, bar "fox", which has
    // none and falls back to its word accuracy of 95.
    foreach ($words as $index => $word) {
        if ($word['Word'] === 'fox') {
            continue;
        }
        $words[$index]['Phonemes'] = [
            ['Phoneme' => 'a', 'PronunciationAssessment' => ['AccuracyScore' => 80.0]],
            ['Phoneme' => 'b', 'PronunciationAssessment' => ['AccuracyScore' => 60.0]],
        ];
    }
    // An omitted word was never said, so its (terrible) phonemes must not count.
    $words[8]['Phonemes'] = [['Phoneme' => 'l', 'PronunciationAssessment' => ['AccuracyScore' => 0.0]]];

    fakeTimedAzure($words);

    $response = submitTimedReading($this, $student, $page)->assertCreated();

    // Eight spoken words x two phonemes averaging 70, plus "fox" at 95:
    // (16 * 70 + 95) / 17.
    expect($response->json('data.diction_score'))->toEqual(71.47)
        // Accuracy is still Azure's own figure — diction sits beside it.
        ->and($response->json('data.accuracy_score'))->toEqual(92);

    Http::assertSent(function (Request $request) {
        if (! $request->hasHeader('Pronunciation-Assessment')) {
            return false;
        }
        $config = json_decode(base64_decode($request->header('Pronunciation-Assessment')[0]), true);

        return $config['Granularity'] === 'Phoneme';
    });
});

it('falls back to word accuracy for diction when Azure sends no phonemes', function () {
    $student = makeStudent(makeTeacher());
    $page = wordPage($student);

    // Nine words said at 95; the omitted one was never said and does not count.
    fakeTimedAzure(timedWords(1200, ['lazy' => 'Omission']));

    expect(submitTimedReading($this, $student, $page)->json('data.diction_score'))->toEqual(95);
});

it('averages diction on the reading report', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $page = wordPage($student);

    fakeTimedAzure(timedWords(1200));
    submitTimedReading($this, $student, $page)->assertCreated();

    $report = $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/students/{$student->id}/reading-report")
        ->assertOk()
        ->json('data');

    expect($report['summary']['average_diction'])->toEqual(95)
        ->and($report['attempts'][0]['diction_score'])->toEqual(95)
        ->and($report['trend'][0]['diction'])->toEqual(95);
});

it('keeps a word the pupil fixed on a retry without changing the score', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $page = wordPage($student);

    fakeTimedAzure(timedWords(1200, ['quick' => 'Mispronunciation']));
    $attempt = submitTimedReading($this, $student, $page)->assertCreated()->json('data');
    $quick = collect($attempt['words'])->firstWhere('word', 'quick');

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pronunciation/{$attempt['id']}/words/{$quick['id']}/retry", ['accuracy' => 88])
        ->assertOk()
        ->assertJsonPath('data.is_corrected', true)
        ->assertJsonPath('data.retry_accuracy', 88);

    $stored = PronunciationAttempt::with('words')->find($attempt['id']);
    $word = $stored->words->firstWhere('word', 'quick');

    expect($word->retried_at)->not->toBeNull()
        // The reading earned what it earned; the fix is extra information.
        ->and($stored->pron_score)->toEqual($attempt['pron_score'])
        ->and($word->error_type)->toBe('Mispronunciation');

    // A weak second go is kept, but does not count as corrected.
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pronunciation/{$attempt['id']}/words/{$quick['id']}/retry", ['accuracy' => 40])
        ->assertOk()
        ->assertJsonPath('data.is_corrected', false);

    // The teacher sees the retry on the attempt.
    $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/pronunciation/{$attempt['id']}")
        ->assertOk()
        ->assertJsonPath('data.words.1.retry_accuracy', 40)
        ->assertJsonPath('data.words.1.is_corrected', false);
});

it("will not record a retry on another pupil's reading or for a word from another one", function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $other = makeStudent($teacher);
    $page = wordPage($student);

    fakeTimedAzure(timedWords(1200));
    $attempt = submitTimedReading($this, $student, $page)->assertCreated()->json('data');
    $second = submitTimedReading($this, $student, $page)->assertCreated()->json('data');
    $wordId = $attempt['words'][0]['id'];

    $this->withHeaders(studentHeaders($other))
        ->postJson("/api/v1/student/pronunciation/{$attempt['id']}/words/{$wordId}/retry", ['accuracy' => 90])
        ->assertForbidden();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pronunciation/{$second['id']}/words/{$wordId}/retry", ['accuracy' => 90])
        ->assertNotFound();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pronunciation/{$attempt['id']}/words/{$wordId}/retry", ['accuracy' => 140])
        ->assertUnprocessable();
});
