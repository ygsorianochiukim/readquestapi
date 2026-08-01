<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Pronunciation\Services\PronunciationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPronunciationRequest;
use Illuminate\Http\JsonResponse;
use Throwable;

class PronunciationController extends Controller
{
    public function __construct(private PronunciationService $service) {}

    /** Student submits a recording of themselves reading a page/chapter aloud. */
    public function store(SubmitPronunciationRequest $request): JsonResponse
    {
        $bookPageId = $request->input('book_page_id');
        $chapterId = $request->input('chapter_id');

        // Derive the reference text server-side (never trust the client for it).
        $referenceText = null;
        if ($bookPageId) {
            $referenceText = BookPage::find($bookPageId)?->text;
        } elseif ($chapterId) {
            $referenceText = Chapter::find($chapterId)?->story_text;
        }

        if (blank($referenceText)) {
            return response()->json([
                'message' => 'There is no text to check the reading against.',
            ], 422);
        }

        if (! $this->service->isConfigured()) {
            return response()->json([
                'message' => 'Pronunciation assessment is not configured. Add AZURE_SPEECH_KEY and AZURE_SPEECH_REGION to the API .env file.',
            ], 503);
        }

        try {
            $attempt = $this->service->assessAndStore(
                $request->user(),
                $referenceText,
                $request->file('audio'),
                $bookPageId ? (int) $bookPageId : null,
                $chapterId ? (int) $chapterId : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not assess the recording. Please try reading again.',
            ], 502);
        }

        return response()->json(['data' => $attempt], 201);
    }
}
