<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\SystemLog\Services\SystemLogService;
use App\Domain\Teachers\Services\TeachersService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterTeacherRequest;
use App\Http\Requests\UpdateTeacherProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherAuthController extends Controller
{
    public function __construct(
        private TeachersService $service,
        private SystemLogService $logs,
    ) {}

    public function register(RegisterTeacherRequest $request): JsonResponse
    {
        $teacher = $this->service->register($request->validated());
        $token = $teacher->createToken('teacher')->plainTextToken;

        $this->logs->record(
            'teacher.registered',
            "{$teacher->full_name} created a teacher account ({$teacher->email}).",
            null,
            $teacher,
        );

        return response()->json([
            'data' => $teacher,
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $teacher = $this->service->attempt(
            $request->input('email'),
            $request->input('password'),
        );

        if (! $teacher) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        $token = $teacher->createToken('teacher')->plainTextToken;

        $this->logs->record(
            'teacher.login',
            "{$teacher->full_name} signed in.",
            null,
            $teacher,
        );

        return response()->json([
            'data' => $teacher,
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    /** Update the authenticated teacher's own profile. */
    public function updateProfile(UpdateTeacherProfileRequest $request): JsonResponse
    {
        $teacher = $request->user();
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $teacher->update($data);

        $this->logs->record(
            'teacher.profile_updated',
            "{$teacher->full_name} updated their profile.",
            null,
            $teacher,
        );

        return response()->json(['data' => $teacher->refresh()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $this->logs->record('teacher.logout', "{$teacher->full_name} signed out.", null, $teacher);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
