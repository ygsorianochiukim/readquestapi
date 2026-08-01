<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\QuizQuestion\Services\QuizQuestionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQuizQuestionRequest;
use App\Http\Requests\UpdateQuizQuestionRequest;
use Illuminate\Http\JsonResponse;

class QuizQuestionController extends Controller
{
    public function __construct(private QuizQuestionService $service) {}

    public function index(Chapter $chapter): JsonResponse
    {
        return response()->json(['data' => $this->service->listForChapter($chapter)]);
    }

    public function store(CreateQuizQuestionRequest $request, Chapter $chapter): JsonResponse
    {
        $question = $this->service->create($chapter, $request->validated());

        return response()->json(['data' => $question], 201);
    }

    public function show(QuizQuestion $quizQuestion): JsonResponse
    {
        return response()->json(['data' => $quizQuestion]);
    }

    public function update(UpdateQuizQuestionRequest $request, QuizQuestion $quizQuestion): JsonResponse
    {
        $question = $this->service->update($quizQuestion, $request->validated());

        return response()->json(['data' => $question]);
    }

    public function destroy(QuizQuestion $quizQuestion): JsonResponse
    {
        $this->service->delete($quizQuestion);

        return response()->json(['message' => 'Quiz question deleted successfully.']);
    }
}
