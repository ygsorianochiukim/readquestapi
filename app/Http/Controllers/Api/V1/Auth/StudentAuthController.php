<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Badge\Services\RewardService;
use App\Domain\Student\Services\StudentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StudentLoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAuthController extends Controller
{
    public function __construct(private StudentService $service) {}

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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
