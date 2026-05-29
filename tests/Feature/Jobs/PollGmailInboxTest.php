<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PollGmailInbox;
use App\Jobs\ProcessInboundEmail;
use App\Models\EmailIngestion;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PollGmailInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeMessage(string $id): array
    {
        return [
            'message_id' => $id,
            'from' => 'remitente@ejemplo.com',
            'to' => 'bandeja@proteccionlaboral.co',
            'subject' => 'Asunto '.$id,
            'body_text' => 'Cuerpo',
            'body_html' => '',
            'received_at' => now()->toIso8601String(),
            'attachments' => [],
        ];
    }

    public function test_persists_new_messages_and_dispatches_processing(): void
    {
        Bus::fake();

        // Correo ya ingerido previamente.
        EmailIngestion::create([
            'message_id' => 'existing',
            'from' => 'x@y.com',
            'to' => 'bandeja@proteccionlaboral.co',
            'subject' => 'Ya existe',
            'body_text' => 'b',
            'received_at' => now(),
            'raw_payload' => ['attachments' => []],
            'status' => EmailIngestion::STATUS_PROCESSED,
        ]);

        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('fetchUnread')->once()->andReturn([
            $this->fakeMessage('existing'),
            $this->fakeMessage('new-1'),
        ]);

        (new PollGmailInbox)->handle($gmail);

        // No se duplica el existente; se crea solo el nuevo.
        $this->assertDatabaseCount('email_ingestions', 2);
        $this->assertDatabaseHas('email_ingestions', [
            'message_id' => 'new-1',
            'status' => EmailIngestion::STATUS_PENDING,
        ]);

        $new = EmailIngestion::where('message_id', 'new-1')->firstOrFail();

        Bus::assertDispatchedTimes(ProcessInboundEmail::class, 1);
        Bus::assertDispatched(ProcessInboundEmail::class, fn ($job) => $job->ingestionId === $new->id);
    }

    public function test_skips_silently_when_gmail_not_connected(): void
    {
        Bus::fake();

        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('fetchUnread')
            ->andThrow(new RuntimeException('No hay una cuenta de Gmail conectada.'));

        (new PollGmailInbox)->handle($gmail);

        $this->assertDatabaseCount('email_ingestions', 0);
        Bus::assertNothingDispatched();
    }
}
