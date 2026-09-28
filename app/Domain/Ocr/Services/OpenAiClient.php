<?php

namespace App\Domain\Ocr\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One call to OpenAI's Responses API whose answer must match a JSON schema.
 * Shared by everything that asks OpenAI to read or sort a book's words.
 */
class OpenAiClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.openai.key'));
    }

    /**
     * @param  list<array<string, mixed>>  $content  the user message's parts
     * @param  array<string, mixed>  $schema  a strict JSON schema for the answer
     * @return array<string, mixed> the decoded answer
     *
     * @throws RuntimeException when OpenAI refuses, stops short, or cannot be reached.
     */
    public function structured(string $instructions, array $content, string $name, array $schema, ?string $model = null): array
    {
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            // A whole book in one go; the answer can take a few minutes.
            ->timeout((int) config('services.openai.timeout', 600))
            ->post(rtrim((string) config('services.openai.base_url'), '/').'/responses', [
                'model' => $model ?: config('services.openai.model'),
                'instructions' => $instructions,
                'input' => [['role' => 'user', 'content' => $content]],
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => $name,
                    'strict' => true,
                    'schema' => $schema,
                ]],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("OpenAI could not read this book ({$response->status()}): {$message}");
        }

        if ($response->json('status') === 'incomplete') {
            $reason = $response->json('incomplete_details.reason') ?? 'unknown';

            throw new RuntimeException("OpenAI stopped before finishing this book ({$reason}). Try uploading it in smaller parts.");
        }

        $answer = json_decode($this->outputText($response->json() ?? []), true);

        if (! is_array($answer)) {
            throw new RuntimeException('OpenAI sent back an answer that could not be understood.');
        }

        return $answer;
    }

    /** The model's answer, from the first text part of its message. */
    private function outputText(array $body): string
    {
        foreach ($body['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'output_text') {
                    return (string) $part['text'];
                }
                if (($part['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('OpenAI refused to read this book: '.($part['refusal'] ?? ''));
                }
            }
        }

        return '';
    }
}
