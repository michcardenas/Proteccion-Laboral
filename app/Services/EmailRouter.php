<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\IntegrationToken;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Str;

class EmailRouter
{
    /**
     * Confianza mínima para auto-crear un Process desde un correo `nuevo_caso`.
     */
    public const NEW_PROCESS_CONFIDENCE = 0.7;

    /**
     * Despacha un EmailIngestion ya clasificado y devuelve el STATUS_* resultante.
     * Puede mutar y persistir `process_id` en la ingesta cuando corresponde.
     */
    public function route(EmailIngestion $ingestion): string
    {
        $classification = $ingestion->ai_classification ?? [];
        $action = $classification['action'] ?? 'requiere_revision_humana';
        $confidence = (float) ($classification['confidence'] ?? 0);

        return match ($action) {
            'nuevo_caso' => $this->handleNuevoCaso($ingestion, $classification, $confidence),
            'seguimiento_proceso' => $this->handleSeguimiento($ingestion, $classification),
            'documento_recibido' => $this->handleDocumento($ingestion, $classification),
            'comunicacion_cliente' => $this->handleComunicacion($ingestion, $classification),
            'spam_o_irrelevante' => EmailIngestion::STATUS_PROCESSED,
            default => EmailIngestion::STATUS_NEEDS_REVIEW,
        };
    }

    // ------------------------------------------------------------------
    // Ramas por acción
    // ------------------------------------------------------------------

    protected function handleNuevoCaso(EmailIngestion $ingestion, array $c, float $confidence): string
    {
        // Ya procesado en una corrida previa → idempotente.
        if ($ingestion->process_id) {
            return EmailIngestion::STATUS_PROCESSED;
        }

        if ($confidence < self::NEW_PROCESS_CONFIDENCE) {
            return EmailIngestion::STATUS_NEEDS_REVIEW;
        }

        $client = $this->matchClient($c['client_name'] ?? null);
        $serviceType = $this->matchServiceType($c['service_type'] ?? null);

        // Sin cliente o servicio claro, no auto-creamos: requiere revisión humana.
        if (! $client || ! $serviceType) {
            return EmailIngestion::STATUS_NEEDS_REVIEW;
        }

        $process = app(ProcessService::class)->createFromTemplate([
            'client_id' => $client->id,
            'service_type_id' => $serviceType->id,
            'codigo' => $this->generateCode(),
            'titulo' => $c['summary'] ?? $ingestion->subject ?? 'Caso entrante',
            'descripcion' => $ingestion->body_text,
            'estado' => 'abierto',
            'fecha_apertura' => now()->toDateString(),
        ]);

        $ingestion->process_id = $process->id;
        $ingestion->save();

        $this->attachComment($process, $ingestion);
        $this->attachAttachmentsAsDocuments($process, $ingestion);

        return EmailIngestion::STATUS_PROCESSED;
    }

    protected function handleSeguimiento(EmailIngestion $ingestion, array $c): string
    {
        $process = $this->matchProcessByCode($c['process_code'] ?? null);
        if (! $process) {
            return EmailIngestion::STATUS_NEEDS_REVIEW;
        }

        $ingestion->process_id = $process->id;
        $ingestion->save();

        $this->attachComment($process, $ingestion);
        $this->attachAttachmentsAsDocuments($process, $ingestion);

        return EmailIngestion::STATUS_PROCESSED;
    }

    protected function handleDocumento(EmailIngestion $ingestion, array $c): string
    {
        $process = $this->matchProcessByCode($c['process_code'] ?? null)
            ?? ($ingestion->process_id ? $ingestion->process : null);

        if (! $process) {
            return EmailIngestion::STATUS_NEEDS_REVIEW;
        }

        $ingestion->process_id = $process->id;
        $ingestion->save();

        $this->attachAttachmentsAsDocuments($process, $ingestion);
        $this->attachComment($process, $ingestion);

        return EmailIngestion::STATUS_PROCESSED;
    }

    protected function handleComunicacion(EmailIngestion $ingestion, array $c): string
    {
        $process = $this->matchProcessByCode($c['process_code'] ?? null);
        if (! $process) {
            return EmailIngestion::STATUS_NEEDS_REVIEW;
        }

        $ingestion->process_id = $process->id;
        $ingestion->save();

        $this->attachComment($process, $ingestion);

        return EmailIngestion::STATUS_PROCESSED;
    }

    // ------------------------------------------------------------------
    // Matchers
    // ------------------------------------------------------------------

    protected function matchProcessByCode(?string $code): ?Process
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        return Process::query()->where('codigo', $code)->first();
    }

    protected function matchClient(?string $name): ?Client
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return Client::query()
            ->where('razon_social', 'like', "%{$name}%")
            ->orWhere('nit', $name)
            ->first();
    }

    protected function matchServiceType(?string $name): ?ServiceType
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return ServiceType::query()
            ->where('nombre', 'like', "%{$name}%")
            ->first();
    }

    // ------------------------------------------------------------------
    // Creación idempotente de artefactos
    // ------------------------------------------------------------------

    /**
     * Agrega (una sola vez) un comentario al proceso con el resumen del correo.
     */
    protected function attachComment(Process $process, EmailIngestion $ingestion): void
    {
        if ($process->comments()->where('email_ingestion_id', $ingestion->id)->exists()) {
            return;
        }

        $userId = $this->systemUserId($process);
        if (! $userId) {
            return; // sin un autor válido no podemos crear el comentario
        }

        $c = $ingestion->ai_classification ?? [];
        $lines = ['[Correo entrante] '.($c['summary'] ?? $ingestion->subject ?? '(sin asunto)')];
        if ($ingestion->from) {
            $lines[] = 'De: '.$ingestion->from;
        }
        if (! empty($c['action'])) {
            $lines[] = 'Clasificación IA: '.$c['action'].(isset($c['confidence']) ? ' ('.$c['confidence'].')' : '');
        }

        $process->comments()->create([
            'user_id' => $userId,
            'email_ingestion_id' => $ingestion->id,
            'body' => implode("\n", $lines),
            'visible_cliente' => false,
        ]);
    }

    /**
     * Registra los adjuntos del correo como Documents del proceso (idempotente por ruta).
     */
    protected function attachAttachmentsAsDocuments(Process $process, EmailIngestion $ingestion): void
    {
        $attachments = $ingestion->raw_payload['attachments'] ?? [];

        foreach ($attachments as $att) {
            $filename = $att['filename'] ?? null;
            if (! $filename) {
                continue;
            }

            $ruta = "inbound/{$ingestion->message_id}/{$filename}";

            Document::firstOrCreate(
                ['email_ingestion_id' => $ingestion->id, 'ruta' => $ruta],
                [
                    'process_id' => $process->id,
                    'client_id' => $process->client_id,
                    'nombre' => $filename,
                    'disco' => 'local',
                    'tipo' => 'soporte',
                    'mime' => $att['mime_type'] ?? null,
                    'tamano_bytes' => $att['size'] ?? null,
                    'generado_por_ia' => false,
                    'visible_cliente' => false,
                ]
            );
        }
    }

    /**
     * Resuelve un usuario "del sistema" para atribuir comentarios automáticos:
     * la cuenta que conectó Gmail, o el equipo del proceso, o cualquier director.
     */
    protected function systemUserId(Process $process): ?int
    {
        return IntegrationToken::query()
                ->where('provider', IntegrationToken::PROVIDER_GMAIL)
                ->value('connected_by_user_id')
            ?? $process->abogado_lider_id
            ?? $process->coordinador_id
            ?? User::query()->value('id');
    }

    protected function generateCode(): string
    {
        return 'PL-INBOX-'.now()->format('Ymd').'-'.Str::upper(Str::random(4));
    }
}
