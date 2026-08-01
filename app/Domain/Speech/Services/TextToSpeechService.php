<?php

namespace App\Domain\Speech\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TextToSpeechService
{
    /**
     * Whether Azure Speech credentials are present.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.azure_speech.key'))
            && ! empty(config('services.azure_speech.region'));
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
            'X-Microsoft-OutputFormat' => 'audio-24khz-96kbitrate-mono-mp3',
            'User-Agent' => 'ReadQuest',
        ])
            ->withBody($this->buildSsml($text, $voice), 'application/ssml+xml')
            ->timeout(30)
            ->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('Azure TTS request failed with status '.$response->status());
        }

        return $response->body();
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
