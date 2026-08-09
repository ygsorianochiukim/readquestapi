<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Student\Services\StudentService;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StudentLoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAuthController extends Controller
{
    public function __construct(
        private StudentService $service,
        private SystemLogService $logs,
    ) {}

    public function login(StudentLoginRequest $request): JsonResponse
    {
        $student = $this->service->attempt(
            $request->input('username'),
            $request->input('password'),
        );

        if (! $student) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        if ($student->status !== 'active') {
            return response()->json([
                'message' => 'This account is inactive. Please ask your teacher for help.',
            ], 403);
        }

        $token = $student->createToken('student')->plainTextToken;

        $this->logs->record('student.login', "{$student->full_name} signed in.", $student);

        return response()->json([
            'data'  => $student,
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    /** The authenticated student's own earned badges and points. */
    public function badges(Request $request, RewardService $rewards): JsonResponse
    {
        $student = $request->user();

        return response()->json([
            'data'   => $rewards->forStudent($student),
            'points' => $student->points,
        ]);
    }

    /** The authenticated student's own achievement milestones. */
    public function achievements(Request $request, AchievementService $achievements): JsonResponse
    {
        return response()->json([
            'data' => $achievements->forStudent($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $student = $request->user();
        $this->logs->record('student.logout', "{$student->full_name} signed out.", $student);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
