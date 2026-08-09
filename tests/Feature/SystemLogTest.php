<?php

use App\Domain\SystemLog\Models\SystemLog;

it('records an audit entry when a teacher creates a student', function () {
    $teacher = makeTeacher();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson('/api/v1/students', [
            'first_name' => 'Mika',
            'last_name' => 'Cruz',
            'username' => 'mika.cruz',
            'password' => 'password',
        ])->assertCreated();

    $log = SystemLog::where('action', 'student.created')->first();

    expect($log)->not->toBeNull()
        ->and($log->teacher_id)->toBe($teacher->id)
        ->and($log->description)->toContain('mika.cruz');
});

it('records chapter completion and quiz submissions against the student', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $book = makeBook(1);
    assignBook($student, $book);

    $chapter = $book->chapters()->first();
    $question = makeQuizQuestion($chapter, 'Yes');

    $this->withHeaders(studentHeaders($student))
        ->postJson("/api/v1/student/chapters/{$chapter->id}/quiz", [
            'answers' => [$question->id => 'Yes'],
        ])->assertOk();

    $log = SystemLog::where('action', 'quiz.submitted')->first();

    expect($log)->not->toBeNull()
        ->and($log->student_id)->toBe($student->id)
        ->and($log->description)->toContain('100%');
});

it('shows a teacher their own entries and their students, but not another class', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $otherTeacher = makeTeacher();
    $otherStudent = makeStudent($otherTeacher);

    SystemLog::create(['teacher_id' => $teacher->id, 'action' => 'teacher.login', 'description' => 'mine']);
    SystemLog::create(['student_id' => $student->id, 'action' => 'student.login', 'description' => 'my pupil']);
    SystemLog::create(['teacher_id' => $otherTeacher->id, 'action' => 'teacher.login', 'description' => 'not mine']);
    SystemLog::create(['student_id' => $otherStudent->id, 'action' => 'student.login', 'description' => 'not my pupil']);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/system-logs')
        ->assertOk();

    $descriptions = collect($response->json('data'))->pluck('description');

    expect($descriptions)->toContain('mine')
        ->and($descriptions)->toContain('my pupil')
        ->and($descriptions)->not->toContain('not mine')
        ->and($descriptions)->not->toContain('not my pupil');
});

it('filters the audit trail by action', function () {
    $teacher = makeTeacher();
    SystemLog::create(['teacher_id' => $teacher->id, 'action' => 'teacher.login', 'description' => 'signed in']);
    SystemLog::create(['teacher_id' => $teacher->id, 'action' => 'report.exported', 'description' => 'exported']);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/system-logs?action=report.exported')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.description'))->toBe('exported');
});

it('keeps the audit trail out of reach of students', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/system-logs')
        ->assertStatus(403);
});
