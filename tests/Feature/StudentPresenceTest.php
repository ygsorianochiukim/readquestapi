<?php

/**
 * A teacher can see which pupils are using the app now, and who came today.
 */
it('marks a student active now and present today when they use the app', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    $before = $this->withHeaders(teacherHeaders($teacher))->getJson('/api/v1/dashboard')->assertOk();
    expect($before->json('data.students.0.is_online'))->toBeFalse()
        ->and($before->json('data.stats.present_today'))->toBe(0);

    $this->withHeaders(studentHeaders($student))->getJson('/api/v1/student/me')->assertOk();

    $now = $this->withHeaders(teacherHeaders($teacher))->getJson('/api/v1/dashboard')->assertOk();
    expect($now->json('data.students.0.is_online'))->toBeTrue()
        ->and($now->json('data.students.0.is_present_today'))->toBeTrue()
        ->and($now->json('data.stats.online_now'))->toBe(1)
        ->and($now->json('data.stats.present_today'))->toBe(1);

    // Gone quiet: still present today, no longer on right now.
    $this->travel(5)->minutes();
    $this->withHeaders(teacherHeaders($teacher))
        ->getJson('/api/v1/students')
        ->assertOk()
        ->assertJsonPath('data.0.is_online', false)
        ->assertJsonPath('data.0.is_present_today', true);
});
