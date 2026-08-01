<?php

namespace App\Domain\Speech\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PronunciationAssessmentService
{
    /**
     * Uses the same Azure Speech resource as Text-to-Speech.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.azure_speech.key'))
            && ! empty(config('services.azure_speech.region'));
    }

    /**
     * Send recorded audio + the reference text to the Azure Speech
     * pronunciation-assessment endpoint and return the scores.
     *
     * @return array{recognized_text: ?string, accuracy_score: ?float, fluency_score: ?float, completeness_score: ?float, pron_score: ?float}
     *
     * @throws RuntimeException on request failure.
     */
    public function assess(string $referenceText, string $audioWav): array
    {
        $key = config('services.azure_speech.key');
        $region = config('services.azure_speech.region');

        // `format=detailed` is required — without it Azure returns only
        // DisplayText (simple format) and no NBest/PronunciationAssessment scores.
        $endpoint = "https://{$region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=en-US&format=detailed";

        $assessmentConfig = base64_encode(json_encode([
            'ReferenceText' => $referenceText,
            'GradingSystem' => 'HundredMark',
            'Granularity' => 'FullText',
            'Dimension' => 'Comprehensive',
        ]));

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $key,
            'Pronunciation-Assessment' => $assessmentConfig,
            'Accept' => 'application/json',
        ])
            ->withBody($audioWav, 'audio/wav; codecs=audio/pcm; samplerate=16000')
            ->timeout(60)
            ->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('Azure pronunciation assessment failed with status '.$response->status());
        }

        $data = $response->json();
        $best = $data['NBest'][0] ?? [];
        // With FullText granularity Azure returns the scores directly on the
        // NBest item; with Word/Phoneme they sit under PronunciationAssessment.
        $scores = $best['PronunciationAssessment'] ?? $best;

        return [
            'recognized_text' => $data['DisplayText'] ?? ($best['Display'] ?? null),
            'accuracy_score' => $scores['AccuracyScore'] ?? null,
            'fluency_score' => $scores['FluencyScore'] ?? null,
            'completeness_score' => $scores['CompletenessScore'] ?? null,
            'pron_score' => $scores['PronScore'] ?? null,
        ];
    }
}
