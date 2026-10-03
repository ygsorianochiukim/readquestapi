<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Repositories\PronunciationRepository;
use App\Domain\Pronunciation\Services\PronunciationService;
use App\Domain\Pronunciation\Services\ReadingReportService;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Requests\OverridePronunciationScoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PronunciationReviewController extends Controller
{
    public function __construct(
        private PronunciationService $service,
        private PronunciationRepository $repository,
        private ReadingReportService $reports,
    ) {}

    /** Teacher: list a student's pronunciation attempts, words included. */
    public function index(Request $request, Student $student): JsonResponse
    {
        $this->assertOwns($request, $student);

        return response()->json([
            'data' => $this->service->forStudent($student->id),
        ]);
    }

    /**
     * Teacher: the review queue across every student they teach.
     *
     * This is the working surface for manual verification — a teacher marks a
     * class's readings in one sitting, not one child at a time.
     */
    public function queue(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'status' => ['nullable', 'in:pending,reviewed'],
            'only_failed' => ['nullable', 'boolean'],
            'off_script' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $attempts = $this->repository->reviewQueue(
            $request->user()->id,
            $filters,
            (int) ($filters['per_page'] ?? 20),
        );

        return response()->json([
            'data' => $attempts->items(),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'total' => $attempts->total(),
                'pending' => $this->service->pendingCountForTeacher($request->user()->id),
            ],
        ]);
    }

    /** Teacher: one attempt in full — every word, so the misses can be seen. */
    public function show(Request $request, PronunciationAttempt $attempt): JsonResponse
    {
        $this->assertOwnsAttempt($request, $attempt);

        return response()->json([
            'data' => $attempt->load(['words', 'student:id,first_name,last_name', 'chapter:id,chapter_number,title', 'bookPage:id,page_number']),
        ]);
    }

    /** Teacher: the individual reading report — trend, misses, history. */
    public function report(Request $request, Student $student): JsonResponse
    {
        $this->assertOwns($request, $student);

        return response()->json([
            'data' => $this->reports->forStudent($student),
        ]);
    }

    /** Teacher: validate (confirm) an attempt's score. */
    public function validateAttempt(Request $request, PronunciationAttempt $attempt): JsonResponse
    {
        $this->assertOwnsAttempt($request, $attempt);

        return response()->json([
            'data' => $this->service->validate($attempt, $request->user()),
        ]);
    }

    /** Teacher: replace the automatic score with their own judgement. */
    public function override(OverridePronunciationScoreRequest $request, PronunciationAttempt $attempt): JsonResponse
    {
        $this->assertOwnsAttempt($request, $attempt);

        $score = $request->input('teacher_score');

        return response()->json([
            'data' => $this->service->overrideScore(
                $attempt,
                $score === null ? null : (float) $score,
                $request->input('teacher_note'),
                $request->user(),
            )->load('words'),
        ]);
    }

    private function assertOwns(Request $request, Student $student): void
    {
        abort_if(
            $student->teacher_id !== $request->user()->id,
            403,
            'This student does not belong to you.',
        );
    }

    private function assertOwnsAttempt(Request $request, PronunciationAttempt $attempt): void
    {
        abort_if(
            $attempt->student?->teacher_id !== $request->user()->id,
            403,
            'This attempt does not belong to your student.',
        );
    }
}
