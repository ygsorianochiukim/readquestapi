<?php

namespace App\Domain\Chapter\Services;

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;

/**
 * A chapter's pages, cut into the paragraphs a child reads one at a time.
 *
 * The student app shows each paragraph as its own page of the flip book and
 * reads them aloud one by one, so this must cut them exactly the same way:
 * one paragraph per line, blank lines ignored, and a "Chapter 2" heading line
 * left out — it titles the page, it is not read.
 */
class ChapterParagraphs
{
    private const HEADING = '/^chapter\s+[\w-]+\b.{0,60}$/iu';

    /** @return list<string> */
    public function of(BookPage $page): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', (string) $page->text) ?: []),
            fn (string $line) => $line !== '',
        ));

        if ($lines !== [] && preg_match(self::HEADING, $lines[0])) {
            array_shift($lines);
        }

        return $lines;
    }

    /**
     * Every paragraph of the chapter, in reading order.
     *
     * @return list<array{book_page_id: int, paragraph_index: int, text: string}>
     */
    public function forChapter(Chapter $chapter): array
    {
        $paragraphs = [];

        foreach ($chapter->pages()->orderBy('page_number')->get() as $page) {
            foreach ($this->of($page) as $index => $text) {
                $paragraphs[] = [
                    'book_page_id' => $page->id,
                    'paragraph_index' => $index,
                    'text' => $text,
                ];
            }
        }

        return $paragraphs;
    }
}
