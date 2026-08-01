<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Services\ChapterService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateChapterRequest;
use App\Http\Requests\UpdateChapterRequest;
use Illuminate\Http\JsonResponse;

class ChapterController extends Controller
{
    public function __construct(private ChapterService $service) {}

    public function index(Book $book): JsonResponse
    {
        return response()->json(['data' => $this->service->listForBook($book)]);
    }

    public function store(CreateChapterRequest $request, Book $book): JsonResponse
    {
        $chapter = $this->service->create($book, $request->validated());

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
