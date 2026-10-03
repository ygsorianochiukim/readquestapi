<?php

namespace App\Domain\Book\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Ocr\Services\PageReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class BookPageService
{
    public function __construct(private PageReader $ocr) {}

    /**
     * @return Collection<int, BookPage>
     */
    public function forBook(Book $book): Collection
    {
        return $book->pages()->get();
    }

    /**
     * Store an uploaded page image, run OCR on it (best-effort), and create
     * the page. OCR failures do not block the upload — the teacher can re-scan
     * the page or fix the text afterwards.
     *
     * Every page belongs to a chapter: the one asked for, else the book's last
     * chapter, else a first chapter made for it.
     */
    public function createFromUpload(Book $book, UploadedFile $file, ?Chapter $chapter = null): BookPage
    {
        $imageBytes = file_get_contents($file->getRealPath());
        $path = $file->store("book-pages/{$book->id}", 'public');

        $text = null;
        if ($this->ocr->isConfigured()) {
            try {
                $text = $this->ocr->extractText($imageBytes);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $chapter ??= $book->chapters()->reorder('chapter_number', 'desc')->first()
            ?? $book->chapters()->create(['chapter_number' => 1, 'title' => 'Chapter 1']);

        $nextNumber = ($book->pages()->max('page_number') ?? 0) + 1;

        return $book->pages()->create([
            'chapter_id'  => $chapter->id,
            'page_number' => $nextNumber,
            'image_path'  => $path,
            'text'        => blank($text) ? null : $text,
        ]);
    }

    /**
     * Read a page again — from a replacement photo when one is given (a blurry
     * or cropped scan), otherwise from the image already stored. This is how a
     * page that came back with no words gets fixed, instead of typing them in.
     *
     * @throws RuntimeException when scanning is not set up, there is nothing
     *                          to read, or the scan fails.
     */
    public function rescan(BookPage $page, ?UploadedFile $replacement = null): BookPage
    {
        if (! $this->ocr->isConfigured()) {
            throw new RuntimeException('Scanning is not set up yet. Add AZURE_VISION_KEY and AZURE_VISION_ENDPOINT to enable it.');
        }

        if ($replacement) {
            $bytes = (string) file_get_contents($replacement->getRealPath());
            $newPath = $replacement->store("book-pages/{$page->book_id}", 'public');

            if ($page->image_path) {
                Storage::disk('public')->delete($page->image_path);
            }

            $page->image_path = $newPath;
        } elseif ($page->image_path && Storage::disk('public')->exists($page->image_path)) {
            $bytes = (string) Storage::disk('public')->get($page->image_path);
        } else {
            throw new RuntimeException('This page has no picture to scan. Upload a photo of the page instead.');
        }

        $text = $this->ocr->extractText($bytes);

        $page->text = blank($text) ? null : $text;
        $page->save();

        return $page->refresh();
    }

    /**
     * Add a page by typing its sentences — for a chapter whose words the
     * teacher has but no scan of, or a page they want to write themselves.
     */
    public function createText(Book $book, Chapter $chapter, string $text): BookPage
    {
        return $book->pages()->create([
            'chapter_id' => $chapter->id,
            'page_number' => ($book->pages()->max('page_number') ?? 0) + 1,
            'image_path' => null,
            'text' => trim($text),
        ]);
    }

    /**
     * Cut a chapter's story text into pages of a few sentences each, so a
     * chapter that came in as one block of words reads a page at a time.
     *
     * @return Collection<int, BookPage>
     *
     * @throws RuntimeException when the chapter has no words, or already has pages.
     */
    public function generateForChapter(Chapter $chapter, int $sentencesPerPage = 5): Collection
    {
        if (blank($chapter->story_text)) {
            throw new RuntimeException('This chapter has no story text to make pages from. Add a page and type its sentences instead.');
        }

        if ($chapter->pages()->exists()) {
            throw new RuntimeException('This chapter already has pages. Delete them first to make new ones from the story text.');
        }

        $book = $chapter->book;
        $next = (int) ($book->pages()->max('page_number') ?? 0);
        $created = new Collection;

        foreach (array_chunk(self::sentences($chapter->story_text), max(1, $sentencesPerPage)) as $chunk) {
            $created->push($book->pages()->create([
                'chapter_id' => $chapter->id,
                'page_number' => ++$next,
                'image_path' => null,
                'text' => implode("\n", $chunk),
            ]));
        }

        return $created;
    }

    /**
     * The story's sentences, in order. A sentence ends at . ! or ? (with any
     * closing quote after it) or at a line break — but not at "Mrs." and the
     * like, nor before a lowercase word (“Very good!” says Mrs. Post). A
     * question or cry in quotes keeps the words that tell who said it:
     * “What is it?” Mrs. Post asks.
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $sentences = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $joinNext = false;

            foreach (preg_split('/(?<=[.!?])(?<!\bMrs\.)(?<!\bMr\.)(?<!\bMs\.)(?<!\bDr\.)(?<!\bSt\.)["\'”’)]*\K\s+(?!\p{Ll})/u', trim($line)) ?: [] as $sentence) {
                $sentence = trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence);

                if ($sentence === '') {
                    continue;
                }

                if ($joinNext && ! preg_match('/^["“‘\']/u', $sentence)) {
                    $sentences[array_key_last($sentences)] .= ' '.$sentence;
                } else {
                    $sentences[] = $sentence;
                }

                $joinNext = (bool) preg_match('/[!?]["”’\']$/u', $sentences[array_key_last($sentences)]);
            }
        }

        return $sentences;
    }

    public function updateText(BookPage $page, ?string $text): BookPage
    {
        $page->update(['text' => $text]);

        return $page->refresh();
    }

    public function delete(BookPage $page): void
    {
        if ($page->image_path) {
            Storage::disk('public')->delete($page->image_path);
        }

        $page->delete();
    }
}
