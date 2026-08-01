<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Services\BadgeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBadgeRequest;
use App\Http\Requests\UpdateBadgeRequest;
use Illuminate\Http\JsonResponse;

class BadgeController extends Controller
{
    public function __construct(private BadgeService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->list()]);
    }

    public function store(CreateBadgeRequest $request): JsonResponse
    {
        $badge = $this->service->create($request->validated());

        return response()->json(['data' => $badge], 201);
    }

    public function show(Badge $badge): JsonResponse
    {
        return response()->json(['data' => $badge]);
    }

    public function update(UpdateBadgeRequest $request, Badge $badge): JsonResponse
    {
        $badge = $this->service->update($badge, $request->validated());

        return response()->json(['data' => $badge]);
    }

    public function destroy(Badge $badge): JsonResponse
    {
        $this->service->delete($badge);

        return response()->json(['message' => 'Badge deleted successfully.']);
    }
}
