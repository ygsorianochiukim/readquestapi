<?php

namespace App\Domain\Ocr\Services;

use RuntimeException;

/**
 * Reads the words out of a whole PDF with OpenAI, one entry per page.
 *
 * This is the text-only path. Azure Vision takes a PDF too, but refuses one
 * over 4MB — and a scanned leveled reader is easily 10MB — whereas OpenAI takes
 * the file whole. This only transcribes; sorting the story from the footers,
 * credits and exercises is ChapterContentAgent's job afterwards.
 */
class OpenAiPdfReader
{
    private const INSTRUCTIONS = <<<'TEXT'
        You are transcribing a children's reading book, supplied as a PDF.

        Return exactly one entry per physical page of the PDF, in order, even for
        a page with no words (give it empty text). Never merge or split pages.
        Write every word printed on the page, in reading order.
        TEXT;

    public function __construct(private OpenAiClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return list<string> the text of each page, in order
     *
     * @throws RuntimeException when OpenAI refuses or cannot be reached.
     */
    public function readPages(string $pdfBytes, string $filename = 'book.pdf'): array
    {
        $answer = $this->client->structured(
            self::INSTRUCTIONS,
            [
                [
                    'type' => 'input_file',
                    'filename' => $filename,
                    'file_data' => 'data:application/pdf;base64,'.base64_encode($pdfBytes),
                ],
                ['type' => 'input_text', 'text' => 'Transcribe every page of this book.'],
            ],
            'book_pages',
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
                            'required' => ['page_number', 'text'],
                            'properties' => [
                                'page_number' => ['type' => 'integer'],
                                'text' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $pages = $answer['pages'] ?? null;

        if (! is_array($pages)) {
            throw new RuntimeException('OpenAI did not send back the pages of this PDF.');
        }

        usort($pages, fn (array $a, array $b) => ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0));

        return array_map(fn (array $page) => trim((string) ($page['text'] ?? '')), $pages);
    }
}
