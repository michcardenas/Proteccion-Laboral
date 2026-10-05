<?php

namespace Tests\Feature\Admin;

use App\Jobs\GenerateAiDraft;
use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\GmailService;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');
    }

    protected function makeProcess(string $codigo = 'PL-MAIL-1'): Process
    {
        $serviceType = ServiceType::firstOrCreate(
            ['slug' => 'servicio-test'],
            ['nombre' => 'Servicio test', 'descripcion' => 'x', 'modalidad' => 'por_evento', 'es_activo' => true],
        );

        return Process::factory()->create([
            'client_id' => Client::factory()->create()->id,
            'service_type_id' => $serviceType->id,
            'codigo' => $codigo,
            'titulo' => 'Proceso con correo',
        ]);
    }

    protected function makeEmail(Process $process): EmailIngestion
    {
        return EmailIngestion::create([
            'message_id' => 'gmail-msg-1',
            'from' => 'Cliente SAS <contacto@cliente.com>',
            'to' => 'automatizacion@proteccionlaboral.co',
            'subject' => 'Consulta sobre mi caso',
            'received_at' => now(),
            'raw_payload' => ['thread_id' => 'thr-1', 'message_id_header' => '<orig@mail.com>'],
            'body_text' => 'Buenas, ¿cómo va mi proceso?',
            'status' => EmailIngestion::STATUS_PROCESSED,
            'process_id' => $process->id,
        ]);
    }

    public function test_reply_envia_y_registra_comentario(): void
    {
        $mock = Mockery::mock(GmailService::class);
        $mock->shouldReceive('sendReply')->once()
            ->with(Mockery::on(fn ($p) => $p['to'] === 'contacto@cliente.com'
                && $p['thread_id'] === 'thr-1'
                && $p['in_reply_to'] === '<orig@mail.com>'))
            ->andReturn('sent-123');
        $mock->shouldReceive('markAsRead')->once()->andReturnNull();
        $mock->shouldReceive('addLabel')->once()->andReturnNull();
        $this->app->instance(GmailService::class, $mock);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.emails.reply', [$process, $email]),
            ['to' => 'Cliente SAS <contacto@cliente.com>', 'subject' => 'Re: Consulta', 'body' => 'Vamos bien.'],
        );

        $response->assertStatus(201)->assertJson(['gmail_message_id' => 'sent-123']);
        $this->assertDatabaseHas('comments', [
            'commentable_type' => Process::class,
            'commentable_id' => $process->id,
        ]);
    }

    public function test_reply_con_adjuntos_los_envia_y_los_comparte_en_el_portal(): void
    {
        Storage::fake('local');

        $mock = Mockery::mock(GmailService::class);
        $mock->shouldReceive('sendReply')->once()
            ->with(Mockery::on(fn ($p) => count($p['attachments']) === 1
                && $p['attachments'][0]['filename'] === 'Concepto.docx'
                && is_string($p['attachments'][0]['content'])))
            ->andReturn('sent-123');
        $mock->shouldReceive('markAsRead', 'addLabel')->andReturnNull();
        $this->app->instance(GmailService::class, $mock);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $this->actingAs($user)->post(
            route('admin.processes.emails.reply', [$process, $email]),
            [
                'to' => 'contacto@cliente.com', 'subject' => 'Re: Consulta', 'body' => 'Adjunto el concepto.',
                'visible_cliente' => '1',
                'adjuntos' => [UploadedFile::fake()->create('Concepto.docx', 40, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(201);

        $doc = Document::sole();
        $this->assertSame('Concepto.docx', $doc->nombre);
        $this->assertSame($process->id, $doc->process_id);
        $this->assertTrue($doc->visible_cliente);
        $this->assertNotNull($doc->comment_id);
        $this->assertSame($process->comments()->sole()->id, $doc->comment_id);
        Storage::disk('local')->assertExists($doc->ruta);
    }

    public function test_reply_rechaza_adjuntos_que_gmail_no_admite_sin_enviar(): void
    {
        Storage::fake('local');

        $mock = Mockery::mock(GmailService::class);
        $mock->shouldNotReceive('sendReply');
        $this->app->instance(GmailService::class, $mock);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $this->actingAs($user)->post(
            route('admin.processes.emails.reply', [$process, $email]),
            [
                'to' => 'contacto@cliente.com', 'subject' => 'Re', 'body' => 'x',
                'adjuntos' => [
                    UploadedFile::fake()->create('a.pdf', 10 * 1024, 'application/pdf'),
                    UploadedFile::fake()->create('b.pdf', 10 * 1024, 'application/pdf'),
                ],
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(422);

        $this->assertSame(0, Document::count());
        $this->assertSame(0, $process->comments()->count());
    }

    public function test_si_gmail_falla_no_quedan_documentos(): void
    {
        Storage::fake('local');

        $mock = Mockery::mock(GmailService::class);
        $mock->shouldReceive('sendReply')->andThrow(new \RuntimeException('caido'));
        $this->app->instance(GmailService::class, $mock);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $this->actingAs($user)->post(
            route('admin.processes.emails.reply', [$process, $email]),
            ['to' => 'contacto@cliente.com', 'subject' => 'Re', 'body' => 'x', 'adjuntos' => [UploadedFile::fake()->create('a.docx', 10)]],
            ['Accept' => 'application/json'],
        )->assertStatus(502);

        $this->assertSame(0, Document::count());
    }

    public function test_reply_requiere_permiso_processes_update(): void
    {
        $user = User::factory()->create(['is_active' => true]); // sin rol
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $this->actingAs($user)->postJson(
            route('admin.processes.emails.reply', [$process, $email]),
            ['to' => 'a@b.com', 'subject' => 'x', 'body' => 'y'],
        )->assertForbidden();
    }

    public function test_reply_404_si_el_correo_no_es_del_proceso(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess('PL-MAIL-1');
        $otro = $this->makeProcess('PL-MAIL-2');
        $email = $this->makeEmail($otro); // pertenece a OTRO proceso

        $this->actingAs($user)->postJson(
            route('admin.processes.emails.reply', [$process, $email]),
            ['to' => 'a@b.com', 'subject' => 'x', 'body' => 'y'],
        )->assertNotFound();
    }

    public function test_draft_encola_la_respuesta_y_devuelve_202(): void
    {
        Queue::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // tiene ai.use
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $id = $this->actingAs($user)->postJson(
            route('admin.processes.emails.draft', [$process, $email]),
            ['instrucciones' => 'Sé breve'],
        )->assertStatus(202)->assertJson(['estado' => 'pendiente'])->json('id');

        $generation = AiGeneration::findOrFail($id);
        $this->assertSame('pendiente', $generation->estado);
        // El prompt persistido lleva el correo al que se responde y las instrucciones.
        $this->assertStringContainsString('¿cómo va mi proceso?', $generation->prompt);
        $this->assertStringContainsString('Sé breve', $generation->prompt);

        Queue::assertPushed(GenerateAiDraft::class, fn ($job) => $job->generationId === $id);
    }

    public function test_draft_de_ida_y_vuelta_con_la_cola_real(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Estimado cliente, su proceso avanza según lo previsto.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 90],
            ], 200),
        ]);
        config()->set('queue.default', 'database');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();
        $email = $this->makeEmail($process);

        $id = $this->actingAs($user)
            ->postJson(route('admin.processes.emails.draft', [$process, $email]))
            ->assertStatus(202)
            ->json('id');

        Artisan::call('queue:work', ['--once' => true, '--no-interaction' => true]);

        $this->actingAs($user)
            ->getJson(route('admin.processes.ai.show', ['process' => $process, 'generation' => $id]))
            ->assertStatus(200)
            ->assertJson(['estado' => 'ok', 'borrador' => 'Estimado cliente, su proceso avanza según lo previsto.']);
    }

    public function test_draft_404_si_el_correo_no_es_del_proceso(): void
    {
        Queue::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();
        $otro = $this->makeProcess('PL-MAIL-2');
        $email = $this->makeEmail($otro);

        $this->actingAs($user)
            ->postJson(route('admin.processes.emails.draft', [$process, $email]))
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ai_generations', 0);
    }
}
