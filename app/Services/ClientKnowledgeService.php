<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\Client;
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
    /**
     * Máximo de documentos a considerar para la ficha (los más recientes).
     *
     * Con TEXTO CRUDO este tope nunca llegaba a activarse: mandaba el
     * presupuesto de caracteres y la ficha se quedaba sin espacio en el octavo
     * documento. Con RESÚMENES se dio la vuelta — un resumen ocupa ~900
     * caracteres, así que en los 200.000 caben más de doscientos y el que
     * estorba pasa a ser este número.
     *
     * Se vio con ELIAS ACOSTA: 160 documentos con texto, de los que entraban 88
     * (55%), porque `latest()->limit(120)` recortaba antes de mirar el
     * presupuesto. Ahora se pone por encima de lo que el presupuesto puede
     * sostener, para que el que frene siga siendo el de caracteres —que degrada
     * bien, cortando por el documento más viejo— y no este.
     */
    public const MAX_DOCS = 300;

    /**
     * Máximo de caracteres del texto extraído por documento.
     *
     * Estaba en 12.000 y era el verdadero cuello de botella: un documento legal
     * promedia ~11.000 caracteres, así que uno solo se comía el 13% del
     * presupuesto y la ficha se quedaba sin espacio al octavo. Para una ficha
     * —quién es el cliente, qué obligaciones tiene, qué falta— importa más ver
     * el encabezado de cincuenta documentos que el texto íntegro de doce: las
     * partes, las fechas y el objeto están al principio.
     *
     * Es un intercambio, no una mejora gratis: se pierde el detalle del final
     * de cada documento. Para leer uno a fondo está el informe del cliente.
     */
    public const MAX_TEXTO_DOC = 4000;

    /** Presupuesto total de texto de entrada (todos los docs concatenados). */
    public const MAX_TEXTO_TOTAL = 200000;

    /**
     * Espacio de SALIDA para la ficha.
     *
     * Sin esto se usaba el `max_tokens` general (4.096 ≈ 10.000 caracteres) y
     * era un techo invisible: las tres versiones de la ficha de MELENDEZ
     * midieron 9.146, 9.966 y 9.114 caracteres, todas pegadas al límite. Al
     * subir la cobertura de 12 a 97 documentos la ficha no creció — comprimió,
     * y en esa compresión perdió el SGSST y el Comité de Convivencia, que sí
     * estaban en la versión anterior.
     *
     * Más material de entrada exige más sitio de salida; si no, ampliar la
     * cobertura solo cambia qué se sacrifica. Se alinea con
     * `ProcessContextBuilder::MAX_FICHA_CLIENTE`, que es donde acaba la ficha.
     */
    public const MAX_TOKENS_FICHA = 8000;

    /**
     * Segundos de espera para la generación de la ficha.
     *
     * El timeout general (150 s) está pensado para redacciones cortas y se
     * quedó chico en cuanto la cobertura subió: MELENDEZ, con 87.000 caracteres
     * de entrada, tardó 78 s; ELIAS ACOSTA, con 167.000, se pasó de 150 y la
     * ficha del cliente más grande del despacho quedó vacía. El fallo era
     * silencioso —`build()` devuelve false y sigue— así que se veía como «ese
     * cliente no tiene ficha», no como un error.
     *
     * Esto es trabajo de fondo, no una petición web: puede permitirse esperar.
     * Con `QUEUE_CONNECTION=sync` sí corre dentro de la petición, y eso es un
     * problema de despliegue que hay que resolver aparte.
     */
    public const SEGUNDOS_FICHA = 420;

    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly AiService $ai,
    ) {}

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
            $response = $this->ai->generateDraft($prompt, null, [
                'temperature' => 0.2,
                'max_tokens' => self::MAX_TOKENS_FICHA,
                'timeout' => self::SEGUNDOS_FICHA,
            ]);

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
            // El resumen manda sobre el texto crudo cuando existe. Es la
            // diferencia entre que quepan doce documentos o los ciento
            // cuarenta y ocho: un resumen ocupa ~900 caracteres y el texto
            // completo ~11.000. Ver DocumentSummarizer.
            $resumido = filled($doc->resumen_ia);
            $texto = $resumido
                ? $doc->resumen_ia
                : $this->extractor->extractFromDocument($doc);

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

            // Se marca cuál viene resumido: si no, el modelo lee un extracto
            // corto y no puede distinguir «el documento dice poco» de «solo
            // estoy viendo su ficha de catálogo».
            $encabezado = '### '.($doc->nombre ?? 'documento')
                .($doc->tipo ? ' ('.$doc->tipo.')' : '')
                .($doc->created_at ? ' · '.$doc->created_at->format('Y-m-d') : '')
                .($resumido ? ' · [resumen]' : '');
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
