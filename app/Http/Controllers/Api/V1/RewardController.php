<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function __construct(private RewardService $service) {}

    /** Teacher: list a student's earned badges. */
    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $student);

        return $this->badgesResponse($student);
    }

    /** Teacher: award a badge to a student. */
    public function store(Request $request, Student $student, Badge $badge): JsonResponse
    {
        $this->authorizeOwner($request, $student);
        $this->service->award($student, $badge, $request->user());

        return $this->badgesResponse($student->refresh());
    }

    /** Teacher: revoke a badge from a student. */
    public function destroy(Request $request, Student $student, Badge $badge): JsonResponse
    {
        $this->authorizeOwner($request, $student);
        $this->service->revoke($student, $badge, $request->user());

        return $this->badgesResponse($student->refresh());
    }

    private function badgesResponse(Student $student): JsonResponse
    {
        return response()->json([
            'data'   => $this->service->forStudent($student),
            'points' => $student->points,
        ]);
    }

    private function authorizeOwner(Request $request, Student $student): void
    {
        abort_if(
            $student->teacher_id !== $request->user()->id,
            403,
            'This student does not belong to you.',
        );
    }
}
