<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Document;
use App\Services\DocumentSummarizer;
use Illuminate\Console\Command;

/**
 * Resume los documentos que todavía no tienen ficha de catálogo.
 *
 * Se corre una vez para ponerse al día con lo que ya está cargado, y después
 * solo alcanza a los documentos nuevos: el resumen se cachea en el propio
 * documento y no se rehace salvo que el documento cambie.
 *
 * Por defecto es un ENSAYO: dice a cuántos les tocaría y cuánto costaría, sin
 * llamar a la API. Hay que pedir explícitamente `--ejecutar` para gastar.
 */
class DocumentsSummarize extends Command
{
    protected $signature = 'documents:summarize
        {--client= : Solo los documentos de este cliente (id, NIT o parte de la razón social)}
        {--limit=0 : Techo de documentos a procesar en esta corrida (0 = sin techo)}
        {--force : Rehace también los que ya tienen resumen}
        {--ejecutar : Llama a la API de verdad. Sin esto solo se estima}';

    protected $description = 'Genera el resumen por documento que alimenta la ficha de conocimiento del cliente';

    /**
     * Costo observado por documento en las mediciones de agosto de 2026.
     *
     * Sirve para que el ensayo dé una cifra con la que decidir, no para
     * facturar: el costo real queda registrado en `ai_generations`.
     */
    private const COSTO_ESTIMADO = 0.0114;

    public function handle(DocumentSummarizer $summarizer): int
    {
        $query = Document::query()
            ->whereNotNull('texto_extraido')
            ->where('texto_extraido', '!=', '');

        if ($filtro = $this->option('client')) {
            $client = $this->resolverCliente($filtro);
            if (! $client) {
                $this->error("No encontré ningún cliente con «{$filtro}».");

                return self::FAILURE;
            }
            $this->line("Cliente: {$client->razon_social}");
            $query->where('client_id', $client->id);
        }

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('resumen_ia')->orWhere('resumen_ia', '');
            });
        }

        $total = (clone $query)->count();

        if ($limite = (int) $this->option('limit')) {
            $query->limit($limite);
        }

        $docs = $query->orderBy('id')->get();

        if ($docs->isEmpty()) {
            $this->info($total === 0
                ? 'No hay documentos pendientes de resumir.'
                : 'Nada que hacer con los filtros dados.');

            return self::SUCCESS;
        }

        if (! $this->option('ejecutar')) {
            $this->warn('ENSAYO — no se llamó a la API.');
            $this->line("Documentos que se resumirían: {$docs->count()} (pendientes en total: {$total})");
            $this->line('Costo estimado: $'.number_format($docs->count() * self::COSTO_ESTIMADO, 2).' USD');
            $this->newLine();
            $this->line('Para hacerlo de verdad, repite el comando con --ejecutar');

            return self::SUCCESS;
        }

        $barra = $this->output->createProgressBar($docs->count());
        $barra->start();

        $ok = 0;
        $fallidos = 0;

        foreach ($docs as $doc) {
            $resumen = $summarizer->summarize($doc, (bool) $this->option('force'));
            $resumen === null ? $fallidos++ : $ok++;
            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("Resumidos: {$ok}");

        if ($fallidos > 0) {
            $this->warn("Fallaron: {$fallidos} (quedaron registrados en ai_generations con estado 'error')");
        }

        $this->line('Ahora conviene regenerar las fichas afectadas para que usen los resúmenes nuevos.');

        return self::SUCCESS;
    }

    private function resolverCliente(string $filtro): ?Client
    {
        if (ctype_digit($filtro) && $c = Client::find((int) $filtro)) {
            return $c;
        }

        return Client::where('nit', $filtro)
            ->orWhere('razon_social', 'like', '%'.$filtro.'%')
            ->first();
    }
}
