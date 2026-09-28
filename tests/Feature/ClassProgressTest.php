<?php

use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Models\ReadingProgress;
use App\Domain\Student\Models\Student;

/** Mark a chapter finished for a pupil, the way the learning loop would. */
function finishChapter(Student $student, Chapter $chapter): void
{
    ReadingProgress::create([
        'student_id' => $student->id,
        'chapter_id' => $chapter->id,
        'status' => 'completed',
        'is_unlocked' => true,
        'story_read' => true,
        'pronunciation_passed' => true,
        'game_completed' => true,
        'quiz_passed' => true,
        'completed_at' => now(),
    ]);
}

it('shows a teacher how far their class has got through each book', function () {
    $teacher = makeTeacher();
    $ahead = makeStudent($teacher);
    $behind = makeStudent($teacher);

    $book = makeBook(2);
    assignBook($ahead, $book);
    assignBook($behind, $book);

    // One pupil has finished the book; the other is halfway.
    finishChapter($ahead, $book->chapters[0]);
    finishChapter($ahead, $book->chapters[1]);
    finishChapter($behind, $book->chapters[0]);

    $progress = $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/books')
        ->assertOk()
        ->json("progress.{$book->id}");

    expect($progress['assigned'])->toBe(2)
        ->and($progress['completed'])->toBe(1)
        // (100% + 50%) / 2 pupils.
        ->and($progress['percent'])->toBe(75);
});

it('counts a pupil who has not started as zero, not as absent', function () {
    $teacher = makeTeacher();
    $started = makeStudent($teacher);
    $untouched = makeStudent($teacher);

    $book = makeBook(2);
    assignBook($started, $book);
    assignBook($untouched, $book);

    finishChapter($started, $book->chapters[0]);
    finishChapter($started, $book->chapters[1]);

    $progress = $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/books')
        ->json("progress.{$book->id}");

    // Dropping the pupil who never opened it would report this book as done.
    expect($progress['percent'])->toBe(50)
        ->and($progress['completed'])->toBe(1);
});

it("does not count another teacher's pupils", function () {
    $mine = makeTeacher();
    $myPupil = makeStudent($mine);
    $theirPupil = makeStudent(makeTeacher());

    $book = makeBook(1);
    assignBook($myPupil, $book);
    assignBook($theirPupil, $book);

    finishChapter($theirPupil, $book->chapters[0]);

    $progress = $this->withHeaders(teacherHeaders($mine))
        ->getJson('/api/v1/books')
        ->json("progress.{$book->id}");

    expect($progress['assigned'])->toBe(1)
        ->and($progress['completed'])->toBe(0)
        ->and($progress['percent'])->toBe(0);
});

it('names the chapter a class is stuck on', function () {
    $teacher = makeTeacher();
    $book = makeBook(2);

    $pupils = collect(range(1, 4))->map(function () use ($teacher, $book) {
        $student = makeStudent($teacher);
        assignBook($student, $book);

        return $student;
    });

    // Everyone cleared chapter one; only one got through chapter two.
    $pupils->each(fn (Student $student) => finishChapter($student, $book->chapters[0]));
    finishChapter($pupils->first(), $book->chapters[1]);

    $progress = $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/books/{$book->id}/chapters")
        ->assertOk()
        ->json('progress');

    expect($progress[$book->chapters[0]->id]['percent'])->toBe(100)
        ->and($progress[$book->chapters[1]->id]['completed'])->toBe(1)
        ->and($progress[$book->chapters[1]->id]['percent'])->toBe(25);
});

it('reports nothing rather than failing for a teacher with no pupils', function () {
    $teacher = makeTeacher();
    makeBook(2);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/books')
        ->assertOk();

    expect($response->json('progress'))->toBe([]);
});
