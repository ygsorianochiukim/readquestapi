<?php

namespace App\Domain\Pronunciation\Services;

/**
 * How fast the child read, and whether that was too slow, about right, or too
 * fast for their reading level.
 *
 * The pace is measured from Azure's per-word offsets, not from the length of
 * the recording. A child who taps record, hesitates for eight seconds, reads
 * fluently, then takes another four seconds to stop is reading at a fine pace;
 * wall-clock timing would call them painfully slow.
 */
class ReadingPaceService
{
    /**
     * Words-per-minute bands by reading level, as `[floor, ceiling]`.
     *
     * These follow the usual oral-reading-fluency norms for the grade a level
     * maps to. Below the floor the child is decoding word by word; above the
     * ceiling they are racing, which in practice means skipping punctuation and
     * not understanding what they read.
     *
     * @var array<int, array{int, int}>
     */
    private const BANDS = [
        1 => [30, 70],
        2 => [55, 95],
        3 => [75, 115],
        4 => [90, 135],
        5 => [100, 150],
        6 => [110, 160],
    ];

    /** Used when a book has no reading level, or one we cannot read a number out of. */
    private const DEFAULT_BAND = [50, 120];

    /**
     * Too short a sample says nothing about pace — three words in a second is
     * 180 wpm on paper and meaningless in fact.
     */
    private const MIN_WORDS = 5;

    private const MIN_DURATION_MS = 1500;

    /**
     * @param  list<array<string, mixed>>  $words  per-word results from the assessment
     * @return array{words_per_minute: ?int, pace: ?string}
     */
    public function evaluate(array $words, ?string $readingLevel): array
    {
        // Only words the child actually voiced count. Omissions have no
        // duration, and counting them would make a child who skipped half the
        // page look like a fast reader.
        // A word with no timing tells us nothing about pace, and Azure does
        // leave the offsets off some results — so a missing key is an ordinary
        // case here, not a broken one.
        $spoken = array_values(array_filter(
            $words,
            fn (array $word) => ($word['error_type'] ?? 'None') !== 'Omission'
                && ($word['offset_ms'] ?? null) !== null
                && ($word['duration_ms'] ?? null) !== null,
        ));

        if (count($spoken) < self::MIN_WORDS) {
            return ['words_per_minute' => null, 'pace' => null];
        }

        $first = $spoken[0];
        $last = $spoken[count($spoken) - 1];

        // First voiced sound to last — the silence on either end is the child
        // finding the record button, not reading.
        $elapsed = ($last['offset_ms'] + $last['duration_ms']) - $first['offset_ms'];

        if ($elapsed < self::MIN_DURATION_MS) {
            return ['words_per_minute' => null, 'pace' => null];
        }

        $wpm = (int) round(count($spoken) / ($elapsed / 60000));
        [$floor, $ceiling] = $this->band($readingLevel);

        return [
            'words_per_minute' => $wpm,
            'pace' => match (true) {
                $wpm < $floor => 'too_slow',
                $wpm > $ceiling => 'too_fast',
                default => 'good',
            },
        ];
    }

    /**
     * The target band for a reading level. Levels are free text on the book
     * ("Level 2", "Grade 3", "2"), so take the first number in them and fall
     * back to a wide band when there is none.
     *
     * @return array{int, int}
     */
    public function band(?string $readingLevel): array
    {
        if (blank($readingLevel) || ! preg_match('/\d+/', $readingLevel, $matches)) {
            return self::DEFAULT_BAND;
        }

        return self::BANDS[(int) $matches[0]] ?? self::DEFAULT_BAND;
    }

    /** Child-facing advice for a pace verdict. Null when the pace was fine. */
    public function hint(?string $pace): ?string
    {
        return match ($pace) {
            'too_slow' => 'Try reading a little faster — keep the words flowing.',
            'too_fast' => 'Slow down a little so every word is clear.',
            default => null,
        };
    }
}
