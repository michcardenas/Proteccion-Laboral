<?php

namespace App\Jobs;

use App\Models\EmailIngestion;
use App\Models\IntegrationToken;
use App\Services\GmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PollGmailInbox implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $maxResults = 50) {}

    public function handle(GmailService $gmail): void
    {
        $cuentas = GmailService::cuentasConectadas();

        if ($cuentas->isEmpty()) {
            Log::info('PollGmailInbox: no hay ninguna cuenta de Gmail conectada.');

            return;
        }

        // Una bandeja por abogada. El fallo de una no puede dejar sin sondear
        // a las demas: un token caducado en una cuenta paraba el correo de
        // todo el despacho.
        foreach ($cuentas as $cuenta) {
            try {
                $this->sondear($gmail->paraCuenta($cuenta), $cuenta);
            } catch (Throwable $e) {
                report($e);
                Log::warning("PollGmailInbox [{$cuenta->account_email}]: ".$e->getMessage());
            }
        }
    }

    protected function sondear(GmailService $gmail, IntegrationToken $cuenta): void
    {
        try {
            $messages = $gmail->fetchUnread($this->maxResults);
        } catch (RuntimeException $e) {
            // Token invalido o revocado: se omite esta cuenta, no las demas.
            Log::info("PollGmailInbox [{$cuenta->account_email}]: ".$e->getMessage());

            return;
        }

        foreach ($messages as $message) {
            $messageId = $message['message_id'] ?? null;
            if (! $messageId) {
                continue;
            }

            // Dedup por message_id (la columna es unique + firstOrCreate evita la carrera).
            $ingestion = EmailIngestion::firstOrCreate(
                ['message_id' => $messageId],
                [
                    // Recortados a proposito. Las columnas ya son holgadas, pero
                    // una cabecera fuera de norma no puede volver a dejar un
                    // correo sin ingerir y al cron reintentandolo cada dos
                    // minutos para siempre. Mejor guardarlo cortado que perderlo.
                    'from' => Str::limit((string) ($message['from'] ?? ''), 490, ''),
                    'to' => Str::limit((string) ($message['to'] ?? ''), 60000, ''),
                    'subject' => Str::limit((string) ($message['subject'] ?? ''), 990, ''),
                    'body_text' => $message['body_text'] ?? '',
                    'received_at' => $this->parseReceivedAt($message['received_at'] ?? null) ?? now(),
                    'raw_payload' => $message,
                    'status' => EmailIngestion::STATUS_PENDING,
                    // De quien es este correo: decide quien puede verlo y desde
                    // que cuenta sale la respuesta.
                    'integration_token_id' => $cuenta->id,
                ]
            );

            // Solo despachamos el procesamiento para los correos realmente nuevos.
            if ($ingestion->wasRecentlyCreated) {
                ProcessInboundEmail::dispatch($ingestion->id);
            }
        }
    }

    protected function parseReceivedAt(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            return null;
        }
    }
}
