<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Services\ChapterParagraphs;
use App\Domain\Speech\Services\TextToSpeechService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BookPageNarrationController extends Controller
{
    /**
     * Stream MP3 narration for a page's text (Azure TTS), cached so each page
     * is only synthesized once per text version.
     *
     * With `?paragraph=N`, only that paragraph of the page — one page of the
     * student's flip book — is read, so it comes back in a second or two
     * instead of the best part of a minute for a whole chapter.
     */
    public function __invoke(Request $request, BookPage $page, TextToSpeechService $tts, ChapterParagraphs $paragraphs): Response
    {
        $paragraph = $request->query('paragraph');
        $paragraph = $paragraph === null ? null : (int) $paragraph;
        $text = $paragraph === null ? $page->text : ($paragraphs->of($page)[$paragraph] ?? null);

        if (blank($text)) {
            return response()->json([
                'message' => 'This page has no text to narrate yet.',
            ], 422);
        }

        if (! $tts->isConfigured()) {
            return response()->json([
                'message' => 'Text-to-Speech is not configured. Add AZURE_SPEECH_KEY and AZURE_SPEECH_REGION to the API .env file.',
            ], 503);
        }

        // Made once per wording and voice, then served from disk.
        try {
            $path = $tts->cachedAudio(TextToSpeechService::pageName($page->id, $paragraph), $text);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $tts->failureMessage($exception)], 502);
        }

        return response(Storage::get($path), 200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
