<?php

namespace App\Domain\Ocr\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OcrService
{
    /**
     * Whether Azure AI Vision credentials are present.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.azure_vision.key'))
            && ! empty(config('services.azure_vision.endpoint'));
    }

    /**
     * Extract printed text from an image using the Azure AI Vision Read API.
     * Submits the image, then polls the operation until it succeeds.
     *
     * @throws RuntimeException on failure or timeout.
     */
    public function extractText(string $imageBytes): string
    {
        return implode("\n", $this->extractPages($imageBytes));
    }

    /**
     * Read a whole document and return its text one entry per page.
     *
     * The Read API takes a PDF as happily as it takes a photo, and answers with
     * a result per page either way. That is the escape hatch for hosts that
     * cannot render PDF pages to images: the words can still be recovered, even
     * when the pictures cannot.
     *
     * @param  string  $contentType  `application/pdf` for a PDF, otherwise the image type
     * @return list<string>
     *
     * @throws RuntimeException on failure or timeout.
     */
    public function extractPages(string $bytes, string $contentType = 'application/octet-stream'): array
    {
        $key = config('services.azure_vision.key');
        $endpoint = rtrim((string) config('services.azure_vision.endpoint'), '/');

        $submit = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $key,
        ])
            ->withBody($bytes, $contentType)
            ->timeout(60)
            ->post("{$endpoint}/vision/v3.2/read/analyze");

        if ($submit->status() !== 202) {
            // Azure explains exactly what it disliked — keep that, it is the
            // difference between a fixable message and "something went wrong".
            throw new RuntimeException($this->describeError($submit->status(), $submit->json()));
        }

        $operationLocation = $submit->header('Operation-Location');
        if (! $operationLocation) {
            throw new RuntimeException('Azure Vision did not return an operation location.');
        }

        // Poll for the result (Read is asynchronous). A single photo comes back
        // in a second or two; a long PDF genuinely takes a while, so the ceiling
        // is generous rather than tight.
        $deadline = microtime(true) + 180;

        while (microtime(true) < $deadline) {
            usleep(700_000); // 0.7s between polls

            $poll = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $key,
            ])->timeout(30)->get($operationLocation);

            $data = $poll->json();
            $status = $data['status'] ?? '';

            if ($status === 'succeeded') {
                return $this->pages($data);
            }

            if ($status === 'failed') {
                throw new RuntimeException('Azure Vision OCR failed.');
            }
        }

        throw new RuntimeException('Azure Vision OCR timed out.');
    }

    /**
     * Turn an Azure error response into a message worth showing a person.
     * Falls back to the bare status when Azure sends nothing useful.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function describeError(int $status, ?array $body): string
    {
        $code = $body['error']['code'] ?? null;
        $message = $body['error']['message'] ?? null;

        if ($status === 429) {
            $code ??= 'TooManyRequests';
        }

        if ($code && $message) {
            return "{$code}: {$message}";
        }

        if ($code) {
            return (string) $code;
        }

        return 'Azure Vision submit failed with status '.$status;
    }

    /**
     * One newline-joined string per page of the result.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function pages(array $data): array
    {
        $pages = [];

        foreach ($data['analyzeResult']['readResults'] ?? [] as $page) {
            $lines = [];

            foreach ($page['lines'] ?? [] as $line) {
                $lines[] = $line['text'];
            }

            $pages[] = implode("\n", $lines);
        }

        return $pages;
    }
}
