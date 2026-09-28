<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Services\ChapterService;
use App\Domain\Chapter\Services\ChapterUploadService;
use App\Domain\Progress\Services\ClassProgressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateChapterRequest;
use App\Http\Requests\UploadChapterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ChapterController extends Controller
{
    public function __construct(
        private ChapterService $service,
        private ChapterUploadService $uploads,
        private ClassProgressService $classProgress,
    ) {}

    public function index(Request $request, Book $book): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listForBook($book),
            // How many of this teacher's pupils have finished each chapter —
            // the chapter everyone stalls on is the one worth looking at.
            'progress' => $this->classProgress->chapterSummaries($request->user(), $book),
        ]);
    }

    /**
     * Add a chapter by uploading scans of its pages. The text is read off the
     * pages and the quiz is written from it — nothing is typed.
     */
    public function upload(UploadChapterRequest $request, Book $book): JsonResponse
    {
        try {
            $chapter = $this->uploads->createFromUpload(
                $book,
                array_values($request->file('files')),
                $request->input('title'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $chapter], 201);
    }

    public function show(Chapter $chapter): JsonResponse
    {
        return response()->json(['data' => $chapter->load('quizQuestions')]);
    }

    public function update(UpdateChapterRequest $request, Chapter $chapter): JsonResponse
    {
        $chapter = $this->service->update($chapter, $request->validated());

        return response()->json(['data' => $chapter]);
    }

    public function destroy(Chapter $chapter): JsonResponse
    {
        $this->service->delete($chapter);

        return response()->json(['message' => 'Chapter deleted successfully.']);
    }
}
