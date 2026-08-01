<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Badge\Services\RewardService;
use App\Domain\Book\Models\Book;
use App\Domain\Progress\Services\ProgressService;
use App\Domain\Pronunciation\Services\PronunciationService;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherDashboardController extends Controller
{
    public function __construct(
        private ProgressService $progress,
        private PronunciationService $pronunciation,
        private RewardService $rewards,
    ) {}

    /** Overview stats + a per-student summary table for the teacher home. */
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $students = $teacher->students()->get();

        $summaries = $students->map(function (Student $student) {
            $overview = $this->progress->overviewForStudent($student);
            $assignedBooks = count($overview);
            $completedBooks = collect($overview)->where('is_completed', true)->count();
            $totalChapters = collect($overview)->sum('total_chapters');
            $completedChapters = collect($overview)->sum('completed_chapters');

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'reading_level' => $student->reading_level,
                'status' => $student->status,
                'points' => $student->points,
                'assigned_books' => $assignedBooks,
                'completed_books' => $completedBooks,
                'percent' => $totalChapters > 0 ? (int) round($completedChapters / $totalChapters * 100) : 0,
            ];
        });

        $pendingValidations = $this->pronunciation->pendingCountForTeacher($teacher->id);

        return response()->json([
            'data' => [
                'stats' => [
                    'students' => $students->count(),
                    'active_books' => Book::where('status', 'active')->count(),
                    'pending_validations' => $pendingValidations,
                    'average_completion' => $summaries->count() > 0
                        ? (int) round($summaries->avg('percent'))
                        : 0,
                ],
                'students' => $summaries->values(),
            ],
        ]);
    }

    /** Detailed progress + pronunciation history for one student (monitoring & reports). */
    public function studentProgress(Request $request, Student $student): JsonResponse
    {
        $this->assertOwner($request, $student);

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'username' => $student->username,
                    'reading_level' => $student->reading_level,
                    'status' => $student->status,
                    'points' => $student->points,
                ],
                'progress' => $this->progress->detailForStudent($student),
                'badges' => $this->rewards->forStudent($student),
                'pronunciation' => $this->pronunciation->forStudent($student->id),
            ],
        ]);
    }

    private function assertOwner(Request $request, Student $student): void
    {
        abort_if(
            $student->teacher_id !== $request->user()->id,
            403,
            'This student does not belong to you.',
        );
    }
}
