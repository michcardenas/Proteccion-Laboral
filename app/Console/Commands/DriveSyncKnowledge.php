<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\DriveKnowledgeSync;
use App\Services\DriveService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sincroniza la carpeta de Drive de cada cliente hacia sus documentos, de modo que la
 * IA pueda leerlos (texto extraído + ficha de conocimiento).
 *
 * Solo la regeneración de la ficha consume API de Anthropic, y únicamente cuando algún
 * documento cambió. `--no-ficha` sincroniza sin gastar nada.
 */
class DriveSyncKnowledge extends Command
{
    protected $signature = 'drive:sync-knowledge
        {--client= : Id o NIT de un solo cliente}
        {--dry-run : Muestra qué se sincronizaría sin descargar ni escribir}
        {--force : Re-descarga todo aunque no haya cambiado en Drive}
        {--no-ficha : No regenera la ficha de conocimiento (no gasta API de Anthropic)}';

    protected $description = 'Sincroniza los documentos de la unidad compartida de Drive con cada cliente';

    public function handle(DriveService $drive, DriveKnowledgeSync $sync): int
    {
        $clientes = $this->resolverClientes();

        if ($clientes->isEmpty()) {
            $this->warn('No hay clientes con carpeta de Drive mapeada. Corre primero: php artisan drive:map-clients --apply');

            return self::FAILURE;
        }

        try {
            $driveId = config('drive.shared_drive_id') ?: $drive->resolveSharedDriveId();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN: no se descarga ni se escribe nada.');
        }

        $filas = [];
        $totales = ['nuevos' => 0, 'actualizados' => 0, 'omitidos' => 0, 'eliminados' => 0, 'errores' => 0, 'sin_texto' => 0];

        foreach ($clientes as $cliente) {
            $this->line("→ {$cliente->razon_social} <fg=gray>({$cliente->drive_folder_name})</>");

            try {
                $stats = $sync->syncClient($cliente, [
                    'force' => (bool) $this->option('force'),
                    'dry_run' => $dryRun,
                    'regenerar_ficha' => ! $this->option('no-ficha'),
                    'drive_id' => $driveId,
                ]);
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
                $totales['errores']++;

                continue;
            }

            foreach (array_keys($totales) as $clave) {
                $totales[$clave] += $stats[$clave];
            }

            if ($this->output->isVerbose()) {
                foreach ($stats['detalles'] as $detalle) {
                    $this->line("   <fg=gray>{$detalle}</>");
                }
            }

            $filas[] = [
                $cliente->razon_social,
                $stats['nuevos'],
                $stats['actualizados'],
                $stats['omitidos'],
                $stats['eliminados'],
                $stats['sin_texto'],
                $stats['errores'],
            ];
        }

        $this->newLine();
        $this->table(
            ['Cliente', 'Nuevos', 'Actualiz.', 'Sin cambio', 'Eliminados', 'Sin texto', 'Errores'],
            $filas
        );

        $cambios = $totales['nuevos'] + $totales['actualizados'] + $totales['eliminados'];

        if ($cambios > 0 && ! $dryRun && ! $this->option('no-ficha')) {
            $this->info('Fichas de conocimiento regeneradas para los clientes con cambios (consumió API de Anthropic).');
        }

        if ($totales['sin_texto'] > 0) {
            $this->warn("{$totales['sin_texto']} archivo(s) quedaron sin texto legible (formato no soportado, escaneado sin OCR o demasiado grande).");
        }

        return $totales['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Client>
     */
    protected function resolverClientes()
    {
        $query = Client::query()->whereNotNull('drive_folder_id');

        if ($filtro = $this->option('client')) {
            $query->where(function ($q) use ($filtro) {
                $q->where('id', $filtro)->orWhere('nit', $filtro);
            });
        }

        return $query->orderBy('razon_social')->get();
    }
}
