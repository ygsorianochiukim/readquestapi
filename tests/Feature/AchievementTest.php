<?php

use App\Domain\Achievement\Models\Achievement;
use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Progress\Services\ProgressService;
use Database\Seeders\AchievementSeeder;

beforeEach(function () {
    $this->seed(AchievementSeeder::class);
});

it('shows a student their milestones with progress toward each one', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);

    $response = $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/student/achievements')
        ->assertOk();

    expect($response->json('data.total'))->toBe(Achievement::count())
        ->and($response->json('data.unlocked'))->toBe(0)
        ->and($response->json('data.achievements.0'))
        ->toHaveKeys(['name', 'metric', 'threshold', 'current', 'percent', 'is_unlocked']);
});

it('unlocks a milestone once its threshold is reached and awards its points', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();

    expect($student->refresh()->points)->toBe(0);

    // "Page Turner" unlocks on the first story read (5 points).
    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/story-read")
        ->assertOk();

    $pageTurner = Achievement::where('code', 'story_first')->first();

    expect($student->refresh()->achievements()->pluck('code'))->toContain('story_first')
        ->and($student->points)->toBeGreaterThanOrEqual($pageTurner->points);
});

it('does not unlock the same milestone twice', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(2);
    assignBook($student, $book);

    $service = app(AchievementService::class);
    $chapter = $book->chapters()->first();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/story-read")
        ->assertOk();

    $pointsAfterFirstUnlock = $student->refresh()->points;

    // Syncing again must be a no-op.
    expect($service->sync($student))->toBeEmpty()
        ->and($student->refresh()->points)->toBe($pointsAfterFirstUnlock)
        ->and($student->achievements()->where('code', 'story_first')->count())->toBe(1);
});

it('counts a finished book toward the book milestones', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);

    $chapter = $book->chapters()->first();
    $progress = app(ProgressService::class);

    $progress->markStoryRead($student, $chapter);
    $progress->markGameCompleted($student, $chapter);
    $progress->recordPronunciation($student, $chapter, 95.0);

    $metrics = app(AchievementService::class)->metricsFor($student->refresh());

    expect($metrics['chapters_completed'])->toBe(1)
        ->and($metrics['books_completed'])->toBe(1)
        ->and($student->achievements()->pluck('code'))->toContain('book_first');
});

it('lets the owning teacher read a student\'s milestones but not another teacher\'s', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $otherTeacher = makeTeacher();

    $this->withHeaders(teacherHeaders($teacher))
        ->getJson("/api/v1/students/{$student->id}/achievements")
        ->assertOk()
        ->assertJsonPath('data.total', Achievement::count());

    $this->withHeaders(teacherHeaders($otherTeacher))
        ->getJson("/api/v1/students/{$student->id}/achievements")
        ->assertStatus(403);
});
