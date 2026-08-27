<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Services\AiService;
use App\Services\EmailRouter;
use App\Services\GmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ingestionId) {}

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
            //
            // La llamada va en su propio try para poder registrar el gasto
            // tambien cuando falla: si el modelo respondio y lo que rompio fue
            // el parseo, ese correo ya esta pagado. Sin esto, el camino de IA
            // con mas volumen de la app era el unico que no dejaba rastro en
            // `ai_generations`, y el panel de costos mentia por defecto.
            $classification = $this->clasificar($ai, $ingestion,
                [
                    'from' => $ingestion->from ?? '',
                    'subject' => $ingestion->subject ?? '',
                    'body_text' => $ingestion->body_text ?? '',
                    'attachments' => $this->attachmentFilenames($ingestion),
                ],
                [
                    'known_processes' => $this->knownProcesses(),
                    'known_clients' => $this->knownClients(),
                    'known_service_types' => ServiceType::query()
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

            // 4) Marcar como leído en Gmail para que el siguiente sondeo (is:unread)
            //    no lo vuelva a traer y el buzón refleje lo pendiente. Best-effort:
            //    el correo ya quedó procesado, así que un fallo aquí no debe marcarlo
            //    como `failed` ni reintentar el pipeline completo.
            try {
                $gmail->markAsRead($ingestion->message_id);
            } catch (Throwable $e) {
                report($e);
            }
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
        return Client::query()
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
        return Process::query()
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

    /**
     * Clasifica el correo y deja el gasto registrado, salga bien o mal.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function clasificar(AiService $ai, EmailIngestion $ingestion, array $payload, array $context): array
    {
        try {
            $classification = $ai->classifyEmail($payload, $context);
        } catch (Throwable $e) {
            // Puede haber respondido y haber roto el parseo: pagado igual.
            $this->registrarGasto($ingestion, null, 'error', $e);

            throw $e;
        }

        $this->registrarGasto($ingestion, $classification, 'ok');

        return $classification;
    }

    /**
     * Una fila en `ai_generations` por clasificación.
     *
     * Nunca hace fallar el pipeline: el correo ya está clasificado y perderlo
     * por no poder anotar su costo sería cambiar un problema de contabilidad
     * por uno de datos.
     *
     * @param  array<string, mixed>|null  $classification
     */
    protected function registrarGasto(EmailIngestion $ingestion, ?array $classification, string $estado, ?Throwable $e = null): void
    {
        try {
            $costo = 0.0;

            if ($classification) {
                try {
                    $costo = app(AiService::class)->estimateCost(
                        $classification['usage']['input_tokens'] ?? 0,
                        $classification['usage']['output_tokens'] ?? 0,
                        $classification['model'] ?? null,
                    );
                } catch (Throwable) {
                    $costo = 0.0;
                }
            }

            AiGeneration::create([
                'user_id' => null,
                'contexto_tipo' => EmailIngestion::class,
                'contexto_id' => $ingestion->id,
                'proveedor' => 'anthropic',
                'modelo' => $classification['model'] ?? config('anthropic.model'),
                'request_hash' => $classification['request_hash'] ?? null,
                'prompt' => 'classify_email: '.Str::limit((string) $ingestion->subject, 120),
                'respuesta' => $classification ? json_encode($classification, JSON_UNESCAPED_UNICODE) : '',
                'tokens_in' => $classification['usage']['input_tokens'] ?? 0,
                'tokens_out' => $classification['usage']['output_tokens'] ?? 0,
                'latencia_ms' => $classification['latencia_ms'] ?? 0,
                'costo_usd' => $costo,
                'estado' => $estado,
                'error_mensaje' => $e ? Str::limit($e->getMessage(), 500) : null,
            ]);
        } catch (Throwable $fallo) {
            report($fallo);
        }
    }
}
