<?php

use App\Domain\Progress\Services\ProgressService;

it('locks a chapter until the previous one is finished', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(2);
    assignBook($student, $book);

    [$first, $second] = $book->chapters()->orderBy('chapter_number')->get()->all();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$first->id}/story-read")
        ->assertOk();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$second->id}/story-read")
        ->assertStatus(403);
});

it('refuses activities on a book that was never assigned', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    $chapter = $book->chapters()->first();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/story-read")
        ->assertStatus(403);
});

it('grades a quiz server-side and keeps the best score', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);

    $chapter = $book->chapters()->first();
    $question = makeQuizQuestion($chapter, 'Yes');

    // A wrong answer scores 0 and does not pass.
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz", [
            'answers' => [$question->id => 'No'],
        ])
        ->assertOk()
        ->assertJsonPath('data.score', 0)
        ->assertJsonPath('data.passed', false);

    // The right answer scores 100 and passes.
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz", [
            'answers' => [$question->id => 'Yes'],
        ])
        ->assertOk()
        ->assertJsonPath('data.score', 100)
        ->assertJsonPath('data.passed', true);

    // Re-submitting a worse answer must not lower the recorded score.
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz", [
            'answers' => [$question->id => 'No'],
        ])->assertOk();

    expect($student->progress()->first()->quiz_score)->toBe(100);
});

it('never leaks the correct answer to the student', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);

    $chapter = $book->chapters()->first();
    makeQuizQuestion($chapter, 'Yes');

    $response = $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/chapters/{$chapter->id}/quiz")
        ->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('correct_answer');
});

it('completes a chapter only when all four activities are done, then unlocks the next', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(2);
    assignBook($student, $book);

    [$first, $second] = $book->chapters()->orderBy('chapter_number')->get()->all();
    $question = makeQuizQuestion($first, 'Yes');
    $headers = studentHeaders($student);

    $this->withHeaders($headers)->postJson("/api/v1/student/chapters/{$first->id}/story-read")->assertOk();
    $this->withHeaders($headers)->postJson("/api/v1/student/chapters/{$first->id}/game")->assertOk();
    $this->withHeaders($headers)->postJson("/api/v1/student/chapters/{$first->id}/quiz", [
        'answers' => [$question->id => 'Yes'],
    ])->assertOk();

    // Still incomplete: the read-aloud has not been passed yet.
    expect($student->progress()->where('chapter_id', $first->id)->first()->status)->toBe('in_progress');

    // Scoring a read-aloud is the only step that needs Azure, so drive it through
    // the service the way PronunciationService does once a score comes back.
    app(ProgressService::class)->recordPronunciation($student, $first, 95.0);

    expect($student->progress()->where('chapter_id', $first->id)->first()->status)->toBe('completed');

    // The next chapter is now reachable.
    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$second->id}/story-read")
        ->assertOk();
});
