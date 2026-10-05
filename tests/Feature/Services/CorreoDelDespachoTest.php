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

    public function test_reenvio_de_gmail_de_un_correo_propio_al_cliente_se_comparte_con_el_original(): void
    {
        app(EmailRouter::class)->route($this->correo([
            'subject' => 'Fwd: Citación a descargos',
            'to' => 'automatizacion@proteccionlaboral.co',
            'body_text' => "Para el portal.\n\n---------- Forwarded message ---------\nDe: Leidy Rodríguez <leidy@proteccionlaboral.co>\nDate: lun, 5 oct 2026 a las 10:12\nSubject: Citación a descargos\nTo: Gerencia <gerencia@empresademo.co>\nCc: Otra <otra@empresademo.co>\n\nBuenos días,\n\nAdjuntamos el acta firmada.\n\nCordialmente,\nLeidy\n\nEl lun, 5 oct 2026 a las 9:00, Gerencia <gerencia@empresademo.co> escribió:\n> ¿Nos envían el acta?",
        ]));

        $c = Comment::sole();
        $this->assertTrue($c->visible_cliente);
        $this->assertTrue(Document::sole()->visible_cliente);
        // Se enseña el correo original, no la nota del reenvío ni la cabecera.
        $this->assertStringStartsWith("📧 Respuesta enviada a gerencia@empresademo.co, otra@empresademo.co\nAsunto: Citación a descargos\n\nBuenos días,", $c->body);
        $this->assertStringContainsString('Adjuntamos el acta firmada.', $c->body);
        $this->assertStringNotContainsString('Para el portal', $c->body);
        $this->assertStringNotContainsString('Forwarded message', $c->body);
        $this->assertStringNotContainsString('¿Nos envían', $c->body);
    }

    public function test_reenvio_de_outlook_en_ingles_tambien_cuenta(): void
    {
        app(EmailRouter::class)->route($this->correo([
            'subject' => 'RV: Informe mensual',
            'to' => 'automatizacion@proteccionlaboral.co',
            'body_text' => "\n*From:* Leidy <leidy@proteccionlaboral.co>\n*Sent:* Monday, October 5, 2026 10:12 AM\n*To:* gerencia@empresademo.co\n*Subject:* Informe mensual\n\nAdjunto el informe.\n",
        ]));

        $c = Comment::sole();
        $this->assertTrue($c->visible_cliente);
        $this->assertStringContainsString("Asunto: Informe mensual\n\nAdjunto el informe.", $c->body);
    }

    public function test_reenviar_a_la_bandeja_un_correo_del_cliente_queda_interno(): void
    {
        app(EmailRouter::class)->route($this->correo([
            'subject' => 'Fwd: Consulta',
            'to' => 'automatizacion@proteccionlaboral.co',
            'body_text' => "---------- Mensaje reenviado ---------\nDe: Gerencia <gerencia@empresademo.co>\nFecha: lun, 5 oct 2026\nAsunto: Consulta\nPara: <leidy@proteccionlaboral.co>\n\n¿Cómo va el caso?",
        ]));

        // Es un correo del cliente reenviado: nota interna y adjunto como soporte, como siempre.
        $c = Comment::sole();
        $this->assertFalse($c->visible_cliente);
        $this->assertStringStartsWith('[Correo entrante]', $c->body);
        $this->assertNull(Document::first()->comment_id);
        $this->assertSame('soporte', Document::first()->tipo);
    }

    public function test_el_comando_revierte_reenvios_de_clientes_mal_convertidos_y_respeta_lo_compartido(): void
    {
        $reenvio = fn (string $id) => $this->correo([
            'message_id' => $id, 'subject' => 'Fwd: Consulta', 'to' => 'automatizacion@proteccionlaboral.co',
            'status' => EmailIngestion::STATUS_PROCESSED, 'process_id' => $this->process->id,
            'body_text' => "---------- Mensaje reenviado ---------\nDe: Gerencia <gerencia@empresademo.co>\nPara: <leidy@proteccionlaboral.co>\n\nAdjunto el contrato.",
        ]);
        // Como los dejó la conversión anterior: «📧 …» con el adjunto colgado.
        $mal = $reenvio('m1');
        $nota = $this->process->comments()->create(['user_id' => $this->leidy->id, 'email_ingestion_id' => $mal->id, 'body' => '📧 Respuesta enviada a automatizacion@proteccionlaboral.co', 'visible_cliente' => false]);
        Document::create(['email_ingestion_id' => $mal->id, 'comment_id' => $nota->id, 'ruta' => 'inbound/m1/Contrato.docx', 'process_id' => $this->process->id, 'client_id' => $this->process->client_id, 'nombre' => 'Contrato.docx', 'disco' => 'local', 'tipo' => 'comunicacion', 'visible_cliente' => false]);
        $compartido = $reenvio('m2');
        $ya = $this->process->comments()->create(['user_id' => $this->leidy->id, 'email_ingestion_id' => $compartido->id, 'body' => '📧 Respuesta enviada a x', 'visible_cliente' => true]);

        $this->artisan('portal:correos-del-despacho --aplicar')->assertSuccessful();

        $this->assertStringStartsWith('[Correo entrante]', $nota->fresh()->body);
        $doc = Document::where('email_ingestion_id', $mal->id)->sole();
        $this->assertNull($doc->comment_id);
        $this->assertSame('soporte', $doc->tipo);
        // Lo que alguien compartió no se toca.
        $this->assertSame('📧 Respuesta enviada a x', $ya->fresh()->body);
    }

    public function test_una_respuesta_que_cita_un_correo_propio_no_se_toma_por_reenvio(): void
    {
        app(EmailRouter::class)->route($this->correo([
            'subject' => 'RE: Informe',
            'to' => 'automatizacion@proteccionlaboral.co',
            'body_text' => "Recordatorio interno.\n\nDe: Leidy <leidy@proteccionlaboral.co>\nEnviado: lunes, 5 de octubre de 2026\nPara: gerencia@empresademo.co\nAsunto: Informe\n\nTexto viejo.",
        ]));

        $c = Comment::sole();
        $this->assertFalse($c->visible_cliente);
        $this->assertStringContainsString('Recordatorio interno.', $c->body);
        // La cita de Outlook se corta.
        $this->assertStringNotContainsString('Texto viejo', $c->body);
    }

    public function test_sin_citas_respeta_el_texto_propio(): void
    {
        $this->assertSame("Hola\n\nGracias", CorreoDelDespacho::sinCitas("Hola\n\n\n\nGracias\n\nOn Mon, Oct 5 wrote:\n> x", 'RE: x'));
    }
}
