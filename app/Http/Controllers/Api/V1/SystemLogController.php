<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SystemLog\Services\SystemLogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher-facing audit trail: the teacher's own actions plus everything their
 * students did.
 */
class SystemLogController extends Controller
{
    public function __construct(private SystemLogService $service) {}

    public function index(Request $request): JsonResponse
    {
        $logs = $this->service->forTeacher($request->user()->id, [
            'action' => $request->query('action'),
            'student_id' => $request->query('student_id'),
            'search' => $request->query('search'),
            'per_page' => min((int) $request->query('per_page', 25), 100),
        ]);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'actions' => $this->service->actionsForTeacher($request->user()->id),
        ]);
    }
}
