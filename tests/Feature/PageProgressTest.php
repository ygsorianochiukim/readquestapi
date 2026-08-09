<?php

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Progress\Services\PageProgressService;
use App\Domain\Progress\Services\ProgressService;

/** A scanned book with the given page texts (null = picture-only page). */
function makeScannedBook(array $texts, int $sequence = 1): Book
{
    $book = Book::create([
        'title' => "Scanned Book {$sequence}",
        'sequence' => $sequence,
        'status' => 'active',
        'type' => 'scanned',
    ]);

    foreach ($texts as $position => $text) {
        BookPage::create([
            'book_id' => $book->id,
            'page_number' => $position + 1,
            'text' => $text,
        ]);
    }

    return $book->refresh();
}

it('finishes a picture-only page as soon as the pupil marks it read', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeScannedBook([null]);
    assignBook($student, $book);

    $page = $book->pages()->first();

    $response = $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pages/{$page->id}/read")
        ->assertOk();

    expect($response->json('data.percent'))->toBe(100)
        ->and($response->json('data.is_completed'))->toBeTrue();
});

it('needs a passing read-aloud before a page with words counts as finished', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeScannedBook(['The fox jumped over the lazy dog.']);
    assignBook($student, $book);

    $page = $book->pages()->first();
    $pages = app(PageProgressService::class);

    // Marking it read is not enough on its own.
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pages/{$page->id}/read")
        ->assertOk()
        ->assertJsonPath('data.percent', 0);

    // A low score still does not finish it.
    $pages->recordPronunciation($student, $page, 41.0);
    expect($pages->forBook($student, $book)['percent'])->toBe(0);

    // A passing score does.
    $pages->recordPronunciation($student, $page, 88.0);
    $summary = $pages->forBook($student, $book);

    expect($summary['percent'])->toBe(100)
        ->and($summary['is_completed'])->toBeTrue()
        ->and($summary['pages'][0]['best_score'])->toBe(88);
});

it('counts a passing read-aloud as having read the page', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeScannedBook(['The fox jumped over the lazy dog.']);
    assignBook($student, $book);

    // No "mark as read" tap at all — reading it aloud is reading it.
    app(PageProgressService::class)->recordPronunciation($student, $book->pages()->first(), 75.0);

    expect(app(PageProgressService::class)->forBook($student, $book)['is_completed'])->toBeTrue();
});

it('shows page progress as the book percent in the library', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeScannedBook([null, null, null, null]);
    assignBook($student, $book);

    $pages = app(PageProgressService::class);
    foreach ($book->pages()->take(3)->get() as $page) {
        $pages->markRead($student, $page);
    }

    $overview = collect(app(ProgressService::class)->overviewForStudent($student->refresh()))
        ->firstWhere('id', $book->id);

    expect($overview['percent'])->toBe(75)
        ->and($overview['completed_pages'])->toBe(3)
        ->and($overview['total_pages'])->toBe(4)
        ->and($overview['is_completed'])->toBeFalse();
});

it('refuses page progress on a book the pupil was never assigned', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeScannedBook([null]);

    $page = $book->pages()->first();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/pages/{$page->id}/read")
        ->assertStatus(403);

    $this->withHeaders(studentHeaders($student))
        ->getJson("/api/v1/student/books/{$book->id}/pages")
        ->assertStatus(403);
});
