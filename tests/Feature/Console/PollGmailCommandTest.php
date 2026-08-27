<?php

namespace Tests\Feature\Console;

use App\Jobs\ProcessInboundEmail;
use App\Models\EmailIngestion;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PollGmailCommandTest extends TestCase
{
    use RefreshDatabase;

    /** El sondeo recorre las cuentas conectadas: sin ninguna, no hay nada que sondear. */
    protected function cuentaConectada(?string $email = null): IntegrationToken
    {
        return IntegrationToken::create([
            'provider' => IntegrationToken::PROVIDER_GMAIL,
            'account_email' => $email ?? 'bandeja-'.uniqid().'@proteccionlaboral.co',
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
            'connected_by_user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_command_ingesta_los_no_leidos(): void
    {
        // Fake parcial: solo el procesamiento por correo (que pegaría a la IA);
        // el sondeo PollGmailInbox sí corre real para crear la ingesta.
        Bus::fake([ProcessInboundEmail::class]);

        $this->cuentaConectada();

        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('paraCuenta')->andReturnSelf();
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
        $this->cuentaConectada();

        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('paraCuenta')->andReturnSelf();
        $gmail->shouldReceive('fetchUnread')->andThrow(new \RuntimeException('Gmail no conectado'));
        $this->app->instance(GmailService::class, $gmail);

        // No revienta: el job traga el RuntimeException y el comando sale 0.
        $this->artisan('gmail:poll')->assertExitCode(0);

        $this->assertSame(0, EmailIngestion::count());
    }
}
