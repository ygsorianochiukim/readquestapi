<?php

namespace App\Domain\Ingest\Services;

/**
 * Works out where the chapters are in a pile of OCR'd pages.
 *
 * Teachers were typing this structure in by hand. The heuristics below are
 * deliberately conservative: a wrong guess that the teacher has to undo in the
 * preview is worse than no guess at all, so when nothing looks like a chapter
 * heading the whole book becomes one chapter and the teacher splits it if they
 * want to.
 */
class ChapterSegmenter
{
    /**
     * Headings we are confident about: "Chapter 3", "CHAPTER THREE",
     * "Unit 2", "Part 4", "Aralin 5" (the Filipino curriculum's word for a
     * lesson, which is what a DepEd leveled reader actually uses).
     */
    private const HEADING = '/^\s*(chapter|unit|part|lesson|aralin|kabanata)\s+([0-9]{1,3}|[ivxlc]{1,7}|one|two|three|four|five|six|seven|eight|nine|ten)\b[\s:.\-]*(.*)$/i';

    /** Pages shorter than this are covers, blanks or illustrations, not prose. */
    private const MIN_WORDS_FOR_CONTENT = 5;

    /**
     * Front matter — a cover, a title page, a dedication — sits before the
     * first real heading and is short. Anything longer than this is a story
     * that happens to start before a heading, and keeping it is the safer bet.
     */
    private const MAX_WORDS_FOR_FRONT_MATTER = 40;

    /** A picture book without headings up to this long stays one chapter. */
    private const MAX_PAGES_SINGLE_CHAPTER = 12;

    /** Roughly how many pages go in each part of a longer one. */
    private const PAGES_PER_PART = 8;

    /**
     * Group pages into chapters.
     *
     * @param  list<array{page_number: int, text: ?string}>  $pages
     * @return list<array{title: string, chapter_number: int, page_numbers: list<int>, story_text: string}>
     */
    public function segment(array $pages): array
    {
        $chapters = [];
        $currentIndex = null;

        foreach ($pages as $page) {
            $text = trim((string) ($page['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $heading = $this->headingIn($text);

            // A heading opens a new chapter; so does the first page with words
            // on it, because everything before a first heading still has to
            // live somewhere.
            if ($heading !== null || $currentIndex === null) {
                $chapters[] = [
                    'title' => $heading ?? 'Chapter 1',
                    'chapter_number' => count($chapters) + 1,
                    'page_numbers' => [],
                    'story_text' => '',
                    'from_heading' => $heading !== null,
                ];
                $currentIndex = count($chapters) - 1;
            }

            $chapters[$currentIndex]['page_numbers'][] = $page['page_number'];
            $chapters[$currentIndex]['story_text'] = trim(
                $chapters[$currentIndex]['story_text']."\n\n".$this->body($text)
            );
        }

        // A cover or title page opened the first chapter only because something
        // had to hold it. Once a real heading has been found, that opening
        // stretch is front matter, and a child should not be asked to read the
        // author's name as chapter one.
        if (count($chapters) > 1
            && ! $chapters[0]['from_heading']
            && str_word_count($chapters[0]['story_text']) <= self::MAX_WORDS_FOR_FRONT_MATTER) {
            array_shift($chapters);
        }

        // Drop anything else that turned out to be a page with a title and no
        // story under it — it would be a chapter a child cannot read.
        $chapters = array_values(array_filter(
            $chapters,
            fn (array $chapter) => str_word_count($chapter['story_text']) >= self::MIN_WORDS_FOR_CONTENT,
        ));

        // Renumber after filtering, so chapter numbers are always 1..n.
        foreach ($chapters as $index => $chapter) {
            unset($chapters[$index]['from_heading']);
            $chapters[$index]['chapter_number'] = $index + 1;

            if ($chapters[$index]['title'] === '') {
                $chapters[$index]['title'] = 'Chapter '.($index + 1);
            }
        }

        return $chapters;
    }

    /**
     * Group a picture book's pages into chapters.
     *
     * Unlike segment(), every page lands somewhere — a picture page with no
     * words is still part of the book. Chapter headings split it where there
     * are any (pages before the first heading join the first chapter); a book
     * without headings stays one chapter when it is short, and is cut into
     * even parts of about PAGES_PER_PART pages when it is not.
     *
     * @param  list<array{page_number: int, text: ?string}>  $pages  in page order
     * @return list<array{title: string, chapter_number: int, page_numbers: list<int>}>
     */
    public function groupPages(array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $groups = [];

        foreach ($pages as $page) {
            $heading = $this->headingIn(trim((string) ($page['text'] ?? '')));

            if ($groups === []) {
                $groups[] = ['title' => $heading, 'page_numbers' => [], 'from_heading' => $heading !== null];
            } elseif ($heading !== null && count($groups) === 1 && ! $groups[0]['from_heading']) {
                // Everything so far was front matter (a cover, a title page):
                // it opens the first chapter, which takes this heading's name.
                $groups[0]['title'] = $heading;
                $groups[0]['from_heading'] = true;
            } elseif ($heading !== null) {
                $groups[] = ['title' => $heading, 'page_numbers' => [], 'from_heading' => true];
            }

            $groups[count($groups) - 1]['page_numbers'][] = $page['page_number'];
        }

        $foundHeadings = collect($groups)->contains('from_heading', true);

        if (! $foundHeadings) {
            $groups = $this->evenParts(array_column($pages, 'page_number'));
        }

        $chapters = [];
        foreach ($groups as $index => $group) {
            $chapters[] = [
                'title' => $group['title'] ?: 'Chapter '.($index + 1),
                'chapter_number' => $index + 1,
                'page_numbers' => $group['page_numbers'],
            ];
        }

        return $chapters;
    }

    /** The chapter heading a page opens with ("Chapter 2: The River"), if any. */
    public function headingOf(string $text): ?string
    {
        return $this->headingIn(trim($text));
    }

    /** A page's text without its chapter heading line, if it opens with one. */
    public function bodyOf(string $text): string
    {
        return $this->body(trim($text));
    }

    /**
     * A title for the whole book, from the first page that has words on it.
     * Falls back to the file's own name, which the caller supplies.
     */
    public function guessTitle(array $pages, string $fallback): string
    {
        foreach ($pages as $page) {
            foreach (preg_split('/\r?\n/', (string) ($page['text'] ?? '')) ?: [] as $line) {
                $line = trim($line);

                // A cover title is a short line of words — not a page number,
                // not a paragraph, not an ISBN.
                if (mb_strlen($line) >= 3
                    && mb_strlen($line) <= 60
                    && preg_match('/\p{L}/u', $line)
                    && ! preg_match('/^\d+$/', $line)) {
                    return $this->titleCase($line);
                }
            }
        }

        return $fallback;
    }

    /**
     * A book with no headings: one chapter if it is short, otherwise even
     * parts, so a long picture book is not one endless chapter.
     *
     * @param  list<int>  $pageNumbers
     * @return list<array{title: string, page_numbers: list<int>, from_heading: bool}>
     */
    private function evenParts(array $pageNumbers): array
    {
        $count = count($pageNumbers);

        if ($count <= self::MAX_PAGES_SINGLE_CHAPTER) {
            return [['title' => 'Chapter 1', 'page_numbers' => $pageNumbers, 'from_heading' => false]];
        }

        // Balanced sizes: 20 pages become 7 + 7 + 6, not 8 + 8 + 4.
        $parts = (int) ceil($count / self::PAGES_PER_PART);
        $size = (int) ceil($count / $parts);

        return array_map(
            fn (array $chunk, int $index) => [
                'title' => 'Part '.($index + 1),
                'page_numbers' => $chunk,
                'from_heading' => false,
            ],
            array_chunk($pageNumbers, $size),
            range(0, (int) ceil($count / $size) - 1),
        );
    }

    /** The chapter title on this page, or null when it does not open one. */
    private function headingIn(string $text): ?string
    {
        $firstLine = trim(strtok($text, "\n") ?: '');

        if (! preg_match(self::HEADING, $firstLine, $matches)) {
            return null;
        }

        $label = $this->titleCase(trim($matches[1]).' '.trim($matches[2]));
        $rest = trim($matches[3] ?? '');

        return $rest === '' ? $label : "{$label}: {$this->titleCase($rest)}";
    }

    /** The page's text with its heading line removed, if it had one. */
    private function body(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];

        if ($lines !== [] && preg_match(self::HEADING, trim($lines[0]))) {
            array_shift($lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * OCR often returns headings in block capitals. Left as-is they shout at
     * the child from every chapter card.
     */
    private function titleCase(string $text): string
    {
        return preg_match('/\p{Ll}/u', $text) === 1
            ? $text
            : mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8');
    }
}
