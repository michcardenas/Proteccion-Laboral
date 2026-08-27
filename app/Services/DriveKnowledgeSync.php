<?php

namespace App\Services;

use App\Jobs\RegenerateClientKnowledge;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Espejo de la carpeta de Drive de un cliente hacia la tabla `documents`.
 *
 * El objetivo NO es duplicar Drive: es que el material del cliente exista como
 * `Document`, porque toda la cadena de IA ya construida arranca ahí —
 * DocumentTextExtractor cachea el texto, ClientKnowledgeService lo resume en la ficha
 * y ProcessContextBuilder inyecta ambas cosas en cada prompt. Sincronizar la carpeta
 * es, literalmente, lo único que faltaba para que la IA "conozca" al cliente.
 *
 * Es idempotente: cada documento recuerda de qué archivo de Drive vino
 * (`drive_file_id`) y con qué fecha de modificación (`drive_modified_at`); solo se
 * vuelve a descargar lo que cambió en Drive.
 */
class DriveKnowledgeSync
{
    /** Palabras clave → valor de `documents.tipo` (mismos tipos del módulo de clientes). */
    protected const TIPOS = [
        'contrato' => ['contrato', 'otrosi', 'otrosí', 'convenio', 'acuerdo'],
        'concepto' => ['concepto', 'dictamen'],
        'informe' => ['informe', 'diagnostico', 'diagnóstico', 'reporte', 'auditoria', 'auditoría'],
        'escrito' => ['demanda', 'contestacion', 'contestación', 'recurso', 'tutela', 'memorial'],
        'comunicacion' => ['carta', 'comunicacion', 'comunicación', 'circular', 'oficio'],
        'soporte' => ['factura', 'soporte', 'pago', 'comprobante', 'planilla', 'certificado'],
    ];

    public function __construct(
        protected readonly DriveService $drive,
        protected readonly DocumentTextExtractor $extractor,
    ) {
    }

    /**
     * Sincroniza la carpeta de Drive de un cliente.
     *
     * @param  array{force?: bool, dry_run?: bool, regenerar_ficha?: bool, drive_id?: string}  $opciones
     * @return array{nuevos: int, actualizados: int, omitidos: int, eliminados: int, errores: int, sin_texto: int, detalles: array<int, string>}
     *
     * @throws RuntimeException si el cliente no tiene carpeta mapeada.
     */
    public function syncClient(Client $client, array $opciones = []): array
    {
        if (! $client->drive_folder_id) {
            throw new RuntimeException("El cliente {$client->razon_social} no tiene carpeta de Drive mapeada (drive:map-clients).");
        }

        $force = (bool) ($opciones['force'] ?? false);
        $dryRun = (bool) ($opciones['dry_run'] ?? false);
        $regenerar = (bool) ($opciones['regenerar_ficha'] ?? true);
        $driveId = $opciones['drive_id'] ?? config('drive.shared_drive_id');

        $stats = [
            'nuevos' => 0, 'actualizados' => 0, 'omitidos' => 0,
            'eliminados' => 0, 'errores' => 0, 'sin_texto' => 0, 'detalles' => [],
        ];

        $archivos = $this->drive->listFilesRecursive($client->drive_folder_id, $driveId);
        $archivos = array_slice($archivos, 0, (int) config('drive.max_files_per_client', 200));

        $vistos = [];

        foreach ($archivos as $archivo) {
            $vistos[] = $archivo['id'];

            try {
                $resultado = $this->syncArchivo($client, $archivo, $force, $dryRun);
            } catch (Throwable $e) {
                report($e);
                $stats['errores']++;
                $stats['detalles'][] = "error: {$archivo['name']} — ".Str::limit($e->getMessage(), 120);

                continue;
            }

            $stats[$resultado['estado']]++;
            if ($resultado['sin_texto']) {
                $stats['sin_texto']++;
            }
            if ($resultado['estado'] !== 'omitidos') {
                $stats['detalles'][] = "{$resultado['estado']}: {$archivo['name']}";
            }
        }

        if (config('drive.prune', true) && ! $dryRun) {
            $stats['eliminados'] = $this->prune($client, $vistos);
        }

        $cambios = $stats['nuevos'] + $stats['actualizados'] + $stats['eliminados'];

        if (! $dryRun) {
            $client->forceFill(['drive_synced_at' => now()])->saveQuietly();

            // La ficha solo se regenera si algo cambió: es la única parte que gasta API.
            if ($cambios > 0 && $regenerar) {
                RegenerateClientKnowledge::dispatch($client->id, true);
            }
        }

        return $stats;
    }

    /**
     * Crea o actualiza el Document correspondiente a un archivo de Drive.
     *
     * @return array{estado: string, sin_texto: bool}
     */
    protected function syncArchivo(Client $client, array $archivo, bool $force, bool $dryRun): array
    {
        if ($this->drive->excedeTamano($archivo)) {
            return ['estado' => 'omitidos', 'sin_texto' => true];
        }

        $existente = Document::query()
            ->where('client_id', $client->id)
            ->where('drive_file_id', $archivo['id'])
            ->first();

        if ($existente && ! $force && ! $this->cambioEnDrive($existente, $archivo)) {
            return ['estado' => 'omitidos', 'sin_texto' => ($existente->texto_extraido ?? '') === ''];
        }

        $estado = $existente ? 'actualizados' : 'nuevos';

        if ($dryRun) {
            return ['estado' => $estado, 'sin_texto' => ! $this->drive->esLegible($archivo)];
        }

        $ext = $this->drive->targetExtension($archivo);
        $legible = $this->drive->esLegible($archivo);

        $texto = null;
        $ruta = $archivo['web_view_link'] ?? '';
        $disco = 'gdrive';
        $bytes = $archivo['size'];

        if ($legible) {
            $contenido = $this->drive->downloadFile($archivo);

            if ($contenido === null) {
                throw new RuntimeException('No se pudo descargar el archivo desde Drive.');
            }

            $bytes = strlen($contenido);
            $texto = $this->extractor->fromBytes($contenido, $ext);

            // Con store_files=false solo conservamos el texto: el documento queda como
            // enlace a Drive (disco `gdrive`), que es como ya los trata DocumentController.
            if (config('drive.store_files', true)) {
                $ruta = "clients/client_{$client->id}/drive/{$archivo['id']}.{$ext}";
                Storage::disk('local')->put($ruta, $contenido);
                $disco = 'local';
            }
        }

        $atributos = [
            'client_id' => $client->id,
            'nombre' => $this->nombreConRuta($archivo),
            'ruta' => $ruta,
            'disco' => $disco,
            'tipo' => $this->adivinarTipo($archivo['name']),
            'mime' => $archivo['mime_type'],
            'tamano_bytes' => $bytes,
            'generado_por_ia' => false,
            'visible_cliente' => false,
            'drive_file_id' => $archivo['id'],
            'drive_modified_at' => $archivo['modified_at'],
            // Se guarda el texto en la MISMA escritura que el resto para que
            // `texto_extraido_at` no quede por detrás de `updated_at`; si quedara por
            // detrás, DocumentTextExtractor lo daría por obsoleto y reintentaría en vano.
            'texto_extraido' => $texto ?? '',
            'texto_extraido_at' => now(),
        ];

        if ($existente) {
            $existente->update($atributos);
        } else {
            Document::create($atributos);
        }

        return ['estado' => $estado, 'sin_texto' => ($texto ?? '') === ''];
    }

    /**
     * ¿El archivo cambió en Drive desde la última sincronización?
     * Si no hay fecha guardada tratamos el documento como desactualizado.
     */
    protected function cambioEnDrive(Document $doc, array $archivo): bool
    {
        if ($doc->drive_modified_at === null || $archivo['modified_at'] === null) {
            return true;
        }

        return $archivo['modified_at']->greaterThan($doc->drive_modified_at);
    }

    /**
     * Marca como eliminados los documentos del cliente cuyo archivo ya no está en la
     * carpeta de Drive. Solo toca documentos que vinieron de Drive: los subidos a mano
     * desde la app no tienen `drive_file_id` y quedan intactos.
     */
    protected function prune(Client $client, array $idsVistos): int
    {
        $huerfanos = Document::query()
            ->where('client_id', $client->id)
            ->whereNotNull('drive_file_id')
            ->when($idsVistos !== [], fn ($q) => $q->whereNotIn('drive_file_id', $idsVistos))
            ->get();

        foreach ($huerfanos as $doc) {
            $doc->delete();
        }

        return $huerfanos->count();
    }

    /**
     * Antepone la subcarpeta al nombre para no perder el contexto ("Contratos/…").
     */
    protected function nombreConRuta(array $archivo): string
    {
        $nombre = ($archivo['path'] ?? '') !== ''
            ? $archivo['path'].'/'.$archivo['name']
            : $archivo['name'];

        return Str::limit($nombre, 200, '');
    }

    /**
     * Deduce el `tipo` del documento por palabras clave del nombre (o de su subcarpeta).
     */
    public function adivinarTipo(string $nombre): string
    {
        $normalizado = Str::lower($nombre);

        foreach (self::TIPOS as $tipo => $claves) {
            foreach ($claves as $clave) {
                if (str_contains($normalizado, $clave)) {
                    return $tipo;
                }
            }
        }

        return 'otro';
    }
}
