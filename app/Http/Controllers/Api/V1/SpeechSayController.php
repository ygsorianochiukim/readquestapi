<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Speech\Services\TextToSpeechService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SpeechSayController extends Controller
{
    /**
     * Say a word or a sentence in the narration voice (Azure TTS).
     *
     * The games and the "tap a word to hear it" helpers used the browser's own
     * voice, which sounds robotic next to the narration. Kept short on purpose:
     * this is for a word or a clue, not a chapter, and each one is cached.
     */
    public function __invoke(Request $request, TextToSpeechService $tts): Response
    {
        $text = trim((string) $request->validate([
            'text' => ['required', 'string', 'max:300'],
        ])['text']);

        if (! $tts->isConfigured()) {
            return response()->json([
                'message' => 'Text-to-Speech is not configured. Add AZURE_SPEECH_KEY and AZURE_SPEECH_REGION to the API .env file.',
            ], 503);
        }

        try {
            $path = $tts->cachedAudio('say', $text);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $tts->failureMessage($exception)], 502);
        }

        return response(Storage::get($path), 200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
