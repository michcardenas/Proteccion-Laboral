<?php

namespace App\Services;

use BadMethodCallException;
use Illuminate\Support\Facades\Http;

class AiService
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $model = null,
        protected ?int $maxTokens = null,
        protected ?int $timeout = null,
    ) {
        $this->apiKey ??= config('anthropic.api_key');
        $this->model ??= config('anthropic.model');
        $this->maxTokens ??= config('anthropic.max_tokens');
        $this->timeout ??= config('anthropic.timeout');
    }

    /**
     * Generates an AI draft from a user prompt.
     *
     * @param  string       $prompt        User prompt / instruction.
     * @param  string|null  $systemPrompt  Optional system message to steer the model.
     * @param  array        $options       Overrides: model, max_tokens, temperature, metadata.
     * @return array{
     *     text: string,
     *     model: string,
     *     stop_reason: string,
     *     usage: array{input_tokens: int, output_tokens: int}
     * }
     */
    public function generateDraft(string $prompt, ?string $systemPrompt = null, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => config('anthropic.anthropic_version'),
                'content-type' => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post(config('anthropic.base_url').'/messages', $payload)
            ->throw()
            ->json();

        $text = collect($response['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return [
            'text' => $text,
            'model' => $response['model'] ?? $payload['model'],
            'stop_reason' => $response['stop_reason'] ?? '',
            'usage' => [
                'input_tokens' => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            ],
        ];
    }

    /**
     * Classifies an incoming email (used by Gmail ingestion pipeline).
     *
     * @param  string  $subject  Email subject.
     * @param  string  $body     Email plain-text body.
     * @param  array   $context  Optional context (known clients, open processes, etc.).
     * @return array{
     *     category: string,
     *     confidence: float,
     *     matched_process_id: int|null,
     *     extracted: array<string, mixed>,
     *     usage: array{input_tokens: int, output_tokens: int}
     * }
     */
    public function classifyEmail(string $subject, string $body, array $context = []): array
    {
        throw new BadMethodCallException('not_implemented');
    }

    /**
     * Estimates the USD cost of a Claude API call given token counts.
     *
     * @param  int          $inputTokens   Tokens in the prompt.
     * @param  int          $outputTokens  Tokens produced by the model.
     * @param  string|null  $model         Model id; defaults to configured model.
     * @return float                       Cost in USD.
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        throw new BadMethodCallException('not_implemented');
    }
}
