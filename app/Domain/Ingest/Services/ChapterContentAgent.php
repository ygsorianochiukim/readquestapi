<?php

namespace App\Domain\Ingest\Services;

use App\Domain\Ocr\Services\OpenAiClient;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps only the chapters' own words out of everything a scan picked up.
 *
 * A scanned leveled reader is mostly not story: a cover, a credits page, a
 * copyright footer and page number on every page, "Skill Builder" exercises
 * after each chapter, and a back cover. OCR — and a model asked only to
 * transcribe — faithfully keeps all of it. This reads the whole book the way a
 * teacher would, decides what each page is, and hands back the story pages
 * alone, cleaned, with each chapter's heading as the first line of the page
 * that opens it so ChapterSegmenter can split the book on it.
 *
 * It only ever removes. If it cannot run, or finds no story at all, the pages
 * are passed through untouched rather than losing the teacher's upload.
 */
class ChapterContentAgent
{
    private const INSTRUCTIONS = <<<'TEXT'
        You prepare children's reading books for a reading app. You receive the
        raw text of every page of one book, as read by a scanner, in order.

        For every page, decide what it is:
        - "front_matter": cover, title page, credits, copyright, table of contents.
        - "story": the chapter text a child reads.
        - "activity": exercises, "Skill Builder", word lists, questions, games, "Extra Fun".
        - "back_matter": back cover, about the author, publisher notes.
        - "blank": nothing a child would read.

        For a "story" page, give its text cleaned:
        - Keep the story's own words, spelling, punctuation and quotation marks exactly.
        - Remove running headers and footers, copyright or "not for sale" notices,
          printed page numbers, and repeated book titles.
        - Join lines that were only wrapped; keep a blank line between paragraphs.
        - Do not summarise, correct, reword or add anything.
        For any other kind of page, give empty text.

        If a story page opens a chapter, give that chapter's heading as printed,
        for example "Chapter 2" or "Chapter 2: The Waterfall", and leave the
        heading out of the text. Otherwise give an empty heading. A heading that
        only appears in a footer or a running header does not open a chapter.
        TEXT;

    private const KINDS = ['front_matter', 'story', 'activity', 'back_matter', 'blank'];

    public function __construct(private OpenAiClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @param  list<array{image_path: ?string, text: ?string}>  $pages
     * @return list<array{image_path: ?string, text: ?string}> the story pages only
     */
    public function keepChapterContent(array $pages): array
    {
        if (! $this->isConfigured() || $pages === []) {
            return $pages;
        }

        try {
            $verdicts = $this->analyse($pages);
        } catch (Throwable $exception) {
            // Sorting is an improvement, not a requirement: the raw pages still
            // make a book the teacher can fix in the preview.
            report($exception);

            return $pages;
        }

        $kept = [];
        $dropped = [];

        foreach ($pages as $index => $page) {
            $verdict = $verdicts[$index + 1] ?? null;
            $text = trim((string) ($verdict['text'] ?? ''));

            if (($verdict['kind'] ?? null) !== 'story' || $text === '') {
                $dropped[] = $page;

                continue;
            }

            $heading = trim((string) ($verdict['chapter'] ?? ''));

            $kept[] = [
                'image_path' => $page['image_path'],
                'text' => $heading === '' ? $text : "{$heading}\n{$text}",
            ];
        }

        // Finding no story at all means the verdicts are wrong, not the book.
        if ($kept === []) {
            return $pages;
        }

        foreach ($dropped as $page) {
            if ($page['image_path']) {
                Storage::disk('public')->delete($page['image_path']);
            }
        }

        return $kept;
    }

    /**
     * What each page is, keyed by its 1-based position in the upload.
     *
     * @param  list<array{image_path: ?string, text: ?string}>  $pages
     * @return array<int, array{kind: string, chapter: string, text: string}>
     */
    private function analyse(array $pages): array
    {
        $book = array_map(
            fn (array $page, int $index) => ['page' => $index + 1, 'text' => (string) ($page['text'] ?? '')],
            $pages,
            array_keys($pages),
        );

        $answer = $this->client->structured(
            self::INSTRUCTIONS,
            [[
                'type' => 'input_text',
                'text' => "The book's pages, as JSON:\n".json_encode($book, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]],
            'chapter_content',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['pages'],
                'properties' => [
                    'pages' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['page', 'kind', 'chapter', 'text'],
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'kind' => ['type' => 'string', 'enum' => self::KINDS],
                                'chapter' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            config('services.openai.agent_model'),
        );

        $verdicts = [];
        foreach ($answer['pages'] ?? [] as $verdict) {
            if (is_array($verdict) && isset($verdict['page'])) {
                $verdicts[(int) $verdict['page']] = $verdict;
            }
        }

        return $verdicts;
    }
}
