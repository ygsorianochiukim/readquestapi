<?php

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\QuizQuestion\Services\QuizGeneratorService;

/*
| Quizzes are written from the chapter's own text; teachers review, correct,
| delete or regenerate them instead of typing them.
*/

const MARKET_STORY = 'Maria walked to the busy market with her little brother Paolo. '
    .'Paolo carried a woven basket on his shoulder. '
    .'They bought fresh mangoes, sweet bananas and a round pumpkin. '
    .'Maria counted the coins carefully before paying the friendly vendor. '
    .'On the way home, the children rested under a shady acacia tree. '
    .'Their mother cooked the pumpkin for dinner that evening.';

it('writes multiple-choice questions from the story text', function () {
    $questions = app(QuizGeneratorService::class)->build(MARKET_STORY);

    expect($questions)->not->toBeEmpty()
        ->and(count($questions))->toBeLessThanOrEqual(QuizGeneratorService::MAX_QUESTIONS);

    foreach ($questions as $question) {
        expect($question['choices'])->toContain($question['correct_answer'])
            ->and(count($question['choices']))->toBeGreaterThanOrEqual(2)
            // Every choice is different, or grading by text would be ambiguous.
            ->and(array_unique($question['choices']))->toHaveCount(count($question['choices']));
    }

    // A blank to fill, and a question about who did something.
    expect(collect($questions)->pluck('question_text')->implode("\n"))
        ->toContain('_____')
        ->toContain('Who ');
});

it('writes the same quiz for the same text every time', function () {
    $generator = app(QuizGeneratorService::class);

    expect($generator->build(MARKET_STORY))->toEqual($generator->build(MARKET_STORY));
});

it('writes nothing for a chapter with no text', function () {
    expect(app(QuizGeneratorService::class)->build('   '))->toBe([]);
});

it('regenerates on request but keeps the questions a teacher corrected', function () {
    $teacher = makeTeacher();
    $book = makeBook(1);
    $chapter = $book->chapters()->first();
    $chapter->update(['story_text' => MARKET_STORY]);

    $generated = $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/chapters/{$chapter->id}/quiz-questions/generate")
        ->assertOk()
        ->json('data');

    expect($generated)->not->toBeEmpty();

    // The teacher corrects one question: from then on it is theirs.
    $edited = QuizQuestion::find($generated[0]['id']);
    $this->withHeaders(teacherHeaders($teacher))
        ->putJson("/api/v1/quiz-questions/{$edited->id}", ['question_text' => 'Who went to the market?'])
        ->assertOk();

    expect($edited->refresh()->is_generated)->toBeFalse();

    $again = $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/chapters/{$chapter->id}/quiz-questions/generate")
        ->assertOk()
        ->json('data');

    expect(collect($again)->pluck('id'))->toContain($edited->id)
        ->and(collect($again)->firstWhere('id', $edited->id)['question_text'])->toBe('Who went to the market?');
});

it('rewrites the generated quiz when the scanned text is corrected', function () {
    $teacher = makeTeacher();
    $chapter = makeBook(1)->chapters()->first();
    app(QuizGeneratorService::class)->regenerate($chapter);
    $before = $chapter->quizQuestions()->pluck('question_text')->all();

    $this->withHeaders(teacherHeaders($teacher))
        ->putJson("/api/v1/chapters/{$chapter->id}", ['story_text' => MARKET_STORY])
        ->assertOk();

    $after = $chapter->quizQuestions()->pluck('question_text')->all();

    expect($after)->not->toBeEmpty()->and($after)->not->toEqual($before);
});

it('leaves a teacher-written quiz alone when the text changes', function () {
    $teacher = makeTeacher();
    $chapter = makeBook(1)->chapters()->first();
    makeQuizQuestion($chapter);

    $this->withHeaders(teacherHeaders($teacher))
        ->putJson("/api/v1/chapters/{$chapter->id}", ['story_text' => MARKET_STORY])
        ->assertOk();

    expect($chapter->quizQuestions()->count())->toBe(1);
});

it('no longer takes a typed-in question', function () {
    $teacher = makeTeacher();
    $chapter = makeBook(1)->chapters()->first();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/chapters/{$chapter->id}/quiz-questions", [
            'question_text' => 'Typed?',
            'choices' => ['Yes', 'No'],
            'correct_answer' => 'Yes',
        ])
        ->assertStatus(405);
});

it('writes a quiz for each chapter of a reader published from an upload', function () {
    $teacher = makeTeacher();
    $response = uploadPicturePages($this, $teacher, [
        "Chapter 1\n".MARKET_STORY,
        "Chapter 2\nThe rain fell softly on the tin roof of the little house near the river.",
    ]);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/ingest/{$response->id}/commit", ['as_chapters' => true])
        ->assertOk();

    $chapters = Chapter::where('book_id', $response->book_id)->orderBy('chapter_number')->get();

    expect($chapters)->toHaveCount(2)
        ->and($chapters[0]->quizQuestions()->count())->toBeGreaterThan(0)
        // The scans each chapter was read from stay attached to it.
        ->and($chapters[0]->pages()->pluck('page_number')->all())->toEqual([1])
        ->and($chapters[1]->pages()->pluck('page_number')->all())->toEqual([2]);
});
