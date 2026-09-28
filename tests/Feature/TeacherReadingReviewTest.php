<?php

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Models\SystemLog;

/** A read-aloud attempt sitting in the teacher's queue, unreviewed. */
function attemptFor(Student $student, float $score = 42.0): PronunciationAttempt
{
    $book = Book::create([
        'title' => 'Review Book',
        'sequence' => 1,
        'status' => 'active',
        'type' => 'scanned',
    ]);

    assignBook($student, $book);

    $page = BookPage::create([
        'book_id' => $book->id,
        'page_number' => 1,
        'text' => 'the cat sat on the mat',
    ]);

    return PronunciationAttempt::create([
        'student_id' => $student->id,
        'book_page_id' => $page->id,
        'reference_text' => $page->text,
        'recognized_text' => 'the cat sat on the mat',
        'accuracy_score' => 40.0,
        'fluency_score' => 45.0,
        'pron_score' => $score,
        'text_match_score' => 100.0,
    ]);
}

it("lets a teacher overrule a score the machine got wrong", function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    // The microphone was poor; the child read it perfectly well.
    $attempt = attemptFor($student, 42.0);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", [
            'teacher_score' => 85,
            'teacher_note' => 'Noisy classroom — read it correctly to me.',
        ])
        ->assertOk();

    expect($response->json('data.teacher_score'))->toEqual(85.0)
        // The automatic score is kept, not overwritten: the teacher's judgement
        // sits on top of it and can be taken back.
        ->and($response->json('data.pron_score'))->toEqual(42.0)
        ->and($response->json('data.effective_score'))->toEqual(85.0)
        ->and($response->json('data.passed'))->toBeTrue();
});

it('makes an overridden score actually finish the page', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $attempt = attemptFor($student, 42.0);

    // Before: the automatic score failed, so the page is unfinished.
    $before = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$attempt->bookPage->book_id}/pages")
        ->json('data.pages.0');

    expect($before['pronunciation_passed'])->toBeFalse();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", ['teacher_score' => 85])
        ->assertOk();

    // After: the teacher's verdict has to reach progress too, or they would be
    // passing a child on the report while the app still blocks their next page.
    $after = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$attempt->bookPage->book_id}/pages")
        ->json('data.pages.0');

    expect($after['pronunciation_passed'])->toBeTrue()
        ->and($after['is_completed'])->toBeTrue();
});

it('lets a teacher take their override back', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $attempt = attemptFor($student, 42.0);

    $headers = teacherHeaders($teacher);

    $this->withHeaders($headers)
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", ['teacher_score' => 85])
        ->assertOk();

    $response = $this->withHeaders($headers)
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", ['teacher_score' => null])
        ->assertOk();

    expect($response->json('data.teacher_score'))->toBeNull()
        ->and($response->json('data.effective_score'))->toEqual(42.0);
});

it('records who changed a score and to what', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $attempt = attemptFor($student, 42.0);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", [
            'teacher_score' => 85,
            'teacher_note' => 'Noisy classroom.',
        ])
        ->assertOk();

    $log = SystemLog::where('action', 'pronunciation.overridden')->latest()->first();

    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('42%')
        ->and($log->description)->toContain('85%')
        ->and($log->description)->toContain('Noisy classroom.');
});

it('rejects a score that is not a score', function () {
    $teacher = makeTeacher();
    $attempt = attemptFor(makeStudent($teacher));

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", ['teacher_score' => 140])
        ->assertStatus(422);

    // Omitting the field entirely is not the same as sending null, and must
    // not be read as "clear it".
    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", [])
        ->assertStatus(422);
});

it("will not let a teacher touch another teacher's pupil", function () {
    $attempt = attemptFor(makeStudent(makeTeacher()));
    $outsider = makeTeacher();

    $this->withHeaders(teacherHeaders($outsider))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", ['teacher_score' => 100])
        ->assertForbidden();

    $this->withHeaders(teacherHeaders($outsider))
        ->getJson("/api/v1/pronunciation/{$attempt->id}")
        ->assertForbidden();
});

it('queues unreviewed readings for the whole class', function () {
    $teacher = makeTeacher();
    $one = makeStudent($teacher);
    $two = makeStudent($teacher);

    attemptFor($one, 42.0);
    attemptFor($two, 95.0);
    $reviewed = attemptFor($two, 50.0);

    $headers = teacherHeaders($teacher);

    $this->withHeaders($headers)
        ->postJson("/api/v1/pronunciation/{$reviewed->id}/validate")
        ->assertOk();

    $pending = $this->withHeaders($headers)
        ->getJson('/api/v1/pronunciation/queue?status=pending')
        ->assertOk();

    expect($pending->json('data'))->toHaveCount(2)
        ->and($pending->json('meta.pending'))->toBe(2);

    // The readings a teacher most wants to look at are the ones that failed.
    $failed = $this->withHeaders($headers)
        ->getJson('/api/v1/pronunciation/queue?only_failed=1&status=pending')
        ->assertOk();

    expect($failed->json('data'))->toHaveCount(1)
        ->and($failed->json('data.0.student_id'))->toBe($one->id);
});

it("keeps one teacher's queue out of another's", function () {
    $mine = makeTeacher();
    attemptFor(makeStudent($mine));
    attemptFor(makeStudent(makeTeacher()));

    $queue = $this->withHeaders(teacherHeaders($mine))
        ->getJson('/api/v1/pronunciation/queue')
        ->assertOk();

    expect($queue->json('data'))->toHaveCount(1);
});
