<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\BookPage;
use App\Domain\Speech\Services\TextToSpeechService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BookPageNarrationController extends Controller
{
    /**
     * Stream MP3 narration for a scanned page's text (Azure TTS), cached
     * so each page is only synthesized once per text version.
     */
    public function __invoke(Request $request, BookPage $page, TextToSpeechService $tts): Response
    {
        if (blank($page->text)) {
            return response()->json([
                'message' => 'This page has no text to narrate yet.',
            ], 422);
        }

        if (! $tts->isConfigured()) {
            return response()->json([
                'message' => 'Text-to-Speech is not configured. Add AZURE_SPEECH_KEY and AZURE_SPEECH_REGION to the API .env file.',
            ], 503);
        }

        $cachePath = "narration/page-{$page->id}-".md5($page->text).'.mp3';

        if (! Storage::exists($cachePath)) {
            try {
                $audio = $tts->synthesize($page->text);
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
