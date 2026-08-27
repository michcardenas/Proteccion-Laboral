<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Document;
use App\Services\DocumentOcr;
use Illuminate\Console\Command;

/**
 * Lee los escaneados y fotos que no tienen texto.
 *
 * Por defecto es un ENSAYO: dice a cuántos les tocaría, de dónde saldría cada
 * archivo y cuánto costaría, sin llamar a la API. Hay que pedir `--ejecutar`.
 */
class DocumentsOcr extends Command
{
    protected $signature = 'documents:ocr
        {--client= : Solo los de este cliente (id, NIT o parte de la razón social)}
        {--limit=0 : Techo de documentos en esta corrida (0 = sin techo)}
        {--incluir-firmas : Lee tambien los logos incrustados en correos (por defecto se omiten)}
        {--ejecutar : Llama a la API de verdad. Sin esto solo se estima}';

    protected $description = 'Extrae el texto de escaneados e imágenes con la vista del modelo';

    /** Costo observado por imagen. Se ajusta con lo que quede en `ai_generations`. */
    private const COSTO_ESTIMADO = 0.012;

    public function handle(DocumentOcr $ocr): int
    {
        $query = Document::query()
            ->where(fn ($q) => $q->whereNull('texto_extraido')->orWhere('texto_extraido', ''))
            ->where(function ($q) {
                foreach (DocumentOcr::SOPORTADAS as $ext) {
                    $q->orWhere('nombre', 'like', '%.'.$ext);
                }
            });

        if ($filtro = $this->option('client')) {
            $client = $this->resolverCliente($filtro);
            if (! $client) {
                $this->error("No encontré ningún cliente con «{$filtro}».");

                return self::FAILURE;
            }
            $this->line("Cliente: {$client->razon_social}");
            $query->where('client_id', $client->id);
        }

        if ($limite = (int) $this->option('limit')) {
            $query->limit($limite);
        }

        $docs = $query->orderBy('id')->get();

        // Los logos de firma de correo se omiten por defecto: su texto acabaria
        // en el resumen del documento y de ahi en la ficha del cliente, que es
        // peor que dejarlos vacios. Ver DocumentOcr::pareceFirmaDeCorreo().
        $omitidas = 0;
        if (! $this->option('incluir-firmas')) {
            $antes = $docs->count();
            $docs = $docs->reject(fn (Document $d) => $ocr->pareceFirmaDeCorreo($d))->values();
            $omitidas = $antes - $docs->count();
        }

        if ($omitidas > 0) {
            $this->line("Omitidas {$omitidas} imagenes incrustadas en correos (logos de firma). Con --incluir-firmas se leen igual.");
        }

        if ($docs->isEmpty()) {
            $this->info('No hay imágenes pendientes de leer.');

            return self::SUCCESS;
        }

        if (! $this->option('ejecutar')) {
            $enDrive = $docs->where('disco', 'gdrive')->count();

            $this->warn('ENSAYO — no se llamó a la API.');
            $this->line("Imágenes que se leerían: {$docs->count()}");
            $this->line('  desde disco: '.($docs->count() - $enDrive).' · descargando de Drive: '.$enDrive);
            $this->line('Costo estimado: $'.number_format($docs->count() * self::COSTO_ESTIMADO, 2).' USD');
            $this->newLine();
            $this->line('Para hacerlo de verdad, repite el comando con --ejecutar');

            return self::SUCCESS;
        }

        $barra = $this->output->createProgressBar($docs->count());
        $barra->start();

        $conTexto = 0;
        $sinTexto = 0;

        foreach ($docs as $doc) {
            $texto = $ocr->read($doc);
            $texto === null ? $sinTexto++ : $conTexto++;
            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("Con texto leído: {$conTexto}");

        if ($sinTexto > 0) {
            // No todas son un fallo: una foto de una fachada o un logo no tiene
            // texto que sacar, y eso es un resultado válido. El detalle de cuáles
            // fallaron de verdad está en `ai_generations` con estado 'error'.
            $this->warn("Sin texto legible o con error: {$sinTexto} — mira ai_generations para distinguirlos");
        }

        $this->line('Ahora conviene regenerar las fichas de los clientes afectados.');

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
