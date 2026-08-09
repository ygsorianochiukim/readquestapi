<?php

use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\Student\Models\Student;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against a fresh in-memory SQLite database (see phpunit.xml),
| so every test starts from an empty schema.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Fixture builders shared by the feature tests. They mirror what the seeder
| creates, but small enough to keep each test readable.
|
*/

function makeTeacher(array $overrides = []): Teachers
{
    static $sequence = 0;
    $sequence++;

    return Teachers::create(array_merge([
        'first_name' => 'Test',
        'last_name' => "Teacher{$sequence}",
        'email' => "teacher{$sequence}@readquest.test",
        'password' => 'password',
        'status' => 'active',
    ], $overrides));
}

function makeStudent(Teachers $teacher, array $overrides = []): Student
{
    static $sequence = 0;
    $sequence++;

    return Student::create(array_merge([
        'teacher_id' => $teacher->id,
        'first_name' => 'Test',
        'last_name' => "Pupil{$sequence}",
        'username' => "pupil{$sequence}",
        'password' => 'password',
        'reading_level' => 'Level 1',
        'status' => 'active',
    ], $overrides));
}

/** A book with the given number of chapters, each carrying story text. */
function makeBook(int $chapterCount = 2, array $overrides = []): Book
{
    static $sequence = 0;
    $sequence++;

    $book = Book::create(array_merge([
        'title' => "Test Book {$sequence}",
        'description' => 'A book used in tests.',
        'reading_level' => 'Level 1',
        'sequence' => $sequence,
        'status' => 'active',
    ], $overrides));

    for ($number = 1; $number <= $chapterCount; $number++) {
        Chapter::create([
            'book_id' => $book->id,
            'chapter_number' => $number,
            'title' => "Chapter {$number}",
            'story_text' => 'The quick brown fox jumps over the lazy dog near the old school house.',
        ]);
    }

    return $book->refresh();
}

/** Attach a single multiple-choice question to a chapter. */
function makeQuizQuestion(Chapter $chapter, string $correct = 'Yes'): QuizQuestion
{
    return QuizQuestion::create([
        'chapter_id' => $chapter->id,
        'question_text' => 'Did the fox jump?',
        'choices' => [$correct, 'No', 'Maybe', 'Never'],
        'correct_answer' => $correct,
    ]);
}

function assignBook(Student $student, Book $book): void
{
    $student->books()->syncWithoutDetaching([$book->id => ['assigned_at' => now()]]);
}

/**
 * Bearer-token headers for a teacher.
 *
 * The guard caches the user it resolved for the first request of a test, so we
 * forget it here — otherwise a second request made as somebody else would still
 * be authenticated as the first one.
 */
function teacherHeaders(Teachers $teacher): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$teacher->createToken('test')->plainTextToken];
}

/** Bearer-token headers for a student (see teacherHeaders on guard caching). */
function studentHeaders(Student $student): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$student->createToken('test')->plainTextToken];
}
