<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\EmailRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function router(): EmailRouter
    {
        return app(EmailRouter::class);
    }

    protected function makeServiceType(string $nombre = 'Proceso Ordinario Laboral'): ServiceType
    {
        return ServiceType::create([
            'nombre' => $nombre,
            'slug' => 'svc-'.uniqid(),
            'descripcion' => 'Servicio de prueba',
            'modalidad' => 'judicial',
            'es_activo' => true,
        ]);
    }

    protected function makeProcess(string $codigo): Process
    {
        $this->makeServiceType(); // asegura que ProcessFactory encuentre un service_type_id
        $user = User::factory()->create(['is_active' => true]);

        return Process::factory()->create([
            'codigo' => $codigo,
            'abogado_lider_id' => $user->id,
        ]);
    }

    protected function makeIngestion(array $classification, array $overrides = []): EmailIngestion
    {
        return EmailIngestion::create(array_merge([
            'message_id' => 'msg-'.uniqid(),
            'from' => 'remitente@ejemplo.com',
            'to' => 'bandeja@proteccionlaboral.co',
            'subject' => 'Asunto de prueba',
            'body_text' => 'Cuerpo del correo.',
            'received_at' => now(),
            'status' => EmailIngestion::STATUS_PENDING,
            'ai_classification' => $classification,
            'raw_payload' => ['attachments' => []],
        ], $overrides));
    }

    // === nuevo_caso ===

    public function test_nuevo_caso_with_match_and_high_confidence_creates_process(): void
    {
        User::factory()->create(['is_active' => true]); // autor para el comentario
        $client = Client::factory()->create(['razon_social' => 'Acme Corp SAS']);
        $service = $this->makeServiceType('Proceso Ordinario Laboral');

        $ing = $this->makeIngestion([
            'action' => 'nuevo_caso',
            'confidence' => 0.9,
            'client_name' => 'Acme Corp',
            'service_type' => 'Proceso Ordinario',
            'summary' => 'Despido sin justa causa',
        ]);

        $status = $this->router()->route($ing);

        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('processes', [
            'client_id' => $client->id,
            'service_type_id' => $service->id,
        ]);
        $ing->refresh();
        $this->assertNotNull($ing->process_id);
    }

    public function test_nuevo_caso_low_confidence_goes_to_needs_review(): void
    {
        Client::factory()->create(['razon_social' => 'Acme Corp SAS']);
        $this->makeServiceType();

        $ing = $this->makeIngestion([
            'action' => 'nuevo_caso',
            'confidence' => 0.5,
            'client_name' => 'Acme Corp',
            'service_type' => 'Proceso Ordinario',
            'summary' => 'x',
        ]);

        $status = $this->router()->route($ing);

        $this->assertSame(EmailIngestion::STATUS_NEEDS_REVIEW, $status);
        $this->assertDatabaseCount('processes', 0);
    }

    public function test_nuevo_caso_without_client_match_goes_to_needs_review(): void
    {
        $this->makeServiceType();

        $ing = $this->makeIngestion([
            'action' => 'nuevo_caso',
            'confidence' => 0.95,
            'client_name' => 'Cliente Inexistente ZZZ',
            'service_type' => 'Proceso Ordinario',
            'summary' => 'x',
        ]);

        $status = $this->router()->route($ing);

        $this->assertSame(EmailIngestion::STATUS_NEEDS_REVIEW, $status);
        $this->assertDatabaseCount('processes', 0);
    }

    // === seguimiento_proceso ===

    public function test_seguimiento_with_matching_code_adds_comment(): void
    {
        $process = $this->makeProcess('PL-2026-0009');

        $ing = $this->makeIngestion([
            'action' => 'seguimiento_proceso',
            'confidence' => 0.85,
            'process_code' => 'PL-2026-0009',
            'summary' => 'El juzgado fija audiencia',
        ]);

        $status = $this->router()->route($ing);

        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $status);
        $this->assertSame(1, $process->comments()->count());
        $this->assertDatabaseHas('comments', [
            'email_ingestion_id' => $ing->id,
            'commentable_id' => $process->id,
            'commentable_type' => Process::class,
        ]);
        $ing->refresh();
        $this->assertSame($process->id, $ing->process_id);
    }

    public function test_seguimiento_without_match_goes_to_needs_review(): void
    {
        $ing = $this->makeIngestion([
            'action' => 'seguimiento_proceso',
            'confidence' => 0.85,
            'process_code' => 'PL-NO-EXISTE',
            'summary' => 'x',
        ]);

        $this->assertSame(EmailIngestion::STATUS_NEEDS_REVIEW, $this->router()->route($ing));
    }

    // === documento_recibido ===

    public function test_documento_recibido_registers_attachments_as_documents(): void
    {
        $process = $this->makeProcess('PL-DOC-001');

        $ing = $this->makeIngestion(
            [
                'action' => 'documento_recibido',
                'confidence' => 0.8,
                'process_code' => 'PL-DOC-001',
                'summary' => 'Llega auto de admisión',
            ],
            [
                'message_id' => 'msg-doc-1',
                'raw_payload' => ['attachments' => [
                    ['filename' => 'auto_admision.pdf', 'mime_type' => 'application/pdf', 'size' => 2048],
                ]],
            ]
        );

        $status = $this->router()->route($ing);

        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('documents', [
            'email_ingestion_id' => $ing->id,
            'process_id' => $process->id,
            'ruta' => 'inbound/msg-doc-1/auto_admision.pdf',
            'tipo' => 'soporte',
            'generado_por_ia' => false,
        ]);
    }

    // === comunicacion_cliente / spam / revisión ===

    public function test_comunicacion_cliente_with_match_adds_comment(): void
    {
        $process = $this->makeProcess('PL-COM-1');

        $ing = $this->makeIngestion([
            'action' => 'comunicacion_cliente',
            'confidence' => 0.8,
            'process_code' => 'PL-COM-1',
            'summary' => 'Cliente pregunta por avance',
        ]);

        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $this->router()->route($ing));
        $this->assertSame(1, $process->comments()->count());
    }

    public function test_spam_is_processed_without_creating_anything(): void
    {
        $ing = $this->makeIngestion([
            'action' => 'spam_o_irrelevante',
            'confidence' => 0.97,
            'summary' => 'Publicidad',
        ]);

        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $this->router()->route($ing));
        $this->assertDatabaseCount('processes', 0);
        $this->assertDatabaseCount('comments', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_requiere_revision_humana_goes_to_needs_review(): void
    {
        $ing = $this->makeIngestion([
            'action' => 'requiere_revision_humana',
            'confidence' => 0.4,
            'summary' => 'Correo confuso',
        ]);

        $this->assertSame(EmailIngestion::STATUS_NEEDS_REVIEW, $this->router()->route($ing));
    }

    // === idempotencia ===

    public function test_routing_is_idempotent(): void
    {
        $process = $this->makeProcess('PL-IDEM-1');

        $ing = $this->makeIngestion([
            'action' => 'seguimiento_proceso',
            'confidence' => 0.9,
            'process_code' => 'PL-IDEM-1',
            'summary' => 'audiencia',
        ]);

        $this->router()->route($ing);
        $this->router()->route($ing->fresh());

        $this->assertSame(1, $process->comments()->count());
    }
}
