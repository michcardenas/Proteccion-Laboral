<?php

namespace App\Jobs;

use App\Models\EmailIngestion;
use App\Services\GmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PollGmailInbox implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $maxResults = 50)
    {
    }

    public function handle(GmailService $gmail): void
    {
        try {
            $messages = $gmail->fetchUnread($this->maxResults);
        } catch (RuntimeException $e) {
            // Sin cuenta de Gmail conectada (o token inválido): se omite el poll.
            Log::info('PollGmailInbox: '.$e->getMessage());

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
                    'from' => $message['from'] ?? '',
                    'to' => $message['to'] ?? '',
                    'subject' => $message['subject'] ?? '',
                    'body_text' => $message['body_text'] ?? '',
                    'received_at' => $this->parseReceivedAt($message['received_at'] ?? null) ?? now(),
                    'raw_payload' => $message,
                    'status' => EmailIngestion::STATUS_PENDING,
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
        } catch (\Throwable $e) {
            return null;
        }
    }
}
