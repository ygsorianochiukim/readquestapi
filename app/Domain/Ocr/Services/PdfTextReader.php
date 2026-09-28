<?php

namespace App\Domain\Ocr\Services;

use RuntimeException;

/**
 * The words of a whole PDF, one entry per page, for hosts that cannot turn
 * PDF pages into pictures.
 *
 * OpenAI is used when it has a key: it takes a PDF of any sensible size and
 * leaves out the footers printed on every page. Otherwise the PDF goes to
 * Azure Vision, which does the same job but refuses a file over 4MB.
 */
class PdfTextReader
{
    public function __construct(
        private OpenAiPdfReader $openAi,
        private OcrService $azure,
    ) {}

    public function isConfigured(): bool
    {
        return $this->openAi->isConfigured() || $this->azure->isConfigured();
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when neither reader is set up, or the read fails.
     */
    public function readPages(string $pdfBytes, string $filename = 'book.pdf'): array
    {
        if ($this->openAi->isConfigured()) {
            return $this->openAi->readPages($pdfBytes, $filename);
        }

        if ($this->azure->isConfigured()) {
            return $this->azure->extractPages($pdfBytes, 'application/pdf');
        }

        throw new RuntimeException('This server cannot read PDFs: it has no PDF renderer, no OpenAI key and no Azure Vision key.');
    }
}
