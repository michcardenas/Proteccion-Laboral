<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncClientDrive;
use App\Models\Client;
use App\Services\DriveKnowledgeSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Botón «Traer documentos de Drive» de la ficha del cliente.
 *
 * Primero MIRA y después decide. Consultar la carpeta de Drive y comparar
 * fechas es rápido y no gasta IA, así que se hace en la propia petición: si no
 * hay nada nuevo se le dice al usuario y ahí acaba, sin encolar ni cobrar.
 *
 * Solo cuando de verdad hay documentos nuevos se encola el trabajo pesado
 * —descargar, extraer, resumir y rehacer la ficha—, que puede tardar minutos.
 */
class ClientDriveController extends Controller
{
    public function sync(Request $request, Client $client, DriveKnowledgeSync $sync): RedirectResponse
    {
        abort_unless($request->user()->can('documents.upload'), 403);

        if (blank($client->drive_folder_id)) {
            return back()->with('error',
                'Este cliente no tiene carpeta de Drive asignada todavía.');
        }

        try {
            // Ensayo: pregunta a Drive y cuenta, sin escribir nada ni llamar a la IA.
            $previo = $sync->syncClient($client, ['dry_run' => true, 'regenerar_ficha' => false]);
        } catch (RuntimeException $e) {
            return back()->with('error', 'No se pudo consultar Drive: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo consultar Drive. Revisa que la integración siga conectada.');
        }

        $pendientes = ($previo['nuevos'] ?? 0) + ($previo['actualizados'] ?? 0);

        if ($pendientes === 0) {
            return back()->with('success', 'No hay documentos nuevos en Drive: la ficha ya está al día.');
        }

        SyncClientDrive::dispatch($client->id);

        $detalle = $previo['nuevos'] > 0
            ? $previo['nuevos'].' '.($previo['nuevos'] === 1 ? 'documento nuevo' : 'documentos nuevos')
            : '';
        if (($previo['actualizados'] ?? 0) > 0) {
            $detalle .= ($detalle ? ' y ' : '').$previo['actualizados'].' actualizado'.($previo['actualizados'] === 1 ? '' : 's');
        }

        return back()->with('success',
            "Encontré {$detalle} en Drive. Se están procesando y la ficha se actualizará sola en unos minutos.");
    }
}
