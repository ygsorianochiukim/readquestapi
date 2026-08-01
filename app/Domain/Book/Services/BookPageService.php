<?php

namespace App\Domain\Book\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookPage;
use App\Domain\Ocr\Services\OcrService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BookPageService
{
    public function __construct(private OcrService $ocr) {}

    /**
     * @return Collection<int, BookPage>
     */
    public function forBook(Book $book): Collection
    {
        return $book->pages()->get();
    }

    /**
     * Store an uploaded page image, run OCR on it (best-effort), and create
     * the page. OCR failures do not block the upload — the teacher can type
     * or fix the text afterwards.
     */
    public function createFromUpload(Book $book, UploadedFile $file): BookPage
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

        $nextNumber = ($book->pages()->max('page_number') ?? 0) + 1;

        return $book->pages()->create([
            'page_number' => $nextNumber,
            'image_path'  => $path,
            'text'        => $text,
        ]);
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
