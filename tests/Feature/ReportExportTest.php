<?php

use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Repositories\PronunciationRepository;
use App\Domain\SystemLog\Models\SystemLog;
use Database\Seeders\AchievementSeeder;

beforeEach(function () {
    $this->seed(AchievementSeeder::class);
});

it('exports a class report as CSV', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher, ['first_name' => 'Liza', 'last_name' => 'Santos']);
    $book = makeBook(2);
    assignBook($student, $book);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->get('/api/v1/reports/class.csv')
        ->assertOk();

    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Student,Username,"Reading level"')
        ->and($csv)->toContain('Liza Santos')
        ->and(SystemLog::where('action', 'report.exported')->count())->toBe(1);
});

it('exports one student report with chapter, badge and achievement sections', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(2);
    assignBook($student, $book);

    $csv = $this->withHeaders(teacherHeaders($teacher))
        ->get("/api/v1/reports/students/{$student->id}.csv")
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('ReadQuest — Student Progress Report')
        ->and($csv)->toContain('CHAPTER PROGRESS')
        ->and($csv)->toContain('PRONUNCIATION ATTEMPTS')
        ->and($csv)->toContain('BADGES EARNED')
        ->and($csv)->toContain('ACHIEVEMENTS')
        ->and($csv)->toContain('Chapter 1');
});

it('refuses to export a report for another teacher\'s student', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $otherTeacher = makeTeacher();

    $this->withHeaders(teacherHeaders($otherTeacher))
        ->getJson("/api/v1/reports/students/{$student->id}.csv")
        ->assertStatus(403);
});

it('exports the score that counts and the words each reading missed', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $attempt = PronunciationAttempt::create([
        'student_id' => $student->id,
        'reference_text' => 'the cat sat on the mat',
        'recognized_text' => 'the cat sat on the',
        'accuracy_score' => 40.0,
        'diction_score' => 55.5,
        'pron_score' => 41.25,
        'teacher_score' => 77.5,
    ]);

    app(PronunciationRepository::class)->saveWords($attempt, [
        ['word' => 'the', 'accuracy_score' => 90, 'error_type' => 'None'],
        ['word' => 'cat', 'accuracy_score' => 20, 'error_type' => 'Mispronunciation'],
        ['word' => 'mat', 'accuracy_score' => null, 'error_type' => 'Omission'],
    ]);
    // The pupil got "cat" right on a second go.
    $attempt->words()->where('word', 'cat')->update(['retry_accuracy' => 85]);

    $csv = $this->withHeaders(teacherHeaders($teacher))
        ->get("/api/v1/reports/students/{$student->id}.csv")
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Diction')
        ->and($csv)->toContain('55.5')
        // The teacher's override, not the machine's 41.25.
        ->and($csv)->toContain('77.5')
        ->and($csv)->not->toContain('41.25')
        ->and($csv)->toContain('"cat (corrected), mat"');
});
