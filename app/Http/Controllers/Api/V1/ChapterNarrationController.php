<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\Speech\Services\TextToSpeechService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChapterNarrationController extends Controller
{
    /**
     * Stream MP3 narration for a chapter's story text (Azure TTS),
     * caching the generated audio so it is only synthesized once.
     */
    public function __invoke(Request $request, Chapter $chapter, TextToSpeechService $tts): Response
    {
        if (blank($chapter->story_text)) {
            return response()->json([
                'message' => 'This chapter has no story text to narrate yet.',
            ], 422);
        }

        if (! $tts->isConfigured()) {
            return response()->json([
                'message' => 'Text-to-Speech is not configured. Add AZURE_SPEECH_KEY and AZURE_SPEECH_REGION to the API .env file.',
            ], 503);
        }

        // Cache keyed by chapter + a hash of the text, so edits regenerate audio.
        $cachePath = "narration/chapter-{$chapter->id}-".md5($chapter->story_text).'.mp3';

        if (! Storage::exists($cachePath)) {
            try {
                $audio = $tts->synthesize($chapter->story_text);
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Could not generate narration. Please check the Azure Speech credentials and region.',
                ], 502);
            }

            Storage::put($cachePath, $audio);
        }

        return response(Storage::get($cachePath), 200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
