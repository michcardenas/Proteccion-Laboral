<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiService
{
    /**
     * Tabla de precios en USD por millón de tokens (Anthropic).
     * input = costo por 1M tokens de entrada; output = costo por 1M tokens de salida.
     */
    public const PRICING = [
        'claude-sonnet-4-6'  => ['input' => 3.00,  'output' => 15.00],
        'claude-opus-4-7'    => ['input' => 15.00, 'output' => 75.00],
        'claude-haiku-4-5'   => ['input' => 0.80,  'output' => 4.00],
    ];

    /**
     * Acciones válidas que classifyEmail() puede devolver.
     */
    public const CLASSIFY_ACTIONS = [
        'nuevo_caso',
        'seguimiento_proceso',
        'documento_recibido',
        'comunicacion_cliente',
        'spam_o_irrelevante',
        'requiere_revision_humana',
    ];

    /**
     * Umbral de confianza por debajo del cual se fuerza revisión humana.
     */
    public const CLASSIFY_CONFIDENCE_THRESHOLD = 0.6;

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
     *     usage: array{input_tokens: int, output_tokens: int},
     *     request_hash: string,
     *     latencia_ms: int
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

        $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $startedAt = hrtime(true);

        $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => config('anthropic.anthropic_version'),
                'content-type' => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post(config('anthropic.base_url').'/messages', $payload)
            ->throw()
            ->json();

        $latenciaMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

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
            'request_hash' => $requestHash,
            'latencia_ms' => $latenciaMs,
        ];
    }

    /**
     * Clasifica un email entrante usando el prompt en `resources/prompts/classify_email.md`
     * y devuelve la estructura del dominio legal (acción, código de proceso, etapa, etc.).
     *
     * @param  array  $payload  Email a clasificar:
     *                          ['from' => string, 'subject' => string,
     *                           'body_text' => string, 'attachments' => string[]]
     * @param  array  $context  Contexto adicional opcional:
     *                          ['known_processes' => array<int, array{code: string, client_name: string, ...}>]
     * @return array{
     *     action: string,
     *     confidence: float,
     *     process_code?: string,
     *     client_name?: string,
     *     service_type?: string,
     *     stage_hint?: string,
     *     summary: string,
     *     extracted_fields: array{dates: array, amounts: array, references: array, people: array},
     *     usage: array{input_tokens: int, output_tokens: int},
     *     request_hash: string,
     *     latencia_ms: int
     * }
     *
     * @throws \RuntimeException si la respuesta de Claude no contiene los campos requeridos.
     * @throws \JsonException    si la respuesta no es JSON válido.
     */
    public function classifyEmail(array $payload, array $context = []): array
    {
        $prompt = $this->renderClassifyEmailPrompt($payload, $context);

        $response = $this->generateDraft($prompt, null, ['temperature' => 0.0]);

        $parsed = $this->parseJsonResponse($response['text']);

        $this->validateClassifyEmailFields($parsed);

        // Forzar revisión humana si el modelo no se siente seguro o devolvió acción inválida.
        if (! in_array($parsed['action'], self::CLASSIFY_ACTIONS, true)
            || (float) $parsed['confidence'] < self::CLASSIFY_CONFIDENCE_THRESHOLD) {
            $parsed['action'] = 'requiere_revision_humana';
        }

        $parsed['confidence'] = (float) $parsed['confidence'];
        $parsed['usage'] = $response['usage'];
        $parsed['request_hash'] = $response['request_hash'];
        $parsed['latencia_ms'] = $response['latencia_ms'];

        return $parsed;
    }

    /**
     * Renderiza la plantilla classify_email.md con los datos del payload y contexto.
     */
    protected function renderClassifyEmailPrompt(array $payload, array $context): string
    {
        $template = file_get_contents(resource_path('prompts/classify_email.md'));

        $attachments = $payload['attachments'] ?? [];
        $attachmentsList = empty($attachments) ? '(ninguno)' : implode(', ', $attachments);

        $knownProcessesJson = json_encode(
            $context['known_processes'] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        return strtr($template, [
            '{{from}}' => $payload['from'] ?? '',
            '{{subject}}' => $payload['subject'] ?? '',
            '{{body_text}}' => $payload['body_text'] ?? '',
            '{{attachments}}' => $attachmentsList,
            '{{known_processes}}' => $knownProcessesJson,
        ]);
    }

    /**
     * Extrae y decodifica el JSON de la respuesta de Claude (admite el caso en que venga
     * envuelto en un bloque de código markdown ```json ... ```).
     *
     * @throws \JsonException
     */
    protected function parseJsonResponse(string $text): array
    {
        $trimmed = trim($text);

        // Si Claude envolvió la respuesta en un bloque de código, extraer el contenido.
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Valida que la respuesta tenga los campos requeridos por el contrato.
     *
     * @throws \RuntimeException
     */
    protected function validateClassifyEmailFields(array $parsed): void
    {
        foreach (['action', 'confidence', 'summary', 'extracted_fields'] as $required) {
            if (! array_key_exists($required, $parsed)) {
                throw new \RuntimeException("classifyEmail: respuesta sin campo requerido '{$required}'.");
            }
        }
    }

    /**
     * Estimates the USD cost of a Claude API call given token counts.
     *
     * @param  int          $inputTokens   Tokens in the prompt.
     * @param  int          $outputTokens  Tokens produced by the model.
     * @param  string|null  $model         Model id; defaults to configured model.
     * @return float                       Cost in USD.
     *
     * @throws \InvalidArgumentException si el modelo no está en la tabla de precios.
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model ??= $this->model;

        if (! isset(self::PRICING[$model])) {
            throw new \InvalidArgumentException("Modelo desconocido para tarificación: {$model}");
        }

        $rates = self::PRICING[$model];

        return ($inputTokens  / 1_000_000) * $rates['input']
             + ($outputTokens / 1_000_000) * $rates['output'];
    }
}
