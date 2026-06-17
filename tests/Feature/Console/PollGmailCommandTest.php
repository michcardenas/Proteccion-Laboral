<?php

namespace Tests\Feature\Console;

use App\Jobs\ProcessInboundEmail;
use App\Models\EmailIngestion;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PollGmailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_ingesta_los_no_leidos(): void
    {
        // Fake parcial: solo el procesamiento por correo (que pegaría a la IA);
        // el sondeo PollGmailInbox sí corre real para crear la ingesta.
        Bus::fake([ProcessInboundEmail::class]);

        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('fetchUnread')->once()->with(50)->andReturn([
            [
                'message_id' => 'cmd-1',
                'from' => 'remitente@ejemplo.com',
                'to' => 'bandeja@proteccionlaboral.co',
                'subject' => 'Hola',
                'body_text' => 'Cuerpo',
                'received_at' => now()->toIso8601String(),
                'attachments' => [],
            ],
        ]);
        $this->app->instance(GmailService::class, $gmail);

        $this->artisan('gmail:poll')->assertExitCode(0);

        // La fila quedó ingestada (dedup por message_id) y se encoló su procesamiento.
        $this->assertDatabaseHas('email_ingestions', ['message_id' => 'cmd-1']);
        Bus::assertDispatched(ProcessInboundEmail::class);
    }

    public function test_command_se_omite_si_gmail_no_conectado(): void
    {
        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('fetchUnread')->andThrow(new \RuntimeException('Gmail no conectado'));
        $this->app->instance(GmailService::class, $gmail);

        // No revienta: el job traga el RuntimeException y el comando sale 0.
        $this->artisan('gmail:poll')->assertExitCode(0);

        $this->assertSame(0, EmailIngestion::count());
    }
}
