<?php

use App\Domain\Badge\Models\Badge;
use App\Domain\Book\Models\Book;
use App\Domain\Progress\Services\ProgressService;

/** A scanned/page-based book: no chapters, so nothing to "complete". */
function makePageBook(int $sequence): Book
{
    return Book::create([
        'title' => "Scanned Book {$sequence}",
        'sequence' => $sequence,
        'status' => 'active',
        'type' => 'scanned',
    ]);
}

it('does not let a page-based book lock the books assigned after it', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $scanned = makePageBook(1);
    $next = makeBook(2, ['sequence' => 2]);

    assignBook($student, $scanned);
    assignBook($student, $next);

    $overview = app(ProgressService::class)->overviewForStudent($student);

    expect(collect($overview)->firstWhere('title', $scanned->title)['is_locked'])->toBeFalse()
        ->and(collect($overview)->firstWhere('title', $next->title)['is_locked'])->toBeFalse();
});

it('still gates a book behind the previous chapter-based book', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $first = makeBook(1, ['sequence' => 1]);
    $scanned = makePageBook(2);
    $third = makeBook(1, ['sequence' => 3]);

    assignBook($student, $first);
    assignBook($student, $scanned);
    assignBook($student, $third);

    $progress = app(ProgressService::class);
    $locked = fn () => collect($progress->overviewForStudent($student->refresh()))
        ->firstWhere('title', $third->title)['is_locked'];

    // The unfinished first book still blocks the third, page-based book between
    // them notwithstanding.
    expect($locked())->toBeTrue();

    // Finish the first book and the third opens up.
    $chapter = $first->chapters()->first();
    $progress->markStoryRead($student, $chapter);
    $progress->markGameCompleted($student, $chapter);
    $progress->recordPronunciation($student, $chapter, 95.0);

    expect($locked())->toBeFalse();
});

it('awards Reading Star from the chapter-based books alone', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    Badge::create([
        'name' => 'Reading Star',
        'description' => 'Finished all books.',
        'criteria' => 'Complete every book',
        'points' => 100,
    ]);

    $book = makeBook(1, ['sequence' => 1]);
    $scanned = makePageBook(2);
    assignBook($student, $book);
    assignBook($student, $scanned);

    $progress = app(ProgressService::class);
    $chapter = $book->chapters()->first();
    $progress->markStoryRead($student, $chapter);
    $progress->markGameCompleted($student, $chapter);
    $progress->recordPronunciation($student, $chapter, 95.0);

    expect($student->refresh()->badges()->pluck('name'))->toContain('Reading Star');
});
