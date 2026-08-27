<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class AiService
{
    /**
     * Tabla de precios en USD por millón de tokens (Anthropic).
     * input = costo por 1M tokens de entrada; output = costo por 1M tokens de salida.
     */
    public const PRICING = [
        'claude-sonnet-4-6' => ['input' => 3.00,  'output' => 15.00],
        'claude-opus-4-7' => ['input' => 15.00, 'output' => 75.00],
        'claude-haiku-4-5' => ['input' => 0.80,  'output' => 4.00],
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
     * @param  string  $prompt  User prompt / instruction.
     * @param  string|null  $systemPrompt  Optional system message to steer the model.
     * @param  array  $options  Overrides: model, max_tokens, temperature, metadata,
     *                          timeout, e `images` (lista de
     *                          ['media_type' => 'image/png', 'data' => base64]).
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
        // Con imágenes el contenido deja de ser una cadena y pasa a ser una
        // lista de bloques. Es aditivo: quien no mande `images` sigue enviando
        // texto plano exactamente igual que antes. Lo usa el OCR de escaneados,
        // que en un despacho laboral son la mayoría de lo que importa —
        // demandas radicadas, sentencias, incapacidades.
        //
        // Las imágenes van ANTES del texto a propósito: es el orden que
        // recomienda Anthropic cuando la instrucción se refiere a ellas.
        $contenido = $prompt;
        if (! empty($options['images'])) {
            $contenido = [];
            foreach ($options['images'] as $img) {
                $contenido[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['media_type'],
                        'data' => $img['data'],
                    ],
                ];
            }
            $contenido[] = ['type' => 'text', 'text' => $prompt];
        }

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $contenido],
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
            // `timeout` por llamada: una redacción corta se resuelve en segundos,
            // pero un informe de 20.000 tokens no cabe en el tiempo por defecto.
            // Es aditivo: quien no lo mande sigue con el de config/anthropic.php.
            ->timeout($options['timeout'] ?? $this->timeout)
            // Backoff ante rate limit (429) o sobrecarga (529) de Anthropic.
            ->retry(
                3,
                fn (int $attempt) => $attempt * 15000,
                fn ($exception) => $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 529], true),
            )
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
     *                          'body_text' => string, 'attachments' => string[]]
     * @param  array  $context  Contexto adicional opcional:
     *                          ['known_processes' => array<int, array{code: string, client_name: string, ...}>,
     *                          'known_clients' => array<int, array{razon_social: string, nit: ?string}>,
     *                          'known_service_types' => string[]]
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
     * @throws \JsonException si la respuesta no es JSON válido.
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
     * Interpreta un plan de trabajo / contrato (texto plano ya extraído) y devuelve
     * la estructura para configurar el proceso: etapas con entregables y fechas,
     * entregables transversales y tareas del tablero Kanban.
     *
     * @param  string  $documentText  Texto plano del documento subido.
     * @param  array  $context  ['today', 'process_code', 'client_name',
     *                          'service_type', 'fecha_apertura']
     * @return array{
     *     tipo_documento: string,
     *     resumen: string,
     *     etapas: array<int, array{nombre: string, descripcion: ?string, fecha_entrega: ?string, entregables: string[]}>,
     *     transversales: string[],
     *     tareas: array<int, array{titulo: string, descripcion: ?string, prioridad: string, fecha_limite: ?string}>,
     *     usage: array{input_tokens: int, output_tokens: int},
     *     request_hash: string,
     *     latencia_ms: int
     * }
     *
     * @throws \RuntimeException si la respuesta no trae las listas requeridas.
     * @throws \JsonException si la respuesta no es JSON válido.
     */
    public function extractWorkPlan(string $documentText, array $context = []): array
    {
        $prompt = $this->renderExtractWorkPlanPrompt($documentText, $context);

        $response = $this->generateDraft($prompt, null, ['temperature' => 0.0]);

        $parsed = $this->parseJsonResponse($response['text']);

        $this->validateWorkPlanFields($parsed);

        $parsed['tipo_documento'] = $parsed['tipo_documento'] ?? 'desconocido';
        $parsed['resumen'] = $parsed['resumen'] ?? '';
        $parsed['etapas'] = array_values($parsed['etapas']);
        $parsed['transversales'] = array_values($parsed['transversales']);
        $parsed['tareas'] = array_values($parsed['tareas']);
        $parsed['usage'] = $response['usage'];
        $parsed['request_hash'] = $response['request_hash'];
        $parsed['latencia_ms'] = $response['latencia_ms'];

        return $parsed;
    }

    /**
     * Renderiza la plantilla extract_work_plan.md con el documento y el contexto.
     */
    protected function renderExtractWorkPlanPrompt(string $documentText, array $context): string
    {
        $template = file_get_contents(resource_path('prompts/extract_work_plan.md'));

        return strtr($template, [
            '{{today}}' => $context['today'] ?? now()->toDateString(),
            '{{process_code}}' => $context['process_code'] ?? '',
            '{{client_name}}' => $context['client_name'] ?? '',
            '{{service_type}}' => $context['service_type'] ?? '',
            '{{fecha_apertura}}' => $context['fecha_apertura'] ?? '',
            '{{document_text}}' => $documentText,
        ]);
    }

    /**
     * Valida que la extracción traiga las tres listas del contrato como arrays.
     *
     * @throws \RuntimeException
     */
    protected function validateWorkPlanFields(array $parsed): void
    {
        foreach (['etapas', 'transversales', 'tareas'] as $required) {
            if (! array_key_exists($required, $parsed) || ! is_array($parsed[$required])) {
                throw new \RuntimeException("extractWorkPlan: respuesta sin la lista requerida '{$required}'.");
            }
        }
    }

    /**
     * Renderiza la plantilla classify_email.md con los datos del payload y contexto.
     */
    protected function renderClassifyEmailPrompt(array $payload, array $context): string
    {
        $template = file_get_contents(resource_path('prompts/classify_email.md'));

        $attachments = $payload['attachments'] ?? [];
        $attachmentsList = empty($attachments) ? '(ninguno)' : implode(', ', $attachments);

        $json = fn (string $key) => json_encode(
            $context[$key] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        return strtr($template, [
            '{{from}}' => $payload['from'] ?? '',
            '{{subject}}' => $payload['subject'] ?? '',
            '{{body_text}}' => $payload['body_text'] ?? '',
            '{{attachments}}' => $attachmentsList,
            '{{known_processes}}' => $json('known_processes'),
            '{{known_clients}}' => $json('known_clients'),
            '{{known_service_types}}' => $json('known_service_types'),
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
     * @param  int  $inputTokens  Tokens in the prompt.
     * @param  int  $outputTokens  Tokens produced by the model.
     * @param  string|null  $model  Model id; defaults to configured model.
     * @return float Cost in USD.
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

        return ($inputTokens / 1_000_000) * $rates['input']
             + ($outputTokens / 1_000_000) * $rates['output'];
    }
}
