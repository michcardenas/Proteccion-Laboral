<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Informe en Markdown de todo lo que el despacho tiene de un cliente.
 *
 * El material vive en Drive (`ASESORIAS EMPRESAS / <abogada> / <empresa> / <asunto>`)
 * y la abogada que retoma un caso hoy no tiene forma de ponerse al día sin abrir
 * cientos de archivos. Esto lo lee y devuelve un documento de una sentada.
 *
 * Dos decisiones que sostienen la honestidad del informe:
 *
 * 1. A la IA se le da el MAPA COMPLETO (ruta y nombre de cada documento, que es
 *    barato) y ADEMÁS el texto de una parte. Así puede decir «existe una carpeta
 *    de liquidaciones» aunque no haya leído sus archivos, en vez de callarla.
 * 2. El inventario de lo que NO se pudo leer y el alcance del informe los escribe
 *    el código, no el modelo. Son hechos, y un modelo no debería poder maquillarlos.
 */
class InformeClienteService
{
    /** Documentos que se leen a fondo (los demás entran solo en el mapa). */
    public const MAX_DOCS_LEIDOS = 60;

    /** Recorte por documento, para que uno enorme no se coma el presupuesto. */
    public const MAX_TEXTO_DOC = 6000;

    /** Techo del texto que viaja en el prompt. */
    public const MAX_TEXTO_TOTAL = 120000;

    /**
     * Espacio de salida. Con 8000 el informe de un cliente grande se cortaba a
     * mitad de una tabla y las dos últimas secciones no llegaban a escribirse.
     */
    public const MAX_TOKENS_SALIDA = 20000;

    public function __construct(protected AiService $ai) {}

    /**
     * @return array{markdown: string, stats: array, ruta: ?string}
     */
    public function generar(Client $client, bool $guardar = true): array
    {
        $docs = Document::query()
            ->where('client_id', $client->id)
            ->whereNotNull('drive_file_id')
            ->orderByDesc('drive_modified_at')
            ->get(['id', 'nombre', 'tipo', 'mime', 'drive_modified_at', 'texto_extraido']);

        $legibles = $docs->filter(fn ($d) => trim((string) $d->texto_extraido) !== '');
        $ilegibles = $docs->reject(fn ($d) => trim((string) $d->texto_extraido) !== '');

        $stats = [
            'total' => $docs->count(),
            'legibles' => $legibles->count(),
            'ilegibles' => $ilegibles->count(),
            'leidos' => 0,
        ];

        if ($docs->isEmpty()) {
            return ['markdown' => $this->sinDocumentos($client), 'stats' => $stats, 'ruta' => null];
        }

        [$extractos, $leidos] = $this->extractos($legibles);
        $stats['leidos'] = $leidos;

        $respuesta = $this->ai->generateDraft(
            $this->prompt($client, $docs, $extractos),
            $this->systemPrompt(),
            ['max_tokens' => self::MAX_TOKENS_SALIDA, 'temperature' => 0.2, 'timeout' => 600]
        );

        $markdown = trim((string) ($respuesta['text'] ?? ''));
        $truncado = ($respuesta['stop_reason'] ?? '') === 'max_tokens';
        $stats['truncado'] = $truncado;

        if ($truncado) {
            // Callarlo sería lo peor: quien lo lea creería que el análisis
            // terminó y que no había más que decir.
            $markdown .= "\n\n> **Aviso: el informe quedó incompleto.** El modelo agotó el espacio "
                .'de salida antes de terminar. Vuelve a generarlo o reduce el número de documentos leídos.';
        }

        if ($markdown === '') {
            $markdown = "# {$client->razon_social}\n\n> La IA no devolvió contenido. Reintenta la generación.";
        }

        $markdown = $this->encabezado($client, $stats)."\n\n".$markdown."\n\n".$this->anexos($ilegibles, $stats);

        $ruta = $guardar ? $this->guardar($client, $markdown) : null;

        return ['markdown' => $markdown, 'stats' => $stats, 'ruta' => $ruta];
    }

    /**
     * Texto de los documentos más recientes, con los tres topes aplicados.
     *
     * @return array{0: string, 1: int}
     */
    protected function extractos($legibles): array
    {
        $partes = [];
        $total = 0;
        $leidos = 0;

        foreach ($legibles->take(self::MAX_DOCS_LEIDOS) as $doc) {
            $texto = Str::limit(trim((string) $doc->texto_extraido), self::MAX_TEXTO_DOC, '… [recortado]');

            if ($total + mb_strlen($texto) > self::MAX_TEXTO_TOTAL) {
                break;
            }

            $fecha = $doc->drive_modified_at?->format('Y-m-d') ?? 's/f';
            $partes[] = "### {$doc->nombre}  (modificado {$fecha})\n{$texto}";
            $total += mb_strlen($texto);
            $leidos++;
        }

        return [implode("\n\n", $partes), $leidos];
    }

    protected function systemPrompt(): string
    {
        return <<<'TXT'
        Eres un abogado laboralista senior de un despacho colombiano. Escribes para
        una compañera que acaba de recibir este cliente y necesita ponerse al día.

        Reglas que no puedes romper:
        - Escribe SOLO lo que se desprenda de los documentos. Nada de rellenar huecos.
        - Si algo es dudoso, dilo con esas palabras: «no queda claro en los documentos».
        - Nunca inventes fechas, cifras, números de radicado ni nombres.
        - Cuando afirmes algo importante, di de qué documento sale.
        - Español de Colombia, directo y sin retórica.
        - Devuelve SOLO Markdown, sin explicar lo que vas a hacer.

        Estructura exacta que debes seguir:

        ## Resumen ejecutivo
        Tres a cinco frases: qué empresa es, qué se le lleva y en qué estado está.

        ## Asuntos identificados
        Un `###` por asunto. En cada uno: de qué se trata, qué se ha hecho, en qué
        quedó. Si el nombre de la carpeta indica un asunto que no alcanzaste a leer,
        inclúyelo y márcalo como «sin revisar en este informe».

        ## Fechas y plazos
        Lista de fechas relevantes que aparezcan en los documentos, con su origen.
        Si no encuentras ninguna, escribe «No se identificaron fechas explícitas».

        ## Riesgos y pendientes
        Qué quedó sin cerrar y qué conviene revisar. Sé concreto.

        ## Qué falta o no está claro
        Vacíos que detectaste: documentos que se mencionan pero no están, asuntos
        sin desenlace, contradicciones entre documentos.
        TXT;
    }

    protected function prompt(Client $client, $docs, string $extractos): string
    {
        $mapa = $docs->map(function ($d) {
            $fecha = $d->drive_modified_at?->format('Y-m-d') ?? 's/f';
            $marca = trim((string) $d->texto_extraido) === '' ? ' [ILEGIBLE]' : '';

            return "- {$d->nombre} ({$fecha}){$marca}";
        })->implode("\n");

        $nit = $client->nit ? "NIT {$client->nit}" : 'sin NIT registrado';

        return <<<TXT
        # Cliente
        {$client->razon_social} ({$nit})

        # Mapa completo de la carpeta en Drive
        Estos son TODOS los documentos que el despacho tiene de este cliente. Las rutas
        indican la organización por asuntos. Los marcados [ILEGIBLE] existen pero no se
        pudo extraer su texto (escaneados sin OCR o formato no soportado): puedes
        mencionarlos por su nombre, pero NO supongas su contenido.

        {$mapa}

        # Contenido de los documentos que sí se pudieron leer

        {$extractos}
        TXT;
    }

    /** Encabezado factual: lo escribe el código, no el modelo. */
    protected function encabezado(Client $client, array $stats): string
    {
        $nit = $client->nit ? " · NIT {$client->nit}" : '';
        $carpeta = $client->drive_folder_name ? " · Carpeta: {$client->drive_folder_name}" : '';

        return "# {$client->razon_social}\n\n"
            .'*Informe generado automáticamente el '.now()->format('d/m/Y H:i')."{$nit}{$carpeta}*"
            .(($stats['truncado'] ?? false)
                ? "\n\n> **Este informe quedó incompleto** (ver aviso al final del análisis)."
                : '');
    }

    /** Inventario de lo no leído y alcance real. También factual. */
    protected function anexos($ilegibles, array $stats): string
    {
        $md = "---\n\n## Alcance de este informe\n\n";
        $md .= "Documentos del cliente en Drive: **{$stats['total']}**. ";
        $md .= "Con texto legible: **{$stats['legibles']}**. ";
        $md .= "Leídos a fondo para este informe: **{$stats['leidos']}**.\n\n";

        if ($stats['leidos'] < $stats['legibles']) {
            $md .= '> Se leyeron los más recientes. Los demás aparecen en el mapa por nombre y ruta, '
                ."pero su contenido no entró en el análisis.\n\n";
        }

        if ($ilegibles->isEmpty()) {
            return $md."Todos los documentos pudieron leerse.\n";
        }

        $md .= "## Documentos que no se pudieron leer ({$stats['ilegibles']})\n\n";
        $md .= 'Existen en Drive pero la plataforma no pudo extraer su texto: suelen ser PDF '
            ."escaneados sin OCR, imágenes o archivos demasiado grandes. **La IA no los vio.**\n\n";

        foreach ($ilegibles as $d) {
            $fecha = $d->drive_modified_at?->format('Y-m-d') ?? 's/f';
            $md .= "- {$d->nombre} ({$fecha})\n";
        }

        return $md;
    }

    protected function sinDocumentos(Client $client): string
    {
        return "# {$client->razon_social}\n\n"
            .'No hay documentos sincronizados desde Drive para este cliente. '
            ."Verifica que tenga carpeta asignada y corre `php artisan drive:sync-knowledge --client={$client->id}`.\n";
    }

    /** Guarda el .md y lo deja colgado del cliente para que se vea en la plataforma. */
    protected function guardar(Client $client, string $markdown): string
    {
        $ruta = "clients/client_{$client->id}/informes/informe-".now()->format('Ymd-His').'.md';
        Storage::disk('local')->put($ruta, $markdown);

        Document::create([
            'client_id' => $client->id,
            'nombre' => 'Informe IA — '.$client->razon_social.' ('.now()->format('d/m/Y').').md',
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => 'informe',
            'mime' => 'text/markdown',
            'tamano_bytes' => mb_strlen($markdown),
            'generado_por_ia' => true,
            'visible_cliente' => false,
        ]);

        return $ruta;
    }
}
