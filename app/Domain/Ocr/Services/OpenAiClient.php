<?php

namespace App\Domain\Ocr\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
            ->post($this->url(), $this->payload($instructions, $content, $name, $schema, $model));

        return $this->answer($response);
    }

    /**
     * The same call for several inputs at once — one page each, say — sent
     * together rather than one after another.
     *
     * @param  array<int, list<array<string, mixed>>>  $contents  the user message's parts, per input
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>|Throwable> each decoded answer, or why it failed, under the same keys
     */
    public function structuredMany(string $instructions, array $contents, string $name, array $schema, ?string $model = null, int $timeout = 120): array
    {
        if ($contents === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($key) => $pool->as((string) $key)
                ->withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->timeout($timeout)
                ->post($this->url(), $this->payload($instructions, $contents[$key], $name, $schema, $model)),
            array_keys($contents),
        ));

        $answers = [];

        foreach (array_keys($contents) as $key) {
            $response = $responses[$key] ?? null;

            try {
                if (! $response instanceof Response) {
                    throw $response instanceof Throwable ? $response : new RuntimeException('OpenAI could not be reached.');
                }

                $answers[$key] = $this->answer($response);
            } catch (Throwable $exception) {
                $answers[$key] = $exception;
            }
        }

        return $answers;
    }

    private function url(): string
    {
        return rtrim((string) config('services.openai.base_url'), '/').'/responses';
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function payload(string $instructions, array $content, string $name, array $schema, ?string $model): array
    {
        return [
            'model' => $model ?: config('services.openai.model'),
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => $name,
                'strict' => true,
                'schema' => $schema,
            ]],
        ];
    }

    /**
     * @return array<string, mixed> the decoded answer
     *
     * @throws RuntimeException when OpenAI refused or stopped short.
     */
    private function answer(Response $response): array
    {
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
