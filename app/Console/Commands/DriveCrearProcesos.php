<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea los procesos a partir de las carpetas de Drive y les engancha sus documentos.
 *
 * Es el paso siguiente a `drive:proponer-procesos`: lee ese mismo CSV, ya
 * revisado, y crea de verdad lo que esté marcado. Guarda en cada proceso el
 * nombre de su carpeta (`processes.drive_folder`), que es lo que después usa
 * `DriveKnowledgeSync` para atar los archivos nuevos sin que nadie se lo diga.
 *
 * Es idempotente por `codigo`: volver a correrlo no duplica nada, y los
 * documentos se reasignan a su proceso aunque el proceso ya existiera.
 *
 * Por defecto es un ENSAYO. Hay que pedir `--ejecutar`.
 */
class DriveCrearProcesos extends Command
{
    protected $signature = 'drive:crear-procesos
        {csv : Ruta del CSV revisado que salio de drive:proponer-procesos}
        {--servicio= : Servicio por defecto para las filas que no lo traigan (nombre o id)}
        {--ejecutar : Crea de verdad. Sin esto solo se informa}';

    protected $description = 'Crea procesos desde las carpetas de Drive y les engancha sus documentos';

    public function handle(): int
    {
        $ruta = $this->argument('csv');
        if (! is_file($ruta)) {
            $this->error("No encuentro el archivo: {$ruta}");

            return self::FAILURE;
        }

        $porDefecto = $this->resolverServicio($this->option('servicio'));
        if ($this->option('servicio') && ! $porDefecto) {
            $this->error('No encontré ese servicio. Disponibles: '
                .ServiceType::where('es_activo', true)->pluck('nombre')->implode(' · '));

            return self::FAILURE;
        }

        $filas = $this->leerCsv($ruta);
        $this->line('Filas en el CSV: '.count($filas));

        $plan = [];
        $problemas = [];

        foreach ($filas as $i => $fila) {
            $linea = $i + 2;   // +1 por el encabezado, +1 porque las hojas empiezan en 1

            // Solo se salta lo marcado explícitamente como NO. Vacío se toma
            // como «sí»: el CSV lo genera este mismo sistema y la decisión de
            // no crear algo hay que escribirla, no dejarla implícita.
            if (Str::upper(trim($fila['crear'] ?? '')) === 'NO') {
                continue;
            }

            $client = Client::where('razon_social', $fila['cliente'] ?? '')->first();
            if (! $client) {
                $problemas[] = "linea {$linea}: no existe el cliente «{$fila['cliente']}»";

                continue;
            }

            $servicio = $this->resolverServicio($fila['servicio'] ?? null) ?? $porDefecto;
            if (! $servicio) {
                $problemas[] = "linea {$linea}: «{$fila['carpeta']}» sin servicio (ni en el CSV ni en --servicio)";

                continue;
            }

            $plan[] = [
                'client' => $client,
                'carpeta' => $fila['carpeta'],
                'codigo' => $fila['codigo_sugerido'],
                'servicio' => $servicio,
                'abogada' => $this->resolverUsuario($fila['abogada'] ?? null),
                'desde' => $fila['desde'] ?? null,
                'hasta' => $fila['ultimo_movimiento'] ?? null,
                'docs' => (int) ($fila['documentos'] ?? 0),
            ];
        }

        foreach ($problemas as $p) {
            $this->warn('  '.$p);
        }

        if ($plan === []) {
            $this->error('No hay nada que crear.');

            return self::FAILURE;
        }

        $this->info('Se crearían '.count($plan).' procesos, con '
            .array_sum(array_column($plan, 'docs')).' documentos enganchados.');

        if (! $this->option('ejecutar')) {
            $this->newLine();
            $this->warn('ENSAYO — no se creó nada. Repite con --ejecutar.');

            return self::SUCCESS;
        }

        $creados = 0;
        $existentes = 0;
        $enganchados = 0;

        foreach ($plan as $p) {
            DB::transaction(function () use ($p, &$creados, &$existentes, &$enganchados) {
                $proceso = Process::where('codigo', $p['codigo'])->first();

                if ($proceso) {
                    $existentes++;
                } else {
                    $proceso = Process::create([
                        'client_id' => $p['client']->id,
                        'service_type_id' => $p['servicio']->id,
                        'abogado_lider_id' => $p['abogada']?->id,
                        'codigo' => $p['codigo'],
                        'titulo' => Str::limit($p['carpeta'], 200, ''),
                        'drive_folder' => $p['carpeta'],
                        // Sin movimiento en más de un año se da por cerrado: el
                        // despacho lo corrige en pantalla si no es así, pero
                        // arrancar todo en «abierto» daría una lista de 53
                        // asuntos vivos que no ayuda a nadie.
                        'estado' => $this->estadoSegunActividad($p['hasta']),
                        'fecha_apertura' => $p['desde'] ?: now()->toDateString(),
                    ]);
                    $creados++;
                }

                $enganchados += $this->engancharDocumentos($p['client'], $p['carpeta'], $proceso->id);
            });
        }

        $this->newLine();
        $this->info("Procesos creados: {$creados}");
        if ($existentes > 0) {
            $this->line("Ya existían: {$existentes} (no se duplicaron)");
        }
        $this->info("Documentos enganchados a su proceso: {$enganchados}");
        $this->newLine();
        $this->line('A partir de ahora, los archivos que aparezcan en esas carpetas se atan solos');
        $this->line('a su proceso al sincronizar. Ver DriveKnowledgeSync::procesoDeLaCarpeta().');

        return self::SUCCESS;
    }

    /** Ata al proceso los documentos cuya ruta empieza por esa carpeta. */
    protected function engancharDocumentos(Client $client, string $carpeta, int $processId): int
    {
        return Document::where('client_id', $client->id)
            ->whereNotNull('drive_file_id')
            ->where('nombre', 'like', $carpeta.'/%')
            ->update(['process_id' => $processId]);
    }

    protected function estadoSegunActividad(?string $hasta): string
    {
        if (! $hasta) {
            return 'abierto';
        }

        return Carbon::parse($hasta)->lt(now()->subYear()) ? 'cerrado' : 'en_curso';
    }

    protected function resolverServicio(?string $valor): ?ServiceType
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        if (ctype_digit($valor)) {
            return ServiceType::find((int) $valor);
        }

        return ServiceType::where('nombre', $valor)
            ->orWhere('nombre', 'like', '%'.$valor.'%')
            ->first();
    }

    protected function resolverUsuario(?string $nombre): ?User
    {
        $nombre = trim((string) $nombre);

        return $nombre === '' || Str::startsWith($nombre, '(')
            ? null
            : User::where('name', $nombre)->first();
    }

    /** @return array<int,array<string,string>> */
    protected function leerCsv(string $ruta): array
    {
        $fh = fopen($ruta, 'r');
        $encabezados = fgetcsv($fh);
        // El BOM que se escribe para Excel se cuela en la primera cabecera.
        $encabezados[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $encabezados[0]);

        $filas = [];
        while (($fila = fgetcsv($fh)) !== false) {
            if (count($fila) === count($encabezados)) {
                $filas[] = array_combine($encabezados, $fila);
            }
        }
        fclose($fh);

        return $filas;
    }
}
