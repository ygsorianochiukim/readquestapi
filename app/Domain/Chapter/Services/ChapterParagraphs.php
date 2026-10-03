<?php

namespace App\Domain\Chapter\Services;

use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;

/**
 * A chapter's pages, as the child reads them one at a time.
 *
 * Each page of the book is one page of reading — exactly the page the teacher
 * sees and edits, not a line of it. A "Chapter 2" heading line at the top is
 * left out: it titles the page, it is not read.
 *
 * The student app cuts pages the same way, and narration, read-aloud scoring
 * and progress all count on it, so a page is always "paragraph" 0 of itself.
 */
class ChapterParagraphs
{
    private const HEADING = '/^chapter\s+[\w-]+\b.{0,60}$/iu';

    /** @return list<string> the page's words, or nothing when it has none */
    public function of(BookPage $page): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', (string) $page->text) ?: []),
            fn (string $line) => $line !== '',
        ));

        if ($lines !== [] && preg_match(self::HEADING, $lines[0])) {
            array_shift($lines);
        }

        return $lines === [] ? [] : [implode("\n", $lines)];
    }

    /**
     * Every page of the chapter that has words, in reading order.
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
