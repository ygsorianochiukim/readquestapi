<?php

namespace App\Domain\Ocr\Services;

use RuntimeException;
use Throwable;

/**
 * Reads the words off a page picture.
 *
 * OpenAI does it when it has a key; Azure Vision is only the fallback for a
 * server without one. Azure's Read API is a submit and then a poll until it
 * finishes, which made a book's pages slow to come back; OpenAI answers each
 * page in one call, and the pages of an upload are sent together.
 *
 * This only transcribes. Sorting story from covers, footers and exercises is
 * ChapterContentAgent's job afterwards.
 */
class PageReader
{
    private const INSTRUCTIONS = <<<'TEXT'
        You are transcribing one page of a children's reading book from a photo
        or scan. Children read this text aloud and are scored against it, so it
        must match the printed page exactly.

        - Write every word printed on the page, in reading order.
        - Keep spelling, punctuation, capitals and quotation marks exactly as
          printed, even where they look wrong. Never correct, reword, summarise
          or add anything.
        - Put each printed line on its own line, with a blank line between
          paragraphs.
        - Do not describe the pictures. A page with no printed words gets empty
          text.
        TEXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['text'],
        'properties' => [
            'text' => ['type' => 'string'],
        ],
    ];

    public function __construct(
        private OpenAiClient $openAi,
        private OcrService $azure,
    ) {}

    public function isConfigured(): bool
    {
        return $this->openAi->isConfigured() || $this->azure->isConfigured();
    }

    /** How many pages to read at the same time. */
    public function concurrency(): int
    {
        return max(1, $this->openAi->isConfigured()
            ? (int) config('services.openai.concurrency', 8)
            : (int) config('services.azure_vision.concurrency', 6));
    }

    /**
     * @throws RuntimeException when the page could not be read.
     */
    public function extractText(string $imageBytes): string
    {
        if (! $this->openAi->isConfigured()) {
            return $this->azure->extractText($imageBytes);
        }

        $answer = $this->openAi->structuredMany(self::INSTRUCTIONS, [$this->content($imageBytes)], 'page_text', self::SCHEMA, $this->model())[0];

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return trim((string) ($answer['text'] ?? ''));
    }

    /**
     * Read several pages at once. A page that fails comes back null rather
     * than failing the rest — the teacher reads it again from the preview.
     *
     * @param  array<int, string>  $images  image bytes, keyed however the caller likes
     * @return array<int, ?string> the text of each page, under the same keys
     */
    public function extractTextMany(array $images): array
    {
        if (! $this->openAi->isConfigured()) {
            return $this->azure->extractTextMany($images);
        }

        $answers = $this->openAi->structuredMany(
            self::INSTRUCTIONS,
            array_map(fn (string $bytes) => $this->content($bytes), $images),
            'page_text',
            self::SCHEMA,
            $this->model(),
        );

        return array_map(function ($answer) {
            if ($answer instanceof Throwable) {
                report($answer);

                return null;
            }

            $text = trim((string) ($answer['text'] ?? ''));

            return $text === '' ? null : $text;
        }, $answers);
    }

    private function model(): ?string
    {
        return config('services.openai.ocr_model') ?: null;
    }

    /** @return list<array<string, mixed>> */
    private function content(string $imageBytes): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($imageBytes) ?: 'image/jpeg';

        return [
            [
                'type' => 'input_image',
                'image_url' => "data:{$mime};base64,".base64_encode($imageBytes),
                // Small print on a page photo needs the full resolution.
                'detail' => 'high',
            ],
            ['type' => 'input_text', 'text' => 'Transcribe this page.'],
        ];
    }
}
