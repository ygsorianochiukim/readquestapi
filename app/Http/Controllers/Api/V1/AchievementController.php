<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Achievement\Repositories\AchievementRepository;
use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher-facing view of the achievement catalog and of a student's milestones.
 * Students read their own via StudentAuthController@achievements.
 */
class AchievementController extends Controller
{
    public function __construct(
        private AchievementService $service,
        private AchievementRepository $repository,
    ) {}

    /** The achievement catalog. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->repository->all()]);
    }

    /** One student's progress toward every achievement. */
    public function forStudent(Request $request, Student $student): JsonResponse
    {
        abort_if(
            $student->teacher_id !== $request->user()->id,
            403,
            'This student does not belong to you.',
        );

        return response()->json(['data' => $this->service->forStudent($student)]);
    }
}
