<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\IntegrationToken;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\CorreoDelDespacho;
use App\Services\EmailRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Leidy: «la idea es que adjunte el Word que fue adjuntado por correo; si no,
 * sería doble trabajo». Lo que manda el despacho y entra a la bandeja
 * conectada queda como mensaje del despacho con su Word.
 */
class CorreoDelDespachoTest extends TestCase
{
    use RefreshDatabase;

    private Process $process;

    private User $leidy;

    protected function setUp(): void
    {
        parent::setUp();
        ServiceType::create(['nombre' => 'Asesoría', 'slug' => 'asesoria', 'descripcion' => 'x', 'modalidad' => 'permanente', 'es_activo' => true]);
        $this->leidy = User::factory()->create(['email' => 'leidy@proteccionlaboral.co', 'is_active' => true]);
        IntegrationToken::create([
            'provider' => IntegrationToken::PROVIDER_GMAIL, 'account_email' => 'automatizacion@proteccionlaboral.co',
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => now()->addHour(), 'scopes' => [],
            'connected_by_user_id' => $this->leidy->id,
        ]);
        $client = Client::factory()->create(['email' => 'gerencia@empresademo.co', 'portal_activo' => true]);
        $this->process = Process::factory()->create([
            'client_id' => $client->id, 'abogado_lider_id' => $this->leidy->id, 'codigo' => 'PL-DEMO-9',
        ]);
    }

    private function correo(array $o = []): EmailIngestion
    {
        return EmailIngestion::create($o + [
            'message_id' => 'msg-'.uniqid(),
            'from' => 'Leidy Rodríguez <leidy@proteccionlaboral.co>',
            'to' => 'Gerencia <gerencia@empresademo.co>, automatizacion@proteccionlaboral.co',
            'subject' => 'RE: Citación a descargos',
            'body_text' => "Buenos días,\n\nAdjuntamos el acta.\n\nCordialmente,\nLeidy\n\nEl lun, 5 oct 2026 a las 9:00, Gerencia <gerencia@empresademo.co> escribió:\n> ¿Nos envían el acta?",
            'received_at' => now(),
            'status' => EmailIngestion::STATUS_PENDING,
            'ai_classification' => ['action' => 'seguimiento_proceso', 'process_code' => 'PL-DEMO-9', 'confidence' => 0.9],
            'raw_payload' => ['attachments' => [
                ['filename' => 'Acta descargos.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 40000, 'attachment_id' => 'a1'],
                ['filename' => 'image001.png', 'mime_type' => 'image/png', 'size' => 4000, 'attachment_id' => 'a2'],
            ]],
        ]);
    }

    public function test_la_respuesta_al_cliente_queda_como_mensaje_visible_con_su_word(): void
    {
        $e = $this->correo();
        app(EmailRouter::class)->route($e);

        $c = Comment::sole();
        $this->assertTrue($c->visible_cliente);
        $this->assertSame($this->leidy->id, $c->user_id);
        $this->assertStringStartsWith("📧 Respuesta enviada a gerencia@empresademo.co, automatizacion@proteccionlaboral.co\nAsunto: RE: Citación a descargos", $c->body);
        $this->assertStringContainsString('Adjuntamos el acta.', $c->body);
        // Sin lo citado del hilo.
        $this->assertStringNotContainsString('escribió', $c->body);
        $this->assertStringNotContainsString('¿Nos envían', $c->body);

        // El Word cuelga del mensaje y es visible; el logo de la firma no entra.
        $doc = Document::sole();
        $this->assertSame('Acta descargos.docx', $doc->nombre);
        $this->assertSame($c->id, $doc->comment_id);
        $this->assertTrue($doc->visible_cliente);
        $this->assertSame("inbound/{$e->message_id}/Acta descargos.docx", $doc->ruta);
    }

    public function test_el_cliente_lo_ve_en_el_portal_con_el_word_primero(): void
    {
        app(EmailRouter::class)->route($this->correo());

        $this->actingAs($this->process->client, 'client')
            ->get(route('portal.process', $this->process))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('process.mensajes.0.adjuntos.0.nombre', 'Acta descargos.docx'));
    }

    public function test_un_reenvio_a_la_bandeja_queda_interno_y_se_comparte_con_un_clic(): void
    {
        $e = $this->correo(['subject' => 'Fwd: Citación a descargos', 'to' => 'automatizacion@proteccionlaboral.co']);
        app(EmailRouter::class)->route($e);

        $c = Comment::sole();
        $this->assertFalse($c->visible_cliente);
        $this->assertFalse(Document::sole()->visible_cliente);
        // En un reenvío lo reenviado es el contenido: no se recorta.
        $this->assertStringContainsString('¿Nos envían el acta?', $c->body);

        $abogada = tap(User::factory()->create())->assignRole(
            \Spatie\Permission\Models\Role::findOrCreate('director', 'web')
        );
        \Spatie\Permission\Models\Permission::findOrCreate('documents.share_with_client', 'web');
        $abogada->givePermissionTo('documents.share_with_client');
        $this->actingAs($abogada)->patch(route('admin.comments.visibility', $c), ['visible_cliente' => true]);
        $this->assertTrue(Document::sole()->fresh()->visible_cliente);
    }

    public function test_el_correo_del_cliente_sigue_como_antes(): void
    {
        app(EmailRouter::class)->route($this->correo([
            'from' => 'Gerencia <gerencia@empresademo.co>', 'to' => 'leidy@proteccionlaboral.co', 'subject' => 'Consulta',
        ]));

        $c = Comment::sole();
        $this->assertStringStartsWith('[Correo entrante]', $c->body);
        $this->assertFalse($c->visible_cliente);
        $this->assertCount(2, Document::all());
        $this->assertNull(Document::first()->comment_id);
    }

    public function test_reprocesar_no_duplica(): void
    {
        $e = $this->correo();
        app(EmailRouter::class)->route($e);
        app(EmailRouter::class)->assignToProcess($e->fresh(), $this->process);

        $this->assertSame(1, Comment::count());
        $this->assertSame(1, Document::count());
    }

    public function test_el_comando_convierte_los_ya_procesados_y_simula_por_defecto(): void
    {
        // Como entraban antes: nota interna + Word suelto y oculto.
        $e = $this->correo(['process_id' => null]);
        $e->forceFill(['process_id' => $this->process->id, 'status' => EmailIngestion::STATUS_PROCESSED])->save();
        $nota = $this->process->comments()->create(['user_id' => $this->leidy->id, 'email_ingestion_id' => $e->id, 'body' => '[Correo entrante] resumen', 'visible_cliente' => false]);
        Document::create(['email_ingestion_id' => $e->id, 'ruta' => "inbound/{$e->message_id}/Acta descargos.docx", 'process_id' => $this->process->id, 'client_id' => $this->process->client_id, 'nombre' => 'Acta descargos.docx', 'disco' => 'local', 'tipo' => 'soporte', 'visible_cliente' => false]);

        $this->artisan('portal:correos-del-despacho')->assertSuccessful();
        $this->assertSame('[Correo entrante] resumen', $nota->fresh()->body);

        $this->artisan('portal:correos-del-despacho --aplicar')->assertSuccessful();
        $nota->refresh();
        $this->assertTrue($nota->visible_cliente);
        $this->assertStringStartsWith('📧 Respuesta enviada a', $nota->body);
        $doc = Document::sole();
        $this->assertSame($nota->id, $doc->comment_id);
        $this->assertTrue($doc->visible_cliente);
        $this->assertSame('comunicacion', $doc->tipo);
    }

    public function test_sin_citas_respeta_el_texto_propio(): void
    {
        $this->assertSame("Hola\n\nGracias", CorreoDelDespacho::sinCitas("Hola\n\n\n\nGracias\n\nOn Mon, Oct 5 wrote:\n> x", 'RE: x'));
    }
}
