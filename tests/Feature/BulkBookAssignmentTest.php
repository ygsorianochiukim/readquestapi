<?php

use App\Domain\SystemLog\Models\SystemLog;

it('assigns several books to several pupils at once, keeping what they already had', function () {
    $teacher = makeTeacher();
    $first = makeStudent($teacher);
    $second = makeStudent($teacher);

    $kept = makeBook(1);
    $bookA = makeBook(1);
    $bookB = makeBook(1);
    assignBook($first, $kept);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/students/books/assign', [
            'book_ids' => [$bookA->id, $bookB->id],
            'student_ids' => [$first->id, $second->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.books', 2)
        ->assertJsonPath('data.students', 2)
        ->assertJsonPath('data.newly_assigned', 4);

    expect($first->books()->pluck('books.id')->sort()->values()->all())
        ->toBe(collect([$kept->id, $bookA->id, $bookB->id])->sort()->values()->all())
        ->and($second->books()->count())->toBe(2);

    $log = SystemLog::where('action', 'books.bulk_assigned')->first();

    expect($log)->not->toBeNull()
        ->and($log->teacher_id)->toBe($teacher->id)
        ->and($log->description)->toContain('2 pupils');
});

it('does not assign a book twice', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/students/books/assign', [
            'book_ids' => [$book->id],
            'student_ids' => [$student->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.newly_assigned', 0);

    expect($student->books()->count())->toBe(1);
});

it('refuses to assign books to another teacher\'s pupils', function () {
    $teacher = makeTeacher();
    $otherStudent = makeStudent(makeTeacher());
    $book = makeBook(1);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/students/books/assign', [
            'book_ids' => [$book->id],
            'student_ids' => [$otherStudent->id],
        ])
        ->assertForbidden();

    expect($otherStudent->books()->count())->toBe(0);
});

it('needs at least one book and one pupil', function () {
    $this->withHeaders(teacherHeaders(makeTeacher()))
        ->postJson('/api/v1/students/books/assign', ['book_ids' => [], 'student_ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['book_ids', 'student_ids']);
});
