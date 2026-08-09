<?php

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
