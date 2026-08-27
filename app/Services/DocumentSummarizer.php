<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\Document;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resume UN documento en unas pocas líneas, y lo cachea.
 *
 * Existe porque la ficha del cliente estaba limitada por el tamaño del prompt y
 * no por la cantidad de documentos: metiendo texto crudo, la de MELENDEZ leía 12
 * de sus 96 documentos y la de ELIAS ACOSTA 12 de 147. Con un resumen corto por
 * documento, los 148 de ELIAS caben enteros y la ficha pasa a conocerlos todos.
 *
 * El resumen se calcula UNA vez y se guarda en el propio documento, igual que
 * `texto_extraido`. Solo se rehace si el documento cambió después de haberlo
 * resumido — un documento firmado no cambia, así que el gasto es de una sola vez.
 *
 * Lo que se le pide al modelo es deliberadamente esquelético: partes, fechas,
 * objeto y obligaciones. No es un resumen "bonito" para leer, es una ficha de
 * catálogo para que otra pasada sepa QUÉ hay y decida qué mirar de cerca.
 */
class DocumentSummarizer
{
    /**
     * Texto del documento que se le manda al modelo.
     *
     * Con 6.000 caracteres entra la primera parte de casi cualquier documento
     * legal, que es donde viven las partes, las fechas y el objeto. Ir más allá
     * encarece el backfill entero sin mejorar la ficha de catálogo.
     */
    public const MAX_TEXTO_ENTRADA = 6000;

    /**
     * Techo del resumen guardado.
     *
     * Es el número que hace que todo quepa: 148 documentos por 900 caracteres
     * son ~133.000, dentro del presupuesto de la ficha. Si esto crece, la
     * promesa de cobertura del 100% deja de cumplirse en los clientes grandes.
     */
    public const MAX_RESUMEN = 900;

    public function __construct(private readonly AiService $ai) {}

    /**
     * ¿Hace falta (re)generar el resumen de este documento?
     *
     * Mismo criterio que `DocumentTextExtractor`: si la marca es posterior o
     * igual a `updated_at`, lo que hay está al día.
     */
    public function necesitaResumen(Document $doc): bool
    {
        if (blank($doc->texto_extraido)) {
            return false;   // sin texto no hay nada que resumir
        }

        if (blank($doc->resumen_ia) || $doc->resumen_ia_at === null) {
            return true;
        }

        return $doc->updated_at !== null
            && $doc->resumen_ia_at->lessThan($doc->updated_at);
    }

    /**
     * Resume el documento y lo guarda. Devuelve el resumen, o null si no se pudo.
     *
     * Nunca lanza: un documento ilegible no puede tumbar el backfill de los
     * otros ciento cuarenta y siete.
     */
    public function summarize(Document $doc, bool $forzar = false): ?string
    {
        if (! $forzar && ! $this->necesitaResumen($doc)) {
            return $doc->resumen_ia;
        }

        if (blank($doc->texto_extraido)) {
            return null;
        }

        $prompt = $this->renderPrompt($doc);

        try {
            $response = $this->ai->generateDraft($prompt, null, [
                'temperature' => 0.1,
                'max_tokens' => 600,
            ]);

            $resumen = trim((string) $response['text']);

            // El carácter de continuación cuenta DENTRO del techo. `Str::limit`
            // lo suma por fuera y devolvía 901: irrelevante en un documento,
            // pero el presupuesto de la ficha se calcula multiplicando este
            // número por los documentos del cliente, y una promesa que no se
            // cumple exactamente no sirve para calcular nada.
            if (mb_strlen($resumen) > self::MAX_RESUMEN) {
                $resumen = mb_substr($resumen, 0, self::MAX_RESUMEN - 1).'…';
            }

            // En el MISMO save que la marca, para que `resumen_ia_at` no quede
            // por detrás de `updated_at` y el documento se dé por obsoleto en bucle.
            $doc->forceFill([
                'resumen_ia' => $resumen,
                'resumen_ia_at' => now(),
            ])->saveQuietly();

            $this->log($doc, $response, 'ok');

            return $resumen;
        } catch (Throwable $e) {
            report($e);
            $this->log($doc, null, 'error', $e);

            return null;
        }
    }

    protected function renderPrompt(Document $doc): string
    {
        $texto = Str::limit(trim((string) $doc->texto_extraido), self::MAX_TEXTO_ENTRADA, '… [recortado]');

        return <<<PROMPT
        Eres un asistente de un despacho de derecho laboral colombiano. Vas a
        escribir una ficha de catálogo de UN documento, para que después otra
        persona sepa qué contiene sin abrirlo.

        Documento: {$doc->nombre}
        Tipo registrado: {$doc->tipo}

        Escribe como máximo 6 líneas, en español, sin encabezados ni markdown.
        Incluye únicamente lo que el documento diga de verdad:
        - Qué tipo de documento es.
        - Las partes (nombres y NIT o cédula si aparecen).
        - Fechas relevantes: suscripción, vigencia, vencimiento, radicación.
        - El objeto y las obligaciones o cifras principales.

        Reglas que no puedes romper:
        - NO inventes ningún dato. Si algo no aparece, no lo menciones.
        - Si el texto está incompleto o ilegible, dilo en una línea y para.
        - Nada de introducciones ni de conclusiones: solo los hechos.

        Texto del documento:
        ---
        {$texto}
        ---
        PROMPT;
    }

    protected function log(Document $doc, ?array $response, string $estado, ?Throwable $e = null): void
    {
        $cost = 0.0;
        if ($response) {
            try {
                $cost = $this->ai->estimateCost(
                    $response['usage']['input_tokens'] ?? 0,
                    $response['usage']['output_tokens'] ?? 0,
                    $response['model'] ?? null,
                );
            } catch (Throwable) {
                $cost = 0.0;
            }
        }

        AiGeneration::create([
            'user_id' => null,
            'contexto_tipo' => Document::class,
            'contexto_id' => $doc->id,
            'proveedor' => 'anthropic',
            'modelo' => $response['model'] ?? config('anthropic.model'),
            'request_hash' => $response['request_hash'] ?? null,
            'prompt' => 'document_summary: '.Str::limit((string) $doc->nombre, 120),
            'respuesta' => $response['text'] ?? '',
            'tokens_in' => $response['usage']['input_tokens'] ?? 0,
            'tokens_out' => $response['usage']['output_tokens'] ?? 0,
            'latencia_ms' => $response['latencia_ms'] ?? 0,
            'costo_usd' => $cost,
            'estado' => $estado,
            'error_mensaje' => $e ? Str::limit($e->getMessage(), 500) : null,
        ]);
    }
}
