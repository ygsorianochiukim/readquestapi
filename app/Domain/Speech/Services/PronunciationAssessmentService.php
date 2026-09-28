<?php

namespace App\Domain\Speech\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PronunciationAssessmentService
{
    private const AUDIO_CONTENT_TYPE = 'audio/wav; codecs=audio/pcm; samplerate=16000';

    /** Azure reports offsets and durations in 100-nanosecond ticks. */
    private const TICKS_PER_MS = 10000;

    /**
     * Uses the same Azure Speech resource as Text-to-Speech.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.azure_speech.key'))
            && ! empty(config('services.azure_speech.region'));
    }

    /**
     * Mint a short-lived token the browser can use to talk to Azure Speech
     * directly. The subscription key must never leave the server: a token is
     * scoped to one region, expires in ten minutes, and can be thrown away.
     *
     * @throws RuntimeException when Azure will not issue one.
     */
    public function issueToken(): string
    {
        $region = config('services.azure_speech.region');

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => config('services.azure_speech.key'),
            'Content-Length' => '0',
        ])
            ->timeout(15)
            ->post("https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken");

        if (! $response->successful()) {
            throw new RuntimeException(
                'Azure would not issue a speech token (status '.$response->status().').'
            );
        }

        return $response->body();
    }

    /**
     * Send recorded audio + the reference text to the Azure Speech
     * pronunciation-assessment endpoint and return the scores.
     *
     * @return array<string, mixed>
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
                ?? $this->matchedPageWords($best),
            'accuracy_score' => $scores['AccuracyScore'] ?? null,
            'fluency_score' => $scores['FluencyScore'] ?? null,
            'completeness_score' => $scores['CompletenessScore'] ?? null,
            'prosody_score' => $scores['ProsodyScore'] ?? null,
            'diction_score' => $this->diction($best),
            'pron_score' => $scores['PronScore'] ?? null,
            'duration_ms' => isset($data['Duration'])
                ? (int) round($data['Duration'] / self::TICKS_PER_MS)
                : null,
            'words' => $this->words($best),
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
            //
            // Phoneme granularity is a superset of Word — every word still
            // comes back with its ErrorType — and adds a score per sound,
            // which is what the diction score is built from.
            'Granularity' => 'Phoneme',
            'Dimension' => 'Comprehensive',
            'EnableMiscue' => true,
            // Intonation. Without this flag ProsodyScore is simply absent.
            'EnableProsodyAssessment' => true,
        ]));
    }

    /**
     * Per-word scores, in the order they appear on the page.
     *
     * Azure has always sent these back under word granularity; keeping them is
     * what lets the reader colour each word and the teacher report name the
     * words a pupil keeps missing.
     *
     * @param  array<string, mixed>  $best
     * @return list<array<string, mixed>>
     */
    private function words(array $best): array
    {
        $words = $best['PronunciationAssessment']['Words'] ?? $best['Words'] ?? [];

        if (! is_array($words)) {
            return [];
        }

        $out = [];

        foreach ($words as $word) {
            if (blank($word['Word'] ?? null)) {
                continue;
            }

            $assessment = $word['PronunciationAssessment'] ?? $word;

            $out[] = [
                'word' => (string) $word['Word'],
                'accuracy_score' => isset($assessment['AccuracyScore'])
                    ? (float) $assessment['AccuracyScore']
                    : null,
                'error_type' => (string) ($assessment['ErrorType'] ?? 'None'),
                'offset_ms' => isset($word['Offset'])
                    ? (int) round($word['Offset'] / self::TICKS_PER_MS)
                    : null,
                'duration_ms' => isset($word['Duration'])
                    ? (int) round($word['Duration'] / self::TICKS_PER_MS)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Diction: how clearly the pupil articulated the words they said.
     *
     * Azure has no diction dimension of its own. Accuracy asks "was this the
     * right word, said acceptably?"; diction asks how cleanly each *sound* in
     * it came out, so it is the mean phoneme accuracy across the words that
     * were actually spoken. Omitted words were never said and inserted ones
     * are not on the page, so neither says anything about articulation. A word
     * Azure sent back without phonemes falls back to its word accuracy.
     *
     * @param  array<string, mixed>  $best
     */
    private function diction(array $best): ?float
    {
        $words = $best['PronunciationAssessment']['Words'] ?? $best['Words'] ?? [];

        if (! is_array($words)) {
            return null;
        }

        $scores = [];

        foreach ($words as $word) {
            $assessment = $word['PronunciationAssessment'] ?? $word;
            $errorType = $assessment['ErrorType'] ?? 'None';

            if ($errorType === 'Omission' || $errorType === 'Insertion') {
                continue;
            }

            $phonemeScores = [];

            foreach ($word['Phonemes'] ?? [] as $phoneme) {
                $score = $phoneme['PronunciationAssessment']['AccuracyScore']
                    ?? $phoneme['AccuracyScore']
                    ?? null;

                if ($score !== null) {
                    $phonemeScores[] = (float) $score;
                }
            }

            if ($phonemeScores !== []) {
                array_push($scores, ...$phonemeScores);
            } elseif (isset($assessment['AccuracyScore'])) {
                $scores[] = (float) $assessment['AccuracyScore'];
            }
        }

        return $scores === [] ? null : round(array_sum($scores) / count($scores), 2);
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
     * @param  array<string, mixed>  $best
     */
    private function matchedPageWords(array $best): ?string
    {
        $spoken = [];

        foreach ($this->words($best) as $word) {
            // An omission is a page word that went unread, not a spoken one.
            if ($word['error_type'] === 'Omission') {
                continue;
            }

            $spoken[] = $word['word'];
        }

        return $spoken === [] ? null : implode(' ', $spoken);
    }
}
