<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera la "ficha de conocimiento" de un cliente: un digest en markdown que resume
 * TODOS los documentos de nivel cliente (pestaña "Documentos") en un texto compacto.
 *
 * Motivación: el ProcessContextBuilder solo inyecta el texto crudo de los N documentos
 * más recientes (tope de tamaño). La ficha da AMPLITUD — un resumen fiel de todo el
 * material del cliente que cabe siempre en el prompt — y complementa (no reemplaza) esa
 * inyección de texto crudo, que aporta la PROFUNDIDAD literal de lo más reciente.
 *
 * La extracción de texto es perezosa y cacheada (DocumentTextExtractor). La generación
 * llama a la API real (AiService) y se registra en `ai_generations` para control de gasto.
 */
class ClientKnowledgeService
{
    /** Máximo de documentos a considerar para la ficha (los más recientes). */
    public const MAX_DOCS = 30;

    /** Máximo de caracteres del texto extraído por documento. */
    public const MAX_TEXTO_DOC = 12000;

    /** Presupuesto total de texto de entrada (todos los docs concatenados). */
    public const MAX_TEXTO_TOTAL = 90000;

    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly AiService $ai,
    ) {
    }

    /**
     * (Re)genera la ficha de conocimiento del cliente y la persiste.
     *
     * Es tolerante a fallos: si la API falla, deja la ficha anterior intacta y registra
     * el error en `ai_generations`. Si el cliente no tiene documentos legibles, limpia la
     * ficha (queda null) para no dejar un digest huérfano.
     *
     * @return bool true si se regeneró (o limpió) con éxito; false si hubo error de API.
     */
    public function build(Client $client): bool
    {
        [$texto, $usados] = $this->gatherDocumentsText($client);

        if ($usados === 0) {
            // Sin documentos legibles: no hay nada que resumir.
            $client->forceFill([
                'resumen_documental' => null,
                'resumen_documental_at' => now(),
            ])->saveQuietly();

            return true;
        }

        $prompt = $this->renderPrompt($client, $texto);

        try {
            $response = $this->ai->generateDraft($prompt, null, ['temperature' => 0.2]);

            $client->forceFill([
                'resumen_documental' => trim($response['text']),
                'resumen_documental_at' => now(),
            ])->saveQuietly();

            $this->logGeneration($client, $response, 'ok', $usados);

            return true;
        } catch (Throwable $e) {
            report($e);
            $this->logError($client, $e);

            return false;
        }
    }

    /**
     * Junta el texto extraído de los documentos de nivel cliente (sin proceso), más
     * recientes primero, respetando los topes por documento y el presupuesto total.
     *
     * @return array{0: string, 1: int} [texto concatenado, nº de documentos incluidos]
     */
    public function gatherDocumentsText(Client $client): array
    {
        $docs = $client->documentosCliente()
            ->latest()
            ->limit(self::MAX_DOCS)
            ->get();

        $bloques = [];
        $total = 0;
        $usados = 0;

        foreach ($docs as $doc) {
            $texto = $this->extractor->extractFromDocument($doc);
            if ($texto === null || trim($texto) === '') {
                continue;
            }

            $texto = Str::limit(trim($texto), self::MAX_TEXTO_DOC, '… [truncado]');

            if ($total + mb_strlen($texto) > self::MAX_TEXTO_TOTAL) {
                $texto = mb_substr($texto, 0, max(0, self::MAX_TEXTO_TOTAL - $total));
            }
            if (trim($texto) === '') {
                break;
            }

            $encabezado = '### '.($doc->nombre ?? 'documento')
                .($doc->tipo ? ' ('.$doc->tipo.')' : '')
                .($doc->created_at ? ' · '.$doc->created_at->format('Y-m-d') : '');
            $bloques[] = $encabezado."\n".$texto;

            $total += mb_strlen($texto);
            $usados++;

            if ($total >= self::MAX_TEXTO_TOTAL) {
                break;
            }
        }

        return [implode("\n\n", $bloques), $usados];
    }

    protected function renderPrompt(Client $client, string $documentsText): string
    {
        $template = file_get_contents(resource_path('prompts/client_knowledge_digest.md'));

        return strtr($template, [
            '{{client_name}}' => (string) ($client->razon_social ?? ''),
            '{{nit}}' => (string) ($client->nit ?? ''),
            '{{sector}}' => (string) ($client->sector ?? ''),
            '{{ciudad}}' => (string) ($client->ciudad ?? ''),
            '{{today}}' => now()->toDateString(),
            '{{documents_text}}' => $documentsText,
        ]);
    }

    protected function logGeneration(Client $client, array $response, string $estado, int $docsUsados): void
    {
        $cost = 0.0;
        try {
            $cost = $this->ai->estimateCost(
                $response['usage']['input_tokens'] ?? 0,
                $response['usage']['output_tokens'] ?? 0,
                $response['model'] ?? null,
            );
        } catch (Throwable $e) {
            // Modelo sin tarifa conocida: registramos sin costo.
        }

        AiGeneration::create([
            'user_id' => null,
            'contexto_tipo' => Client::class,
            'contexto_id' => $client->id,
            'proveedor' => 'anthropic',
            'modelo' => $response['model'] ?? config('anthropic.model'),
            'request_hash' => $response['request_hash'] ?? null,
            'prompt' => 'client_knowledge_digest: '.($client->razon_social ?? $client->id)." ({$docsUsados} doc)",
            'respuesta' => $response['text'] ?? '',
            'tokens_in' => $response['usage']['input_tokens'] ?? 0,
            'tokens_out' => $response['usage']['output_tokens'] ?? 0,
            'latencia_ms' => $response['latencia_ms'] ?? 0,
            'costo_usd' => $cost,
            'estado' => $estado,
        ]);
    }

    protected function logError(Client $client, Throwable $e): void
    {
        AiGeneration::create([
            'user_id' => null,
            'contexto_tipo' => Client::class,
            'contexto_id' => $client->id,
            'proveedor' => 'anthropic',
            'modelo' => config('anthropic.model'),
            'prompt' => 'client_knowledge_digest: '.($client->razon_social ?? $client->id),
            'estado' => 'error',
            'error_mensaje' => Str::limit($e->getMessage(), 500),
        ]);
    }
}
