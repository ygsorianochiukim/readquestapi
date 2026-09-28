<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use App\Domain\QuizQuestion\Services\QuizQuestionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateQuizQuestionRequest;
use Illuminate\Http\JsonResponse;

/**
 * Quiz questions are generated from the chapter's scanned text; teachers
 * review, correct or delete them, and can ask for a fresh set. There is no
 * typing a question in from scratch.
 */
class QuizQuestionController extends Controller
{
    public function __construct(private QuizQuestionService $service) {}

    public function index(Chapter $chapter): JsonResponse
    {
        return response()->json(['data' => $this->service->listForChapter($chapter)]);
    }

    /** Write the chapter's questions again from its current text. */
    public function generate(Chapter $chapter): JsonResponse
    {
        abort_if(
            blank($chapter->story_text),
            422,
            'This chapter has no text to make questions from yet. Scan its pages first.',
        );

        return response()->json(['data' => $this->service->regenerate($chapter)]);
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
