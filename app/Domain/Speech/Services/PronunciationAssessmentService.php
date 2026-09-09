<?php

namespace App\Domain\Speech\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PronunciationAssessmentService
{
    private const AUDIO_CONTENT_TYPE = 'audio/wav; codecs=audio/pcm; samplerate=16000';

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
        $endpoint = $this->endpoint();
        $headers = [
            'Ocp-Apim-Subscription-Key' => config('services.azure_speech.key'),
            'Accept' => 'application/json',
        ];

        // The same audio goes up twice, concurrently:
        //
        //  1. with the Pronunciation-Assessment header — the scores. Azure
        //     recognises this pass *against* the reference text, so its
        //     transcript only ever reports which page words it thinks it
        //     matched; words the pupil said that are not on the page never
        //     come back at all.
        //  2. without that header — plain speech-to-text, with no idea what
        //     the page says. This is the only pass that can tell us what the
        //     pupil actually said, so it is what we show and score the match on.
        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('assessment')
                ->withHeaders($headers + [
                    'Pronunciation-Assessment' => $this->assessmentConfig($referenceText),
                ])
                ->withBody($audioWav, self::AUDIO_CONTENT_TYPE)
                ->timeout(60)
                ->post($endpoint),
            $pool->as('transcript')
                ->withHeaders($headers)
                ->withBody($audioWav, self::AUDIO_CONTENT_TYPE)
                ->timeout(60)
                ->post($endpoint),
        ]);

        $assessment = $responses['assessment'] ?? null;

        if (! $assessment instanceof Response || ! $assessment->successful()) {
            throw new RuntimeException(
                'Azure pronunciation assessment failed with status '
                .($assessment instanceof Response ? $assessment->status() : 'no response')
            );
        }

        $data = $assessment->json();
        $best = $data['NBest'][0] ?? [];
        // With word/phoneme granularity the scores sit under
        // PronunciationAssessment; with FullText they were on the NBest item.
        $scores = $best['PronunciationAssessment'] ?? $best;

        return [
            // Fall back to the page words the assessment pass matched only when
            // the unbiased pass gave us nothing.
            'recognized_text' => $this->spokenText($responses['transcript'] ?? null)
                ?? $this->matchedPageWords($data, $best),
            'accuracy_score' => $scores['AccuracyScore'] ?? null,
            'fluency_score' => $scores['FluencyScore'] ?? null,
            'completeness_score' => $scores['CompletenessScore'] ?? null,
            'pron_score' => $scores['PronScore'] ?? null,
        ];
    }

    /**
     * `format=detailed` is required — without it Azure returns only DisplayText
     * (simple format) and no NBest/PronunciationAssessment scores.
     */
    private function endpoint(): string
    {
        $region = config('services.azure_speech.region');

        return "https://{$region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=en-US&format=detailed";
    }

    private function assessmentConfig(string $referenceText): string
    {
        return base64_encode(json_encode([
            'ReferenceText' => $referenceText,
            'GradingSystem' => 'HundredMark',
            // Word granularity with miscue detection is what makes Azure compare
            // the audio against the reference instead of only scoring the
            // phonemes it can align: words the pupil skipped come back as
            // omissions. Under `FullText` with miscue off, reading something
            // else entirely still scored well.
            'Granularity' => 'Word',
            'Dimension' => 'Comprehensive',
            'EnableMiscue' => true,
        ]));
    }

    /**
     * What the pupil actually said, from the pass that was never told the page
     * text. Null when that pass failed or made out nothing.
     */
    private function spokenText(mixed $response): ?string
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $best = $data['NBest'][0] ?? [];

        // Lexical is the recogniser's plain transcript; Display adds
        // punctuation and capitals, which the word match does not need.
        $text = $best['Lexical'] ?? $data['DisplayText'] ?? ($best['Display'] ?? null);

        return blank($text) ? null : $text;
    }

    /**
     * The page words the assessment pass believes it heard, omissions dropped.
     * A poor stand-in for a transcript — it can only ever contain words from
     * the page — so it is the fallback, never the first choice.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $best
     */
    private function matchedPageWords(array $data, array $best): ?string
    {
        $words = $best['PronunciationAssessment']['Words'] ?? $best['Words'] ?? [];
        $spoken = [];

        if (is_array($words)) {
            foreach ($words as $word) {
                $errorType = $word['PronunciationAssessment']['ErrorType'] ?? $word['ErrorType'] ?? null;

                // An omission is a page word that went unread, not a spoken one.
                if ($errorType === 'Omission') {
                    continue;
                }

                if (filled($word['Word'] ?? null)) {
                    $spoken[] = $word['Word'];
                }
            }
        }

        return $spoken === [] ? null : implode(' ', $spoken);
    }
}
