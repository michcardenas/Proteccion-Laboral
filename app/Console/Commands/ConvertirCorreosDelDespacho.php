<?php

namespace App\Console\Commands;

use App\Models\EmailIngestion;
use App\Services\CorreoDelDespacho;
use Illuminate\Console\Command;

/**
 * Los correos del despacho que ya entraron con el formato anterior (nota
 * interna + adjunto oculto) pasan a mensaje del despacho con su Word. Sin
 * --aplicar solo enseña lo que haría.
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
            ->filter(fn ($e) => $e->process && $despacho->esDelDespacho($e));

        $filas = $correos->map(fn ($e) => [
            $e->id,
            $e->received_at?->format('Y-m-d H:i'),
            $e->process->codigo,
            count($despacho->adjuntosReales($e)),
            $despacho->vaDirigidoAlCliente($e, $e->process) ? 'SÍ' : 'no (un clic)',
        ]);

        $this->table(['Correo', 'Fecha', 'Proceso', 'Adjuntos', 'Lo ve el cliente'], $filas->values()->all());
        $compartidos = $filas->where(4, 'SÍ')->count();
        $this->line("Correos del despacho en procesos: {$correos->count()} · se compartirían con el cliente: {$compartidos}");

        if (! $this->option('aplicar')) {
            $this->comment('Simulación: no se cambió nada. Añade --aplicar.');

            return self::SUCCESS;
        }

        foreach ($correos as $e) {
            $despacho->convertir($e->process, $e);
        }
        $this->info("Convertidos: {$correos->count()}.");

        return self::SUCCESS;
    }
}
