<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Services\PronunciationService;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PronunciationReviewController extends Controller
{
    public function __construct(private PronunciationService $service) {}

    /** Teacher: list a student's pronunciation attempts. */
    public function index(Request $request, Student $student): JsonResponse
    {
        abort_if(
            $student->teacher_id !== $request->user()->id,
            403,
            'This student does not belong to you.',
        );

        return response()->json([
            'data' => $this->service->forStudent($student->id),
        ]);
    }

    /** Teacher: validate (confirm) an attempt's score. */
    public function validateAttempt(Request $request, PronunciationAttempt $attempt): JsonResponse
    {
        abort_if(
            $attempt->student->teacher_id !== $request->user()->id,
            403,
            'This attempt does not belong to your student.',
        );

        return response()->json([
            'data' => $this->service->validate($attempt, $request->user()),
        ]);
    }
}
