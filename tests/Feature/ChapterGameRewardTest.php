<?php

use App\Domain\Progress\Models\ChapterGameResult;
use App\Domain\Progress\Services\ProgressService;
use Database\Seeders\AchievementSeeder;

it('pays points for the first win of each game type, with a bonus for no mistakes', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();
    $headers = studentHeaders($student);

    $perfect = ProgressService::GAME_POINTS + ProgressService::GAME_PERFECT_BONUS;

    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$chapter->id}/game", ['game_type' => 'scramble', 'mistakes' => 0])
        ->assertOk()
        ->assertJsonPath('data.game_completed', true)
        ->assertJsonPath('game.points_earned', $perfect)
        ->assertJsonPath('game.first_completion', true)
        ->assertJsonPath('game.perfect', true);

    // A different game on the same chapter is its own first win.
    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$chapter->id}/game", ['game_type' => 'missing-word', 'mistakes' => 2])
        ->assertOk()
        ->assertJsonPath('game.points_earned', ProgressService::GAME_POINTS)
        ->assertJsonPath('game.perfect', false);

    expect($student->refresh()->points)->toBe($perfect + ProgressService::GAME_POINTS);
});

it('does not pay again for replaying a game already won', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();
    $headers = studentHeaders($student);

    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$chapter->id}/game", ['game_type' => 'sentence-builder', 'mistakes' => 1])
        ->assertOk();
    $pointsAfterFirst = $student->refresh()->points;

    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$chapter->id}/game", ['game_type' => 'sentence-builder', 'mistakes' => 0])
        ->assertOk()
        ->assertJsonPath('game.points_earned', 0)
        ->assertJsonPath('game.first_completion', false);

    expect($student->refresh()->points)->toBe($pointsAfterFirst)
        ->and(ChapterGameResult::where('student_id', $student->id)->count())->toBe(1);

    $this->withHeaders($headers)
        ->getJson("/api/v1/student/chapters/{$chapter->id}/games")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.game_type', 'sentence-builder');
});

it('rejects an unknown game type', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/game", ['game_type' => 'tetris'])
        ->assertStatus(422);
});

it('lets game points unlock a points milestone on the same request', function () {
    $this->seed(AchievementSeeder::class);

    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $student->forceFill(['points' => 95])->save();
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();

    $response = $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/game", ['game_type' => 'scramble', 'mistakes' => 3])
        ->assertOk();

    expect(collect($response->json('celebrations.achievements'))->pluck('code'))->toContain('points_100')
        ->and($student->refresh()->achievements()->pluck('code'))->toContain('points_100');
});

it('checks a single quiz answer without recording anything', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();
    $question = makeQuizQuestion($chapter, 'Yes');
    $headers = studentHeaders($student);

    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz/check", ['question_id' => $question->id, 'answer' => 'No'])
        ->assertOk()
        ->assertJsonPath('data.correct', false)
        ->assertJsonPath('data.correct_answer', 'Yes');

    $this->withHeaders($headers)
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz/check", ['question_id' => $question->id, 'answer' => 'Yes'])
        ->assertOk()
        ->assertJsonPath('data.correct', true);

    expect($student->progress()->count())->toBe(0);
});

it('refuses to check a question from another chapter', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();
    $foreign = makeQuizQuestion(makeBook(1)->chapters()->first(), 'Yes');

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz/check", ['question_id' => $foreign->id, 'answer' => 'Yes'])
        ->assertNotFound();
});

it('returns per-question results with the graded quiz', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);
    $chapter = $book->chapters()->first();
    $right = makeQuizQuestion($chapter, 'Yes');
    $wrong = makeQuizQuestion($chapter, 'Yes');

    $response = $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz", [
            'answers' => [$right->id => 'Yes', $wrong->id => 'No'],
        ])
        ->assertOk()
        ->assertJsonPath('data.score', 50);

    $review = collect($response->json('data.review'))->keyBy('question_id');

    expect($review[$right->id])->toMatchArray(['selected' => 'Yes', 'correct' => true, 'correct_answer' => 'Yes'])
        ->and($review[$wrong->id])->toMatchArray(['selected' => 'No', 'correct' => false, 'correct_answer' => 'Yes']);
});
