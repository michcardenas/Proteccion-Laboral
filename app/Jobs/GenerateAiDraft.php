<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Services\AiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Redacta en segundo plano un borrador que ya tiene su fila en `ai_generations`.
 *
 * POR QUÉ EXISTE. Las plantillas `draft_*` piden un escrito jurídico completo, así
 * que el modelo agota siempre los 4.096 tokens de salida: ~50 tokens/s = unos 80
 * segundos fijos, medidos contra producción (14-sep-2026, proceso PL-INBOX-20260827-BG0U:
 * 82,1 s, `stop_reason: max_tokens`). El gateway de Hostinger corta muchísimo antes y
 * devolvía un 504 seco. Peor: mataba el proceso PHP, así que el `catch` del controller
 * no llegaba a correr y el intento fallido **no dejaba ni rastro en la tabla** — se
 * pagaba la llamada y no quedaba registro de ella.
 *
 * El prompt NO viaja en el payload del job: ya está persistido en la fila, que se crea
 * en estado `pendiente` antes de encolar. Así el payload es un entero y el prompt —que
 * ronda los 40.000 caracteres— no se guarda dos veces.
 */
class GenerateAiDraft implements ShouldQueue
{
    use Queueable;

    /**
     * Un solo intento: un reintento automático vuelve a pagar la llamada completa.
     *
     * Los fallos que sí conviene reintentar (429 y 529 de Anthropic) ya los cubre
     * `AiService::generateDraft` con su propio backoff dentro de la misma ejecución.
     */
    public int $tries = 1;

    /**
     * Techo del job, muy por encima de los ~80 s que tarda una redacción larga.
     *
     * El default del worker son 60 s: sin esto el job moriría antes de terminar,
     * justo el fallo que venimos a arreglar. Ojo, va de la mano de `retry_after`
     * en `config/queue.php`, que debe ser mayor que este valor.
     */
    public int $timeout = 300;

    public function __construct(public int $generationId) {}

    public function handle(AiService $ai): void
    {
        $generation = AiGeneration::find($this->generationId);

        if ($generation === null || $generation->estado !== 'pendiente') {
            // Fila borrada, o ya cerrada por una ejecución anterior. Nada que hacer:
            // repetirla sería pagar el mismo borrador dos veces.
            return;
        }

        $result = $ai->generateDraft($generation->prompt);

        $generation->update([
            'modelo' => $result['model'],
            'request_hash' => $result['request_hash'],
            'respuesta' => $result['text'],
            'tokens_in' => $result['usage']['input_tokens'],
            'tokens_out' => $result['usage']['output_tokens'],
            'latencia_ms' => $result['latencia_ms'],
            'costo_usd' => $ai->estimateCost(
                $result['usage']['input_tokens'],
                $result['usage']['output_tokens'],
                $result['model'],
            ),
            'estado' => 'ok',
        ]);
    }

    /**
     * Cierra la fila en `error` cuando el job muere.
     *
     * Sin esto la pantalla se quedaría sondeando `pendiente` para siempre: el
     * frontend no tiene forma de distinguir "todavía escribiendo" de "murió".
     * Cubre también el caso en que al worker se le acabe el `timeout`.
     */
    public function failed(?Throwable $e): void
    {
        AiGeneration::where('id', $this->generationId)
            ->where('estado', 'pendiente')
            ->update([
                'estado' => 'error',
                'error_mensaje' => Str::limit($e?->getMessage() ?? 'El job de generación falló sin mensaje.', 500),
            ]);
    }
}
