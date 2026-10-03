<?php

use App\Domain\Badge\Models\Badge;
use App\Domain\Pronunciation\Models\PronunciationAttempt;

/**
 * The bell on the student's screen: badges their teacher gave them and what
 * their teacher said about their reading, with the new ones counted.
 */
it('tells a student about a badge from their teacher and a note on their reading', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $chapter = makeBook(1)->chapters()->first();
    $badge = Badge::create(['name' => 'Star Reader', 'points' => 10, 'status' => 'active']);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/students/{$student->id}/badges/{$badge->id}")
        ->assertSuccessful();

    $attempt = PronunciationAttempt::create([
        'student_id' => $student->id,
        'chapter_id' => $chapter->id,
        'reference_text' => 'The fox ran.',
        'pron_score' => 70,
    ]);

    $this->travel(1)->minutes();
    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/pronunciation/{$attempt->id}/score", ['teacher_score' => 90, 'teacher_note' => 'Lovely expression!'])
        ->assertOk();

    $feed = $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/student/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread', 2);

    // Newest first: the note, then the badge.
    expect($feed->json('data.0.type'))->toBe('review')
        ->and($feed->json('data.0.title'))->toContain('left a note')
        ->and($feed->json('data.0.message'))->toContain('Lovely expression!')
        ->and($feed->json('data.0.score'))->toBe(90)
        ->and($feed->json('data.1.type'))->toBe('badge')
        ->and($feed->json('data.1.title'))->toContain('gave you a badge')
        ->and($feed->json('data.1.message'))->toContain('Star Reader');
});

it('counts nothing as new once the student has looked', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);
    $badge = Badge::create(['name' => 'Bookworm', 'points' => 5, 'status' => 'active']);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/students/{$student->id}/badges/{$badge->id}")
        ->assertSuccessful();

    $this->travel(1)->seconds();
    $this->withHeaders(studentHeaders($student))->postJson('/api/v1/student/notifications/read')->assertOk();

    $this->withHeaders(studentHeaders($student))
        ->getJson('/api/v1/student/notifications')
        ->assertJsonPath('meta.unread', 0)
        ->assertJsonPath('data.0.unread', false);
});
