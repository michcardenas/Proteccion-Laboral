<?php

namespace App\Console\Commands;

use App\Models\Comment;
use App\Models\EmailIngestion;
use App\Services\CorreoDelDespacho;
use Illuminate\Console\Command;

/**
 * Pone al día los correos ya procesados:
 * - los del despacho (nota interna + adjunto oculto) pasan a mensaje del
 *   despacho con su Word;
 * - los que se convirtieron así y resultaron no serlo (reenvío a la bandeja
 *   de un correo del cliente) vuelven a nota interna.
 * Sin --aplicar solo enseña lo que haría.
 */
class ConvertirCorreosDelDespacho extends Command
{
    protected $signature = 'portal:correos-del-despacho {--aplicar : Hace los cambios; sin esto solo los enseña}';

    protected $description = 'Convierte los correos ya procesados del despacho en mensajes con su Word para el portal';

    public function handle(CorreoDelDespacho $despacho): int
    {
        $correos = EmailIngestion::query()
            ->whereNotNull('process_id')
            ->with('process.client.contactos')
            ->orderBy('received_at')
            ->get()
            ->toBase() // filas y pares, no solo modelos
            ->filter(fn ($e) => $e->process);

        $convertir = $correos->filter(fn ($e) => $despacho->esDelDespacho($e));

        // Convertidos antes como «📧 …» que no son del despacho.
        $revertir = $correos->reject(fn ($e) => $despacho->esDelDespacho($e))
            ->map(fn ($e) => [$e, Comment::where('email_ingestion_id', $e->id)->where('body', 'like', '📧%')->first()])
            ->filter(fn ($par) => $par[1] !== null);

        $filas = $convertir->map(fn ($e) => [
            $e->id, $e->received_at?->format('Y-m-d H:i'), $e->process->codigo, 'mensaje del despacho',
            count($despacho->adjuntosReales($e)),
            $despacho->vaDirigidoAlCliente($e, $e->process) ? 'SÍ' : 'no (un clic)',
        ])->merge($revertir->map(fn ($par) => [
            $par[0]->id, $par[0]->received_at?->format('Y-m-d H:i'), $par[0]->process->codigo,
            $par[1]->visible_cliente ? 'NO SE TOCA (ya compartido)' : 'vuelve a nota interna', '-', 'no',
        ]));

        $this->table(['Correo', 'Fecha', 'Proceso', 'Queda como', 'Adjuntos', 'Lo ve el cliente'], $filas->values()->all());
        $this->line(sprintf('Mensajes del despacho: %d (se comparten: %d) · vuelven a nota interna: %d',
            $convertir->count(),
            $convertir->filter(fn ($e) => $despacho->vaDirigidoAlCliente($e, $e->process))->count(),
            $revertir->reject(fn ($par) => $par[1]->visible_cliente)->count()));

        if (! $this->option('aplicar')) {
            $this->comment('Simulación: no se cambió nada. Añade --aplicar.');

            return self::SUCCESS;
        }

        foreach ($convertir as $e) {
            $despacho->convertir($e->process, $e);
        }
        foreach ($revertir as [$e, $comment]) {
            $despacho->revertir($comment, $e);
        }
        $this->info('Hecho.');

        return self::SUCCESS;
    }
}
