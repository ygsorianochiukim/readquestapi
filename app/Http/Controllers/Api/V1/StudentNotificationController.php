<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notification\Services\StudentNotificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's notifications: badges from their teacher, and notes on their reading. */
class StudentNotificationController extends Controller
{
    public function __construct(private StudentNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $feed = $this->notifications->forStudent($request->user());

        return response()->json([
            'data' => $feed['data'],
            'meta' => ['unread' => $feed['unread']],
        ]);
    }

    /** The bell was opened: everything shown so far has been seen. */
    public function markRead(Request $request): JsonResponse
    {
        $this->notifications->markRead($request->user());

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
