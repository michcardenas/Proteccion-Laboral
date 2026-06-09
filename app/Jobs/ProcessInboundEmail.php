<?php

namespace App\Jobs;

use App\Models\EmailIngestion;
use App\Services\AiService;
use App\Services\EmailRouter;
use App\Services\GmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ingestionId)
    {
    }

    public function handle(GmailService $gmail, AiService $ai, EmailRouter $router): void
    {
        $ingestion = EmailIngestion::find($this->ingestionId);

        // Idempotencia: si ya quedó procesado, no repetimos nada.
        if (! $ingestion || $ingestion->status === EmailIngestion::STATUS_PROCESSED) {
            return;
        }

        try {
            // 1) Guardar adjuntos en storage/app/inbound/{message_id}/ (idempotente).
            $this->saveAttachments($gmail, $ingestion);

            // 2) Clasificar con IA y persistir el resultado.
            $classification = $ai->classifyEmail(
                [
                    'from' => $ingestion->from ?? '',
                    'subject' => $ingestion->subject ?? '',
                    'body_text' => $ingestion->body_text ?? '',
                    'attachments' => $this->attachmentFilenames($ingestion),
                ],
                [
                    'known_processes' => $this->knownProcesses(),
                    'known_clients' => $this->knownClients(),
                    'known_service_types' => \App\Models\ServiceType::query()
                        ->where('es_activo', true)
                        ->orderBy('nombre')
                        ->pluck('nombre')
                        ->all(),
                ],
            );

            $ingestion->ai_classification = $classification;
            $ingestion->status = EmailIngestion::STATUS_CLASSIFIED;
            $ingestion->save();

            // 3) Despachar según la clasificación.
            $status = $router->route($ingestion);

            $ingestion->status = $status;
            $ingestion->processed_at = now();
            $ingestion->save();
        } catch (Throwable $e) {
            $ingestion->update([
                'status' => EmailIngestion::STATUS_FAILED,
                'error' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            report($e);
        }
    }

    /**
     * Descarga y guarda los adjuntos del mensaje en disco (salta los ya existentes).
     */
    protected function saveAttachments(GmailService $gmail, EmailIngestion $ingestion): void
    {
        if (empty($ingestion->raw_payload['attachments'] ?? [])) {
            return;
        }

        foreach ($gmail->getAttachments($ingestion->message_id) as $attachment) {
            $path = "inbound/{$ingestion->message_id}/{$attachment['filename']}";

            if (! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, $attachment['data']);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function attachmentFilenames(EmailIngestion $ingestion): array
    {
        return collect($ingestion->raw_payload['attachments'] ?? [])
            ->pluck('filename')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Contexto de procesos conocidos para ayudar a la clasificación (match por código).
     *
     * @return array<int, array{code: string, client_name: ?string, service_type: ?string}>
     */
    /**
     * Clientes activos para que la IA haga match por dominio/razón social.
     * Solo `estado = activo`: los prospectos no deben generar casos automáticos.
     *
     * @return array<int, array{razon_social: string, nit: ?string, email: ?string}>
     */
    protected function knownClients(): array
    {
        return \App\Models\Client::query()
            ->where('estado', 'activo')
            ->orderBy('razon_social')
            ->get(['razon_social', 'nit', 'email'])
            ->map(fn ($c) => [
                'razon_social' => $c->razon_social,
                'nit' => $c->nit,
                'email' => $c->email,
            ])
            ->all();
    }

    protected function knownProcesses(): array
    {
        return \App\Models\Process::query()
            ->with(['client:id,razon_social', 'serviceType:id,nombre'])
            ->latest('fecha_apertura')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'code' => $p->codigo,
                'client_name' => $p->client?->razon_social,
                'service_type' => $p->serviceType?->nombre,
            ])
            ->all();
    }
}
