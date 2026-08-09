<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Services\PageProgressService;
use App\Domain\Progress\Services\ProgressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitQuizRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLearningController extends Controller
{
    public function __construct(
        private ProgressService $progress,
        private PageProgressService $pageProgress,
    ) {}

    /** Overview of all assigned books with completion + lock state. */
    public function overview(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->progress->overviewForStudent($request->user()),
            'points' => $request->user()->points,
        ]);
    }

    /** Chapters of an assigned book with per-chapter progress + lock state. */
    public function book(Request $request, Book $book): JsonResponse
    {
        $this->assertAssigned($request, $book);

        return response()->json([
            'data' => [
                'id' => $book->id,
                'title' => $book->title,
                'description' => $book->description,
                'reading_level' => $book->reading_level,
                'chapters' => $this->progress->chaptersForBook($request->user(), $book),
            ],
        ]);
    }

    /** Quiz questions for a chapter — WITHOUT the correct answers. */
    public function quiz(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $questions = $chapter->quizQuestions()->get()->map(fn ($question) => [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'choices' => $question->choices,
        ]);

        return response()->json(['data' => $questions]);
    }

    /** Submit quiz answers; graded server-side. */
    public function submitQuiz(SubmitQuizRequest $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $result = $this->progress->submitQuiz(
            $request->user(),
            $chapter,
            $request->validated()['answers'],
        );

        return response()->json(['data' => $result]);
    }

    public function markStoryRead(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $progress = $this->progress->markStoryRead($request->user(), $chapter);

        return response()->json(['data' => $progress]);
    }

    public function completeGame(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $progress = $this->progress->markGameCompleted($request->user(), $chapter);

        return response()->json(['data' => $progress]);
    }

    /** Pages of an assigned page-based book, with this pupil's progress. */
    public function bookPages(Request $request, Book $book): JsonResponse
    {
        $this->assertAssigned($request, $book);

        return response()->json([
            'data' => $this->pageProgress->forBook($request->user(), $book),
        ]);
    }

    /** The pupil marked a page as read. */
    public function markPageRead(Request $request, BookPage $page): JsonResponse
    {
        $book = $page->book;
        abort_unless($book !== null, 404, 'This page no longer exists.');
        $this->assertAssigned($request, $book);

        $this->pageProgress->markRead($request->user(), $page);

        return response()->json([
            'data' => $this->pageProgress->forBook($request->user(), $book),
        ]);
    }

    private function assertAssigned(Request $request, Book $book): void
    {
        abort_unless(
            $request->user()->books()->whereKey($book->id)->exists(),
            403,
            'This book has not been assigned to you.',
        );
    }

    private function assertUnlocked(Request $request, Chapter $chapter): void
    {
        abort_unless(
            $this->progress->isChapterUnlocked($request->user(), $chapter),
            403,
            'Finish the previous chapter before opening this one.',
        );
    }
}
