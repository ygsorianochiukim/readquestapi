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
        $key = config('services.azure_vision.key');
        $endpoint = rtrim((string) config('services.azure_vision.endpoint'), '/');

        $submit = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $key,
        ])
            ->withBody($imageBytes, 'application/octet-stream')
            ->timeout(30)
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

        // Poll for the result (Read is asynchronous).
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(700_000); // 0.7s between polls

            $poll = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $key,
            ])->timeout(30)->get($operationLocation);

            $data = $poll->json();
            $status = $data['status'] ?? '';

            if ($status === 'succeeded') {
                return $this->joinLines($data);
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
     * Flatten the Read API result into newline-separated text.
     */
    private function joinLines(array $data): string
    {
        $lines = [];

        foreach ($data['analyzeResult']['readResults'] ?? [] as $page) {
            foreach ($page['lines'] ?? [] as $line) {
                $lines[] = $line['text'];
            }
        }

        return implode("\n", $lines);
    }
}
