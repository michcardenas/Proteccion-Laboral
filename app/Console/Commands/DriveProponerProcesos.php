<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Document;
use App\Models\ServiceType;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Propone qué procesos crear a partir de las carpetas de Drive.
 *
 * El trabajo real del despacho vive en Drive con la forma
 * `cliente / asunto / archivos` —ELIAS ACOSTA tiene 52 carpetas de primer
 * nivel— mientras que la tabla `processes` está prácticamente vacía. Esto lee
 * esa estructura y saca la lista para que alguien del despacho la revise.
 *
 * NO crea nada. Y no puede: `processes.service_type_id` es obligatorio y una
 * carpeta llamada «22 MANUAL DE FUNCIONES» no dice a qué servicio corresponde.
 * Adivinarlo por el nombre clasificaría mal desde el primer día, así que la
 * columna sale vacía y la rellena una persona.
 */
class DriveProponerProcesos extends Command
{
    protected $signature = 'drive:proponer-procesos
        {--client= : Solo este cliente (id, NIT o parte de la razón social)}
        {--min-docs=1 : Ignora las carpetas con menos documentos que esto}
        {--csv= : Escribe la propuesta en este archivo en vez de la pantalla}';

    protected $description = 'Lista las carpetas de Drive que podrían ser procesos, para que el despacho las revise';

    public function handle(): int
    {
        $clientes = Client::query()
            ->when($this->option('client'), fn ($q, $f) => $q->where(function ($qq) use ($f) {
                $qq->where('nit', $f)->orWhere('razon_social', 'like', '%'.$f.'%');
                if (ctype_digit($f)) {
                    $qq->orWhere('id', (int) $f);
                }
            }))
            ->orderBy('razon_social')
            ->get();

        $minimo = max(1, (int) $this->option('min-docs'));
        $filas = [];

        foreach ($clientes as $client) {
            foreach ($this->carpetasDe($client) as $carpeta => $datos) {
                if ($datos['docs'] < $minimo) {
                    continue;
                }

                $filas[] = [
                    'cliente' => $client->razon_social,
                    'nit' => $client->nit,
                    'carpeta' => $carpeta,
                    'documentos' => $datos['docs'],
                    'con_texto' => $datos['con_texto'],
                    'desde' => $datos['desde'],
                    'codigo_sugerido' => $this->codigo($client, $carpeta),
                    'servicio' => '',           // lo rellena el despacho
                    'crear' => '',              // SI / NO, lo marca el despacho
                ];
            }
        }

        if ($filas === []) {
            $this->info('No encontré carpetas de Drive con documentos.');

            return self::SUCCESS;
        }

        if ($ruta = $this->option('csv')) {
            $this->escribirCsv($ruta, $filas);
            $this->info('Propuesta escrita en '.$ruta.' — '.count($filas).' carpetas.');
        } else {
            $this->table(
                ['Cliente', 'Carpeta', 'Docs', 'Con texto', 'Desde'],
                array_map(fn ($f) => [
                    Str::limit($f['cliente'], 24),
                    Str::limit($f['carpeta'], 40),
                    $f['documentos'],
                    $f['con_texto'],
                    $f['desde'],
                ], array_slice($filas, 0, 40))
            );
            if (count($filas) > 40) {
                $this->line('… y '.(count($filas) - 40).' más. Usa --csv para verlas todas.');
            }
        }

        $this->newLine();
        $this->warn('Esto NO crea nada.');
        $this->line('Falta que alguien del despacho marque, por cada carpeta:');
        $this->line('  · el SERVICIO al que corresponde (es obligatorio y no se puede deducir del nombre)');
        $this->line('  · si es un PROCESO propio o un entregable dentro de otro');
        $this->newLine();
        $this->line('Servicios disponibles hoy: '.(ServiceType::where('es_activo', true)->pluck('nombre')->implode(' · ') ?: '(ninguno)'));

        return self::SUCCESS;
    }

    /**
     * Las carpetas de primer nivel del cliente, con su tamaño y su fecha.
     *
     * La ruta vive dentro de `documents.nombre` («SISTEMA LABORAL/1 REPRESENTACION
     * LEGAL/camara.pdf»), que es como la guarda `DriveKnowledgeSync`. Se toma
     * solo el primer tramo: los de más adentro son subcarpetas del mismo asunto.
     *
     * @return array<string,array{docs:int,con_texto:int,desde:?string}>
     */
    protected function carpetasDe(Client $client): array
    {
        $carpetas = [];

        $docs = Document::where('client_id', $client->id)
            ->whereNotNull('drive_file_id')
            ->get(['nombre', 'texto_extraido', 'drive_modified_at', 'created_at']);

        foreach ($docs as $doc) {
            $partes = explode('/', (string) $doc->nombre);
            if (count($partes) < 2) {
                continue;   // archivo suelto en la raíz: no es un asunto
            }

            $raiz = trim($partes[0]);
            $carpetas[$raiz] ??= ['docs' => 0, 'con_texto' => 0, 'desde' => null];
            $carpetas[$raiz]['docs']++;

            if (filled($doc->texto_extraido)) {
                $carpetas[$raiz]['con_texto']++;
            }

            // La fecha más antigua sirve de `fecha_apertura`, que es obligatoria.
            $fecha = $doc->drive_modified_at ?? $doc->created_at;
            if ($fecha && (! $carpetas[$raiz]['desde'] || $fecha->lt($carpetas[$raiz]['desde']))) {
                $carpetas[$raiz]['desde'] = $fecha;
            }
        }

        foreach ($carpetas as $k => $v) {
            $carpetas[$k]['desde'] = $v['desde']?->format('Y-m-d');
        }

        uasort($carpetas, fn ($a, $b) => $b['docs'] <=> $a['docs']);

        return $carpetas;
    }

    /** Código propuesto, único y reconocible como venido de Drive. */
    protected function codigo(Client $client, string $carpeta): string
    {
        $base = 'PL-'.Str::upper(Str::slug(Str::limit($client->razon_social, 12, ''), ''))
            .'-'.Str::upper(Str::slug(Str::limit($carpeta, 18, ''), ''));

        return Str::limit($base, 40, '');
    }

    /** @param  array<int,array<string,mixed>>  $filas */
    protected function escribirCsv(string $ruta, array $filas): void
    {
        $fh = fopen($ruta, 'w');
        fwrite($fh, "\xEF\xBB\xBF");   // BOM: sin esto Excel se come los acentos
        fputcsv($fh, array_keys($filas[0]));
        foreach ($filas as $fila) {
            fputcsv($fh, $fila);
        }
        fclose($fh);
    }
}
