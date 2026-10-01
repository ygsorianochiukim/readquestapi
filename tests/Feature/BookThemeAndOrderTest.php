<?php

use App\Domain\Book\Models\Book;

/*
| Books are read in a theme the teacher picks (a chapter may pick its own),
| and in the order the teacher arranges them.
*/

it('lets a teacher give a book and a chapter their own theme', function () {
    $teacher = makeTeacher();
    $book = makeBook(2);
    $chapter = $book->chapters->first();

    $this->withHeaders(teacherHeaders($teacher))
        ->putJson("/api/v1/books/{$book->id}", ['theme' => 'jungle'])
        ->assertOk()
        ->assertJsonPath('data.theme', 'jungle');

    $this->withHeaders(teacherHeaders($teacher))
        ->putJson("/api/v1/chapters/{$chapter->id}", ['theme' => 'halloween'])
        ->assertOk()
        ->assertJsonPath('data.theme', 'halloween');

    $student = makeStudent($teacher);
    assignBook($student, $book);

    $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$book->id}/progress")
        ->assertOk()
        ->assertJsonPath('data.theme', 'jungle')
        ->assertJsonPath('data.chapters.0.theme', 'halloween')
        ->assertJsonPath('data.chapters.1.theme', null);

    $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/student/progress')
        ->assertOk()
        ->assertJsonPath('data.0.theme', 'jungle');
});

it('refuses a theme that does not exist', function () {
    $book = makeBook(1);

    $this->withHeaders(teacherHeaders(makeTeacher()))
        ->putJson("/api/v1/books/{$book->id}", ['theme' => 'volcano'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('theme');
});

it('clears a chapter theme so it falls back to the book', function () {
    $book = makeBook(1);
    $chapter = $book->chapters->first();
    $chapter->update(['theme' => 'space']);

    $this->withHeaders(teacherHeaders(makeTeacher()))
        ->putJson("/api/v1/chapters/{$chapter->id}", ['theme' => null])
        ->assertOk()
        ->assertJsonPath('data.theme', null);
});

it('arranges the books in the order the teacher gives', function () {
    $teacher = makeTeacher();
    $first = makeBook(1);
    $second = makeBook(1);
    $third = makeBook(1);

    $this->withHeaders(teacherHeaders($teacher))
        ->putJson('/api/v1/books/order', ['book_ids' => [$third->id, $first->id, $second->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $third->id)
        ->assertJsonPath('data.1.id', $first->id)
        ->assertJsonPath('data.2.id', $second->id);

    expect(Book::find($third->id)->sequence)->toBe(1)
        ->and(Book::find($first->id)->sequence)->toBe(2)
        ->and(Book::find($second->id)->sequence)->toBe(3);

    // Pupils meet them in the new order, and only the new first book is open.
    $student = makeStudent($teacher);
    foreach ([$first, $second, $third] as $book) {
        assignBook($student, $book);
    }

    $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/student/progress')
        ->assertOk()
        ->assertJsonPath('data.0.id', $third->id)
        ->assertJsonPath('data.0.is_locked', false)
        ->assertJsonPath('data.1.is_locked', true);
});

it('keeps books left out of the order after the ones given', function () {
    $first = makeBook(1);
    $second = makeBook(1);
    $third = makeBook(1);

    $this->withHeaders(teacherHeaders(makeTeacher()))
        ->putJson('/api/v1/books/order', ['book_ids' => [$second->id]])
        ->assertOk();

    expect(Book::find($second->id)->sequence)->toBe(1)
        ->and(Book::find($first->id)->sequence)->toBe(2)
        ->and(Book::find($third->id)->sequence)->toBe(3);
});

it('refuses an order with a book listed twice', function () {
    $book = makeBook(1);

    $this->withHeaders(teacherHeaders(makeTeacher()))
        ->putJson('/api/v1/books/order', ['book_ids' => [$book->id, $book->id]])
        ->assertUnprocessable();
});

it('does not let a pupil reorder the books', function () {
    $book = makeBook(1);
    $student = makeStudent(makeTeacher());

    $this->withHeaders(studentHeaders($student))
        ->putJson('/api/v1/books/order', ['book_ids' => [$book->id]])
        ->assertForbidden();
});
