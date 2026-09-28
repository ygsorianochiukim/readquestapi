<?php

namespace App\Domain\QuizQuestion\Services;

use App\Domain\Chapter\Models\Chapter;
use App\Domain\QuizQuestion\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Writes a chapter's quiz from its own story text.
 *
 * Teachers were typing every question and every choice by hand. This builds
 * them instead — deterministically, with no language model behind it, so the
 * same chapter always gets the same quiz and nothing leaves the server:
 *
 *  - "Who" questions, from sentences that open with a character's name
 *    ("Ana ran to the market." → "Who ran to the market?"), with the other
 *    names in the story as the wrong choices.
 *  - Fill-in-the-blank questions: a content word is blanked out of a sentence
 *    and the wrong choices are other words from the same chapter of a similar
 *    length, so the child has to understand the sentence rather than spot the
 *    odd one out.
 *
 * A teacher can still correct or delete a generated question; an edited one
 * counts as theirs and is never overwritten by a regenerate.
 */
class QuizGeneratorService
{
    /** Enough to check understanding without turning a chapter into a test. */
    public const MAX_QUESTIONS = 5;

    private const MAX_WHO_QUESTIONS = 2;

    /** Shorter words are mostly grammar, and make trivial blanks. */
    private const MIN_WORD_LENGTH = 4;

    /** A sentence has to be long enough to give the blank some context. */
    private const MIN_SENTENCE_WORDS = 5;

    private const MAX_SENTENCE_WORDS = 30;

    private const BLANK = '_____';

    /**
     * Words that carry grammar rather than meaning, in English and Filipino
     * (DepEd readers come in both). Never blanked, never offered as a name.
     */
    private const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'so', 'if', 'then', 'than', 'that', 'this', 'these', 'those',
        'is', 'am', 'are', 'was', 'were', 'be', 'been', 'being', 'do', 'does', 'did', 'done', 'have', 'has',
        'had', 'having', 'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might', 'must',
        'i', 'me', 'my', 'mine', 'we', 'us', 'our', 'ours', 'you', 'your', 'yours', 'he', 'him', 'his',
        'she', 'her', 'hers', 'it', 'its', 'they', 'them', 'their', 'theirs', 'who', 'whom', 'whose',
        'what', 'which', 'when', 'where', 'why', 'how', 'there', 'here', 'to', 'of', 'in', 'on', 'at',
        'by', 'for', 'with', 'from', 'into', 'onto', 'upon', 'about', 'over', 'under', 'after', 'before',
        'again', 'very', 'just', 'also', 'too', 'not', 'no', 'yes', 'all', 'any', 'each', 'every', 'some',
        'many', 'much', 'more', 'most', 'other', 'another', 'such', 'only', 'own', 'same', 'out', 'up',
        'down', 'off', 'said', 'says', 'went', 'come', 'came', 'going', 'into', 'once', 'while', 'because',
        'until', 'said', 'mr', 'mrs', 'ms', 'oh', 'ah', 'let', 'lets', 'one', 'two', 'three', 'chapter',
        'ang', 'ng', 'mga', 'sa', 'na', 'at', 'ay', 'si', 'ni', 'kay', 'siya', 'sila', 'ako', 'ikaw', 'ka',
        'ko', 'mo', 'niya', 'nila', 'namin', 'natin', 'ninyo', 'ito', 'iyan', 'iyon', 'dito', 'diyan',
        'doon', 'para', 'pero', 'kung', 'hindi', 'oo', 'din', 'rin', 'lang', 'lamang', 'pa', 'po', 'ba',
        'nang', 'may', 'mayroon', 'wala', 'kanyang', 'kaniyang', 'kanila', 'akin', 'amin', 'atin', 'inyo',
        'kami', 'tayo', 'kayo', 'isang', 'isa', 'ngunit', 'dahil', 'upang', 'kaya', 'saka', 'tapos',
        'habang', 'noon', 'ngayon', 'rito', 'roon', 'sabi', 'aking', 'iyong', 'ating', 'aralin', 'kabanata',
    ];

    /**
     * Make questions for a freshly scanned chapter, unless the teacher has
     * already written or edited some — their quiz is never replaced unasked.
     *
     * @return int how many questions were created
     */
    public function generateIfUncurated(Chapter $chapter): int
    {
        if ($chapter->quizQuestions()->where('is_generated', false)->exists()) {
            return 0;
        }

        return $this->regenerate($chapter);
    }

    /**
     * Throw away the generated questions and write them again from the
     * chapter's current text. Questions a teacher wrote or edited are kept.
     *
     * @return int how many questions were created
     */
    public function regenerate(Chapter $chapter): int
    {
        $questions = $this->build((string) $chapter->story_text);

        DB::transaction(function () use ($chapter, $questions) {
            $chapter->quizQuestions()->where('is_generated', true)->delete();

            foreach ($questions as $question) {
                QuizQuestion::create($question + [
                    'chapter_id' => $chapter->id,
                    'is_generated' => true,
                ]);
            }
        });

        return count($questions);
    }

    /**
     * The questions for a piece of text, without saving anything.
     *
     * @return list<array{question_text: string, choices: list<string>, correct_answer: string}>
     */
    public function build(string $text): array
    {
        $sentences = $this->sentences($text);

        if ($sentences === []) {
            return [];
        }

        $names = $this->names($sentences);
        $vocabulary = $this->vocabulary($sentences, $names);

        $who = $this->whoQuestions($sentences, $names);
        $usedSentences = array_keys($who);

        $cloze = $this->clozeQuestions($sentences, $vocabulary, $usedSentences);

        $picked = $who + $this->spreadOut($cloze, self::MAX_QUESTIONS - count($who));
        ksort($picked);

        return array_values($picked);
    }

    // ============================================================
    //  Internals
    // ============================================================

    /**
     * The text as sentences, each with its words and where they sit.
     *
     * @return list<array{text: string, words: list<array{0: string, 1: int}>}>
     */
    private function sentences(string $text): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($clean === '') {
            return [];
        }

        $sentences = [];

        foreach (preg_split('/(?<=[.!?])\s+/u', $clean) ?: [] as $sentence) {
            $sentence = trim($sentence);
            preg_match_all("/\p{L}[\p{L}'’\-]*/u", $sentence, $matches, PREG_OFFSET_CAPTURE);

            if ($matches[0] === []) {
                continue;
            }

            $sentences[] = ['text' => $sentence, 'words' => $matches[0]];
        }

        return $sentences;
    }

    /**
     * Characters' names: capitalised words in the middle of a sentence (the
     * first word of a sentence is capitalised whatever it is).
     *
     * @param  list<array{text: string, words: list<array{0: string, 1: int}>}>  $sentences
     * @return array<string, int>  name => first sentence it appears in
     */
    private function names(array $sentences): array
    {
        $names = [];
        $openers = [];
        $lowercase = [];

        foreach ($sentences as $index => $sentence) {
            foreach ($sentence['words'] as $position => [$word, $offset]) {
                if (! $this->isCapitalised($word)) {
                    $lowercase[$word] = true;

                    continue;
                }

                if ($this->isStopword($word)) {
                    continue;
                }

                if ($position === 0) {
                    $openers[$word][] = $index;

                    continue;
                }

                // A capital straight after an opening quote starts a line of
                // dialogue ("Run!"), not a name.
                $before = rtrim(substr($sentence['text'], 0, $offset));
                if ($before !== '' && preg_match('/["“‘\'(]$/u', $before)) {
                    continue;
                }

                $names[$word] ??= $index;
            }
        }

        // A name that only ever opens sentences ("Maria ran… Maria counted…")
        // still counts, as long as it opens more than one and never appears
        // in lower case (which would make it an ordinary word).
        foreach ($openers as $word => $indexes) {
            if (count($indexes) >= 2 && ! isset($lowercase[mb_strtolower($word)])) {
                $names[$word] = min($names[$word] ?? PHP_INT_MAX, $indexes[0]);
            }
        }

        asort($names);

        return $names;
    }

    /**
     * Content words worth blanking, lower-cased.
     *
     * @param  list<array{text: string, words: list<array{0: string, 1: int}>}>  $sentences
     * @param  array<string, int>  $names
     * @return array<string, int>  word => order of first appearance
     */
    private function vocabulary(array $sentences, array $names): array
    {
        $nameKeys = array_map('mb_strtolower', array_keys($names));
        $vocabulary = [];

        foreach ($sentences as $sentence) {
            foreach ($sentence['words'] as [$word]) {
                $lower = mb_strtolower($word);

                if (mb_strlen($lower) < self::MIN_WORD_LENGTH
                    || $this->isStopword($lower)
                    || in_array($lower, $nameKeys, true)
                    || preg_match("/['’\-]/u", $lower)) {
                    continue;
                }

                $vocabulary[$lower] ??= count($vocabulary);
            }
        }

        return $vocabulary;
    }

    /**
     * @param  list<array{text: string, words: list<array{0: string, 1: int}>}>  $sentences
     * @param  array<string, int>  $names
     * @return array<int, array{question_text: string, choices: list<string>, correct_answer: string}>
     */
    private function whoQuestions(array $sentences, array $names): array
    {
        if (count($names) < 2) {
            return [];
        }

        $questions = [];
        $askedAbout = [];

        foreach ($sentences as $index => $sentence) {
            if (count($questions) >= self::MAX_WHO_QUESTIONS) {
                break;
            }

            $words = $sentence['words'];
            [$first, $offset] = $words[0];

            if (! isset($names[$first]) || isset($askedAbout[$first]) || $offset !== 0 || count($words) < 4) {
                continue;
            }

            $rest = trim(substr($sentence['text'], strlen($first)));
            $rest = rtrim($rest, " .!?\"”’'");

            // "Ana, the girl, ran…" reads badly as "Who , the girl…" — only
            // take a sentence where the name is followed straight by its verb.
            if ($rest === '' || ! preg_match('/^\p{Ll}/u', $rest)) {
                continue;
            }

            $others = array_keys(array_filter(
                $names,
                fn (int $firstSeen, string $name) => $name !== $first,
                ARRAY_FILTER_USE_BOTH,
            ));
            $choices = array_merge([$first], array_slice($others, 0, 3));

            $question = "Who {$rest}?";
            $questions[$index] = [
                'question_text' => $question,
                'choices' => $this->shuffle($choices, $question),
                'correct_answer' => $first,
            ];
            $askedAbout[$first] = true;
        }

        return $questions;
    }

    /**
     * @param  list<array{text: string, words: list<array{0: string, 1: int}>}>  $sentences
     * @param  array<string, int>  $vocabulary
     * @param  list<int>  $skip  sentences already used for another question
     * @return array<int, array{question_text: string, choices: list<string>, correct_answer: string}>
     */
    private function clozeQuestions(array $sentences, array $vocabulary, array $skip): array
    {
        $questions = [];
        $usedAnswers = [];

        foreach ($sentences as $index => $sentence) {
            $count = count($sentence['words']);

            if (in_array($index, $skip, true)
                || $count < self::MIN_SENTENCE_WORDS
                || $count > self::MAX_SENTENCE_WORDS) {
                continue;
            }

            $inSentence = array_map(fn (array $word) => mb_strtolower($word[0]), $sentence['words']);

            // The longest content word makes the most telling blank.
            $target = null;
            foreach ($sentence['words'] as [$word, $offset]) {
                $lower = mb_strtolower($word);

                if (! isset($vocabulary[$lower]) || isset($usedAnswers[$lower])) {
                    continue;
                }

                if ($target === null || mb_strlen($lower) > mb_strlen($target[0])) {
                    $target = [$lower, $offset, $word];
                }
            }

            if ($target === null) {
                continue;
            }

            [$answer, $offset, $original] = $target;
            $distractors = $this->distractors($answer, $vocabulary, $inSentence);

            if (count($distractors) < 2) {
                continue;
            }

            $blanked = substr_replace($sentence['text'], self::BLANK, $offset, strlen($original));
            $question = "Fill in the blank: {$blanked}";

            $questions[$index] = [
                'question_text' => $question,
                'choices' => $this->shuffle(array_merge([$answer], $distractors), $question),
                'correct_answer' => $answer,
            ];
            $usedAnswers[$answer] = true;
        }

        return $questions;
    }

    /**
     * Wrong choices: other words from the chapter, closest in length first, so
     * the right answer does not stand out by its size.
     *
     * @param  array<string, int>  $vocabulary
     * @param  list<string>  $inSentence  words already in the sentence (they would fit too well)
     * @return list<string>
     */
    private function distractors(string $answer, array $vocabulary, array $inSentence): array
    {
        $candidates = array_filter(
            array_keys($vocabulary),
            fn (string $word) => $word !== $answer && ! in_array($word, $inSentence, true),
        );

        $length = mb_strlen($answer);
        usort($candidates, fn (string $a, string $b) => [abs(mb_strlen($a) - $length), $vocabulary[$a]]
            <=> [abs(mb_strlen($b) - $length), $vocabulary[$b]]);

        return array_slice(array_values($candidates), 0, 3);
    }

    /**
     * Pick up to $limit questions spread across the whole chapter, not just
     * its opening lines.
     *
     * @param  array<int, array<string, mixed>>  $questions  keyed by sentence index
     * @return array<int, array<string, mixed>>
     */
    private function spreadOut(array $questions, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        if (count($questions) <= $limit) {
            return $questions;
        }

        $keys = array_keys($questions);
        $picked = [];

        for ($i = 0; $i < $limit; $i++) {
            $key = $keys[$limit === 1 ? 0 : (int) round($i * (count($keys) - 1) / ($limit - 1))];
            $picked[$key] = $questions[$key];
        }

        return $picked;
    }

    /**
     * Mix the choices so the answer is not always first — but the same way
     * every time, so a regenerate of unchanged text gives the same quiz.
     *
     * @param  list<string>  $choices
     * @return list<string>
     */
    private function shuffle(array $choices, string $seed): array
    {
        $choices = array_values(array_unique($choices));
        usort($choices, fn (string $a, string $b) => crc32($seed.'|'.$a) <=> crc32($seed.'|'.$b));

        return $choices;
    }

    private function isCapitalised(string $word): bool
    {
        return preg_match('/^\p{Lu}/u', $word) === 1;
    }

    private function isStopword(string $word): bool
    {
        return in_array(mb_strtolower($word), self::STOPWORDS, true);
    }
}
