<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\BookPage;
use App\Domain\Celebration\CelebrationBag;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Chapter\Services\ChapterParagraphs;
use App\Domain\Progress\Services\ProgressService;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Models\PronunciationWord;
use App\Domain\Pronunciation\Services\PronunciationService;
use App\Domain\Pronunciation\Services\ReadingPaceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPronunciationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PronunciationController extends Controller
{
    public function __construct(
        private PronunciationService $service,
        private CelebrationBag $celebrations,
        private ChapterParagraphs $paragraphs,
        private ProgressService $progress,
        private ReadingPaceService $pace,
    ) {}

    /** Student submits a recording of themselves reading a page/chapter aloud. */
    public function store(SubmitPronunciationRequest $request): JsonResponse
    {
        $bookPageId = $request->input('book_page_id');
        $chapterId = $request->input('chapter_id');
        $paragraphIndex = $request->input('paragraph_index');

        // Derive the reference text server-side (never trust the client for it).
        $referenceText = null;
        if ($chapterId && $bookPageId) {
            // One paragraph of one of the chapter's pages, read on its own.
            $page = BookPage::find($bookPageId);
            abort_if($page?->chapter_id !== (int) $chapterId, 422, 'That page is not part of this chapter.');
            $referenceText = $this->paragraphs->of($page)[(int) $paragraphIndex] ?? null;
        } elseif ($bookPageId) {
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
                $chapterId && $bookPageId ? (int) $paragraphIndex : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            // The generic message is all the pupil should see, but without the
            // real reason recorded alongside it this failure cannot be chased.
            Log::error('Pronunciation assessment failed.', [
                'student_id' => $request->user()?->id,
                'book_page_id' => $bookPageId,
                'chapter_id' => $chapterId,
                'reason' => $exception->getMessage(),
                'at' => $exception->getFile().':'.$exception->getLine(),
            ]);

            return response()->json([
                'message' => 'Could not assess the recording. Please try reading again.',
            ] + (config('app.debug') ? ['reason' => $exception->getMessage()] : []), 502);
        }

        return response()->json([
            'data' => $attempt,
            // Everything the celebration modal needs, so the client does not
            // have to re-fetch badges and achievements to find out what changed.
            'celebrations' => $this->celebrations->toArray(),
            'meta' => [
                'pass_mark' => ProgressService::PRONUNCIATION_PASS,
                'pace_hint' => $this->pace->hint($attempt->pace),
                // Reading a chapter page by page: how far through it the pupil is.
                'read_aloud' => $attempt->paragraph_index !== null && $attempt->chapter
                    ? $this->progress->readAloudSummary($request->user(), $attempt->chapter)
                    : null,
            ],
        ], 201);
    }

    /** Re-open a past attempt, words and all — used to colour a page back in. */
    public function show(Request $request, PronunciationAttempt $attempt): JsonResponse
    {
        abort_if($attempt->student_id !== $request->user()->id, 403, 'That reading is not yours.');

        return response()->json(['data' => $attempt->load('words')]);
    }

    /**
     * The pupil tapped a word they got wrong and said it again on its own.
     *
     * The retry is scored in the browser by the same Azure pipeline; this only
     * keeps the result, so the teacher can see the word was put right. The
     * attempt's own score is deliberately left alone.
     */
    public function retryWord(Request $request, PronunciationAttempt $attempt, PronunciationWord $word): JsonResponse
    {
        abort_if($attempt->student_id !== $request->user()->id, 403, 'That reading is not yours.');
        abort_if($word->pronunciation_attempt_id !== $attempt->id, 404, 'That word is not part of this reading.');

        $data = $request->validate([
            'accuracy' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->service->recordWordRetry($word, (float) $data['accuracy']),
        ]);
    }
}
