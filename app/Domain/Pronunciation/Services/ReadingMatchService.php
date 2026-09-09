<?php

namespace App\Domain\Pronunciation\Services;

/**
 * How much of what the pupil actually said matches the words on the page.
 *
 * Azure's pronunciation assessment is *told* the reference text up front, so
 * its scores describe how well the audio aligns to that text — not whether the
 * pupil read it. A child who says something else entirely can still come back
 * with a good score. This service is the independent check: it compares the
 * recognised words against the page words, so an off-script reading can never
 * score as a good one.
 */
class ReadingMatchService
{
    /** Longer passages are trimmed to keep the alignment cheap. */
    private const MAX_WORDS = 1200;

    /**
     * Compare a reading against the page it was meant to be.
     *
     * `match` is overall agreement, 0-100: it drops both for page words that
     * went unsaid and for said words that are not on the page. `on_page` is the
     * share of what the pupil said that is on the page — that is what separates
     * reading something else (low) from reading only part of the page (high
     * `on_page`, low `match`).
     *
     * @return array{match: float, on_page: float}
     */
    public function evaluate(?string $referenceText, ?string $recognizedText): array
    {
        $reference = $this->words($referenceText);
        $spoken = $this->words($recognizedText);

        if ($reference === [] || $spoken === []) {
            return ['match' => 0.0, 'on_page' => 0.0];
        }

        $matched = $this->commonWordCount($reference, $spoken);

        if ($matched === 0) {
            return ['match' => 0.0, 'on_page' => 0.0];
        }

        $recall = $matched / count($reference);
        $precision = $matched / count($spoken);

        return [
            // Recall punishes skipping the page, precision punishes saying
            // things that are not on it; their harmonic mean needs both.
            'match' => round(2 * $precision * $recall / ($precision + $recall) * 100, 2),
            'on_page' => round($precision * 100, 2),
        ];
    }

    /**
     * Lowercased words with punctuation stripped, so "Fox!" and "fox" match.
     *
     * @return list<string>
     */
    private function words(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        $normalized = preg_replace("/[^\p{L}\p{N}']+/u", ' ', mb_strtolower($text));
        $words = preg_split('/\s+/', trim((string) $normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /**
     * Words the two texts share, in order — a longest common subsequence, so
     * saying the page's words out of order does not count as reading it.
     *
     * @param  list<string>  $reference
     * @param  list<string>  $spoken
     */
    private function commonWordCount(array $reference, array $spoken): int
    {
        $spokenCount = count($spoken);
        // Only the previous row of the table is ever needed, so keep just that.
        $previous = array_fill(0, $spokenCount + 1, 0);

        foreach ($reference as $referenceWord) {
            $current = [0];
            for ($column = 1; $column <= $spokenCount; $column++) {
                $current[$column] = $referenceWord === $spoken[$column - 1]
                    ? $previous[$column - 1] + 1
                    : max($previous[$column], $current[$column - 1]);
            }
            $previous = $current;
        }

        return $previous[$spokenCount];
    }
}
