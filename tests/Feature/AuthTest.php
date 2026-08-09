<?php

use App\Domain\SystemLog\Models\SystemLog;

it('registers a teacher and returns a token', function () {
    $response = $this->postJson('/api/v1/teacher/register', [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'email' => 'ana@readquest.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'ana@readquest.test')
        ->assertJsonStructure(['data' => ['id', 'first_name', 'email'], 'token']);

    expect(SystemLog::where('action', 'teacher.registered')->count())->toBe(1);
});

it('logs a teacher in and rejects a wrong password', function () {
    $teacher = makeTeacher(['email' => 'known@readquest.test']);

    $this->postJson('/api/v1/teacher/login', [
        'email' => $teacher->email,
        'password' => 'password',
    ])->assertOk()->assertJsonStructure(['data', 'token']);

    $this->postJson('/api/v1/teacher/login', [
        'email' => $teacher->email,
        'password' => 'wrong-password',
    ])->assertStatus(401);

    expect(SystemLog::where('action', 'teacher.login')->count())->toBe(1);
});

it('logs a student in by username and blocks inactive accounts', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher, ['username' => 'juan.test']);

    $this->postJson('/api/v1/student/login', [
        'username' => 'juan.test',
        'password' => 'password',
    ])->assertOk()->assertJsonPath('data.username', 'juan.test');

    $student->update(['status' => 'inactive']);

    $this->postJson('/api/v1/student/login', [
        'username' => 'juan.test',
        'password' => 'password',
    ])->assertStatus(403);
});

it('rejects unauthenticated access to teacher routes', function () {
    $this->getJson('/api/v1/dashboard')->assertStatus(401);
    $this->getJson('/api/v1/students')->assertStatus(401);
});

it('keeps teacher and student roles separated', function () {
    $teacher = makeTeacher();
    $student = makeStudent($teacher);

    // A student token cannot reach a teacher route, and vice versa.
    $this->withHeaders(studentHeaders($student))->getJson('/api/v1/dashboard')->assertStatus(403);
    $this->withHeaders(teacherHeaders($teacher))->getJson('/api/v1/student/progress')->assertStatus(403);
});

it('lets a teacher update their own profile, including an avatar', function () {
    $teacher = makeTeacher();

    $this->withHeaders(teacherHeaders($teacher))
        ->putJson('/api/v1/teacher/me', [
            'first_name' => 'Renamed',
            'profile_image_url' => 'http://localhost/storage/uploads/avatar.png',
        ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Renamed')
        ->assertJsonPath('data.profile_image_url', 'http://localhost/storage/uploads/avatar.png');
});
