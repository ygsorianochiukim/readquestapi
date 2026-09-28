<?php

namespace App\Domain\Speech\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TextToSpeechService
{
    /**
     * 48kbit mono MP3: plenty for one voice reading, and half the bytes of
     * 96kbit — which matters, because a whole chapter in an HD voice is a
     * large download that used to run out of time.
     */
    private const FORMAT = 'audio-24khz-48kbitrate-mono-mp3';

    /**
     * Whether Azure Speech credentials are present.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.azure_speech.key'))
            && ! empty(config('services.azure_speech.region'));
    }

    /**
     * The narration for this text, made once and kept.
     *
     * Keyed by the text, the voice and the format, so editing the words or
     * changing the voice makes new audio instead of replaying the old one.
     *
     * @param  string  $name  what the audio is of, e.g. "chapter-12"
     * @return string the path of the MP3 on the default disk
     *
     * @throws RuntimeException when the audio cannot be made.
     */
    public function cachedAudio(string $name, string $text): string
    {
        $voice = (string) config('services.azure_speech.voice', 'en-US-JennyNeural');
        $path = "narration/{$name}-".md5($voice.'|'.self::FORMAT.'|'.$text).'.mp3';

        if (! Storage::exists($path)) {
            Storage::put($path, $this->synthesize($text));
        }

        return $path;
    }

    /** The cache name for a page's narration, or one paragraph of it. */
    public static function pageName(int $pageId, ?int $paragraph = null): string
    {
        return $paragraph === null ? "page-{$pageId}" : "page-{$pageId}-p{$paragraph}";
    }

    /**
     * Convert text into spoken MP3 audio using the Azure Speech REST API.
     * Returns the raw MP3 bytes.
     *
     * @throws RuntimeException when the request fails.
     */
    public function synthesize(string $text): string
    {
        $key = config('services.azure_speech.key');
        $region = config('services.azure_speech.region');
        $voice = config('services.azure_speech.voice', 'en-US-JennyNeural');

        $endpoint = "https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $key,
            'X-Microsoft-OutputFormat' => self::FORMAT,
            'User-Agent' => 'ReadQuest',
        ])
            ->withBody($this->buildSsml($text, $voice), 'application/ssml+xml')
            // Fail fast when Azure cannot be reached at all, but give a long
            // chapter the time it needs to be spoken and sent back.
            ->connectTimeout(10)
            ->timeout((int) config('services.azure_speech.timeout', 180))
            ->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('Azure TTS request failed with status '.$response->status(), $response->status());
        }

        return $response->body();
    }

    /**
     * What to tell the person asking for narration when it could not be made —
     * the actual reason, not a guess that always blames the credentials.
     */
    public function failureMessage(Throwable $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return str_contains($exception->getMessage(), 'timed out')
                ? 'The narration took too long to make. Please try again in a moment.'
                : 'Could not reach Azure Speech. Please check the internet connection.';
        }

        return match ($exception->getCode()) {
            401, 403 => 'Azure Speech refused the request. Please check AZURE_SPEECH_KEY and AZURE_SPEECH_REGION.',
            400 => 'Azure Speech could not use the voice "'.config('services.azure_speech.voice').'" in this region. Please check AZURE_SPEECH_VOICE.',
            429 => 'Azure Speech is busy right now. Please try again in a moment.',
            default => 'Could not generate narration. Please try again.',
        };
    }

    /**
     * Wrap the text in SSML, deriving the language from the voice name
     * (e.g. "fil-PH-BlessicaNeural" -> "fil-PH").
     */
    private function buildSsml(string $text, string $voice): string
    {
        $parts = explode('-', $voice);
        $lang = count($parts) >= 2 ? "{$parts[0]}-{$parts[1]}" : 'en-US';
        $safeText = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<speak version='1.0' xml:lang='{$lang}'>"
            ."<voice xml:lang='{$lang}' name='{$voice}'>{$safeText}</voice>"
            .'</speak>';
    }
}
