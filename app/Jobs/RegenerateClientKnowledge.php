<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Document;
use App\Services\ClientKnowledgeService;
use App\Services\DocumentSummarizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Regenera la ficha de conocimiento de un cliente tras subir/borrar un documento.
 *
 * Se despacha en modo auto desde ClientDocumentController. Es idempotente y "debounced":
 * al ejecutar, si la ficha ya está al día (no hay documentos más nuevos que el digest),
 * se salta — así varias subidas seguidas colapsan en una sola regeneración efectiva.
 *
 * ShouldBeUnique evita encolar duplicados mientras uno está pendiente (drivers con lock).
 *
 * ANTES de armar la ficha resume los documentos que aún no tengan resumen. Sin
 * ese paso la cobertura se degradaba sola: cada documento nuevo entraba a la
 * ficha con su texto crudo —unos 11.000 caracteres frente a los 900 de un
 * resumen— y se comía el presupuesto que hace que quepan todos. Y no lo habría
 * notado nadie, porque la ficha sigue generándose igual: solo cubre menos.
 */
class RegenerateClientKnowledge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Segundos que el lock de unicidad permanece activo. */
    public int $uniqueFor = 300;

    /**
     * Techo de resúmenes por corrida.
     *
     * Un cliente al que le vuelcan cien documentos de golpe no puede convertir
     * una subida en cien llamadas a la API dentro del mismo job. Los que se
     * queden fuera entran con su texto crudo —peor cobertura, no un fallo— y los
     * recoge la siguiente regeneración o el comando `documents:summarize`.
     */
    public const MAX_RESUMENES_POR_CORRIDA = 25;

    /**
     * @param  bool  $force  Regenera aunque la ficha parezca al día. Necesario al BORRAR
     *                       un documento: no queda nada "más nuevo" que dispare la
     *                       detección de desactualización, pero la ficha ya no es fiel.
     */
    public function __construct(public int $clientId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return $this->clientId.($this->force ? ':force' : '');
    }

    public function handle(ClientKnowledgeService $service, DocumentSummarizer $summarizer): void
    {
        $client = Client::find($this->clientId);
        if ($client === null) {
            return;
        }

        // Debounce en subidas: si otra ejecución ya dejó la ficha al día, no gastamos API
        // de nuevo. En borrados (force) siempre regeneramos porque la staleness no lo detecta.
        if (! $this->force && ! $client->fichaDesactualizada()) {
            return;
        }

        $this->resumirPendientes($client, $summarizer);

        $service->build($client);
    }

    /**
     * Pone al día los resúmenes de este cliente antes de armar la ficha.
     *
     * Solo alcanza a los que no tienen resumen: el de un documento que no cambió
     * ya está cacheado y no se vuelve a pagar. Un fallo aquí no puede impedir que
     * la ficha se genere — `summarize()` no lanza, y un documento sin resumen
     * entra igual con su texto crudo, que es el comportamiento anterior.
     */
    protected function resumirPendientes(Client $client, DocumentSummarizer $summarizer): void
    {
        // Sin filtrar por proceso: la ficha ve todo el material del cliente
        // (ver Client::documentosCliente), asi que hay que resumirlo todo o los
        // documentos atados a un asunto entrarian con su texto crudo.
        Document::where('client_id', $client->id)
            ->whereNotNull('texto_extraido')
            ->where('texto_extraido', '!=', '')
            ->where(function ($q) {
                $q->whereNull('resumen_ia')->orWhere('resumen_ia', '');
            })
            ->orderByDesc('id')
            ->limit(self::MAX_RESUMENES_POR_CORRIDA)
            ->each(fn (Document $doc) => $summarizer->summarize($doc));
    }
}
