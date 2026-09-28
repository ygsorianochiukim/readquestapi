<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Celebration\CelebrationBag;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Services\PageProgressService;
use App\Domain\Progress\Services\ProgressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckQuizAnswerRequest;
use App\Http\Requests\CompleteGameRequest;
use App\Http\Requests\SubmitQuizRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLearningController extends Controller
{
    public function __construct(
        private ProgressService $progress,
        private PageProgressService $pageProgress,
        private CelebrationBag $celebrations,
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

    /**
     * Check a single answer the moment it is picked, so the child hears right
     * away whether they got it. Only the asked-about question's answer is
     * revealed, and nothing is recorded.
     */
    public function checkQuizAnswer(CheckQuizAnswerRequest $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $validated = $request->validated();

        return response()->json([
            'data' => $this->progress->checkQuizAnswer(
                $chapter,
                (int) $validated['question_id'],
                $validated['answer'] ?? null,
            ),
        ]);
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

        // Finishing a quiz can finish a chapter, and finishing a chapter can
        // finish a book — all of which the child should be told about.
        return response()->json([
            'data' => $result,
            'celebrations' => $this->celebrations->toArray(),
        ]);
    }

    public function markStoryRead(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $progress = $this->progress->markStoryRead($request->user(), $chapter);

        return response()->json(['data' => $progress]);
    }

    /**
     * A mini-game was won. Naming the game type pays its first-win points;
     * without one (an older client) only the chapter's game step is marked.
     */
    public function completeGame(CompleteGameRequest $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        $validated = $request->validated();
        $gameType = $validated['game_type'] ?? null;

        if ($gameType === null) {
            $progress = $this->progress->markGameCompleted($request->user(), $chapter);

            return response()->json([
                'data' => $progress,
                'celebrations' => $this->celebrations->toArray(),
            ]);
        }

        $win = $this->progress->recordGameWin(
            $request->user(),
            $chapter,
            $gameType,
            (int) ($validated['mistakes'] ?? 0),
        );
        $progress = $win['progress'];
        unset($win['progress']);

        return response()->json([
            'data' => $progress,
            'game' => $win,
            'celebrations' => $this->celebrations->toArray(),
        ]);
    }

    /** The mini-games already won on this chapter, for the per-game stars. */
    public function games(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        return response()->json([
            'data' => $this->progress->gamesForChapter($request->user(), $chapter),
        ]);
    }

    /** Which pages of the chapter the pupil has read aloud so far, and how well. */
    public function readAloud(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertUnlocked($request, $chapter);

        return response()->json([
            'data' => $this->progress->readAloudSummary($request->user(), $chapter),
        ]);
    }

    /** Pages of an assigned page-based book, with this pupil's progress. */
    public function bookPages(Request $request, Book $book): JsonResponse
    {
        $this->assertAssigned($request, $book);

        // `?chapter=<id>` narrows the list to one of the book's chapters.
        $chapterId = $request->filled('chapter') ? $request->integer('chapter') : null;
        abort_if(
            $chapterId !== null && ! $book->chapters()->whereKey($chapterId)->exists(),
            404,
            'That chapter is not part of this book.',
        );

        return response()->json([
            'data' => $this->pageProgress->forBook($request->user(), $book, $chapterId),
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
            'celebrations' => $this->celebrations->toArray(),
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
