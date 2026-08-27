<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Document;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
                    'ultimo_movimiento' => $datos['hasta'],
                    'contenido' => $datos['ejemplos'],
                    'de_que_trata' => $datos['resumen'],
                    'abogada' => $this->abogadaDe($client)?->name ?? '(sin identificar)',
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
                ['Cliente', 'Abogada', 'Carpeta', 'Docs', 'Desde'],
                array_map(fn ($f) => [
                    Str::limit($f['cliente'], 22),
                    Str::limit($f['abogada'], 22),
                    Str::limit($f['carpeta'], 34),
                    $f['documentos'],
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
     * @return array<string,array<string,mixed>>
     */
    protected function carpetasDe(Client $client): array
    {
        $carpetas = [];

        $docs = Document::where('client_id', $client->id)
            ->whereNotNull('drive_file_id')
            ->get(['nombre', 'texto_extraido', 'resumen_ia', 'drive_modified_at', 'created_at']);

        foreach ($docs as $doc) {
            $partes = explode('/', (string) $doc->nombre);
            if (count($partes) < 2) {
                continue;   // archivo suelto en la raíz: no es un asunto
            }

            $raiz = trim($partes[0]);
            $carpetas[$raiz] ??= [
                'docs' => 0, 'con_texto' => 0, 'desde' => null, 'hasta' => null,
                'ejemplos' => [], 'resumen' => '',
            ];
            $carpetas[$raiz]['docs']++;

            if (filled($doc->texto_extraido)) {
                $carpetas[$raiz]['con_texto']++;
            }

            // Lo que va DENTRO de la carpeta es la mejor pista de qué es, pero
            // hay que coger el nivel correcto: en «16 COMPRAVENTA GARCES/
            // ESCRITURA 4300 DEL 4 DE DIC 2023/1.jpeg» el nombre del archivo es
            // «1.jpeg» y no dice nada — el significado está en la SUBCARPETA.
            // Cuando no hay subcarpeta, el archivo sí es el nombre útil.
            $etiqueta = trim($partes[1]);
            if ($etiqueta !== '' && ! in_array($etiqueta, $carpetas[$raiz]['ejemplos'], true)
                && count($carpetas[$raiz]['ejemplos']) < 5) {
                $carpetas[$raiz]['ejemplos'][] = $etiqueta;
            }

            // Y el resumen del documento más largo ya está pagado: es la mejor
            // señal de contenido que tenemos y no cuesta una llamada más.
            if (filled($doc->resumen_ia) && mb_strlen($doc->resumen_ia) > mb_strlen($carpetas[$raiz]['resumen'])) {
                $carpetas[$raiz]['resumen'] = $doc->resumen_ia;
            }

            $fecha = $doc->drive_modified_at ?? $doc->created_at;
            if ($fecha) {
                // La más antigua sirve de `fecha_apertura`, que es obligatoria.
                if (! $carpetas[$raiz]['desde'] || $fecha->lt($carpetas[$raiz]['desde'])) {
                    $carpetas[$raiz]['desde'] = $fecha;
                }
                // La más reciente dice si el asunto sigue vivo o está cerrado,
                // que es justo lo que decide el `estado` del proceso.
                if (! $carpetas[$raiz]['hasta'] || $fecha->gt($carpetas[$raiz]['hasta'])) {
                    $carpetas[$raiz]['hasta'] = $fecha;
                }
            }
        }

        foreach ($carpetas as $k => $v) {
            $carpetas[$k]['desde'] = $v['desde']?->format('Y-m-d');
            $carpetas[$k]['hasta'] = $v['hasta']?->format('Y-m-d');
            $carpetas[$k]['ejemplos'] = implode(' · ', $v['ejemplos']);
            $carpetas[$k]['resumen'] = Str::limit(preg_replace('/\s+/', ' ', $v['resumen']), 300);
        }

        uasort($carpetas, fn ($a, $b) => $b['docs'] <=> $a['docs']);

        return $carpetas;
    }

    /**
     * La abogada responsable, deducida de la carpeta de Drive.
     *
     * La estructura del despacho es `<abogada> / <empresa> / <asunto>`, y el
     * mapeo guarda los dos primeros tramos en `drive_folder_name`
     * («DRA CAROLINA / 3 ELIAS ACOSTA»). Drive YA sabe quién lleva cada
     * cliente: no hace falta preguntarlo.
     *
     * Se compara por palabras y sin tildes, porque la carpeta usa el nombre
     * corto («DRA CAROLINA») y el sistema el completo («María Carolina Ramos
     * Sepúlveda»). Se descartan los tratamientos y se exigen DOS coincidencias
     * cuando el nombre de la carpeta las permite, para que un «DRA» suelto no
     * empareje con cualquiera.
     */
    protected function abogadaDe(Client $client): ?User
    {
        $carpeta = (string) $client->drive_folder_name;
        if (! str_contains($carpeta, '/')) {
            return null;
        }

        $palabras = fn (string $t) => collect(preg_split('/[^\p{L}]+/u', Str::ascii(Str::lower($t))))
            ->filter(fn ($p) => mb_strlen($p) >= 3 && ! in_array($p, ['dra', 'dro', 'abg', 'del', 'las', 'los'], true))
            ->values();

        $deLaCarpeta = $palabras(explode('/', $carpeta)[0]);
        if ($deLaCarpeta->isEmpty()) {
            return null;
        }

        $exigidas = min(2, $deLaCarpeta->count());

        return $this->usuarios()->first(
            fn (User $u) => $deLaCarpeta->intersect($palabras((string) $u->name))->count() >= $exigidas
        );
    }

    /** @return Collection<int,User> */
    protected function usuarios(): Collection
    {
        return $this->usuarios ??= User::query()->get();
    }

    /** @var Collection<int,User>|null */
    protected ?Collection $usuarios = null;

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
