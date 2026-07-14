<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientKnowledgeService;
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
 */
class RegenerateClientKnowledge implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Segundos que el lock de unicidad permanece activo. */
    public int $uniqueFor = 300;

    /**
     * @param  bool  $force  Regenera aunque la ficha parezca al día. Necesario al BORRAR
     *                       un documento: no queda nada "más nuevo" que dispare la
     *                       detección de desactualización, pero la ficha ya no es fiel.
     */
    public function __construct(public int $clientId, public bool $force = false)
    {
    }

    public function uniqueId(): string
    {
        return $this->clientId.($this->force ? ':force' : '');
    }

    public function handle(ClientKnowledgeService $service): void
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

        $service->build($client);
    }
}
