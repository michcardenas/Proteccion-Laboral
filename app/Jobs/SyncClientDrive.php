<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\DriveKnowledgeSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trae de Drive los documentos nuevos de un cliente, a petición.
 *
 * Va en cola porque el trabajo real es largo: descargar los archivos, extraer
 * su texto, resumir cada uno y rehacer la ficha puede tomar minutos en un
 * cliente grande. El botón que lo dispara solo comprueba si hay algo nuevo
 * —eso es rápido y no gasta IA— y encola esto.
 *
 * `ShouldBeUnique` evita que pulsar el botón tres veces «por si acaso» cueste
 * tres regeneraciones de la ficha: la ficha se rehace ENTERA cada vez que hay
 * cambios, y en un cliente con 178 documentos eso son ~$0,28 por pasada.
 */
class SyncClientDrive implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Un cliente grande puede tardar; el lock aguanta toda la corrida. */
    public int $uniqueFor = 1800;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return 'drive-sync:'.$this->clientId;
    }

    public function handle(DriveKnowledgeSync $sync): void
    {
        $client = Client::find($this->clientId);
        if ($client === null || blank($client->drive_folder_id)) {
            return;
        }

        try {
            // `regenerar_ficha` en true: el sentido de traer documentos nuevos es
            // que la IA los conozca, y sin rehacer la ficha se quedarían guardados
            // sin llegar a ningún prompt.
            $stats = $sync->syncClient($client, ['regenerar_ficha' => true]);

            Log::info('Sincronización de Drive terminada.', [
                'client_id' => $client->id,
                'nuevos' => $stats['nuevos'] ?? 0,
                'actualizados' => $stats['actualizados'] ?? 0,
                'errores' => $stats['errores'] ?? 0,
            ]);
        } catch (Throwable $e) {
            // No se relanza: un fallo de Drive no debe dejar el job reintentando
            // en bucle y regenerando fichas. Queda en el log y el usuario puede
            // volver a pulsar el botón.
            report($e);
        }
    }
}
