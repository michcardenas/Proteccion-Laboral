<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\EmailRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        // El alta de un caso nuevo dispara la generación automática del resumen IA;
        // fakeamos la respuesta de Anthropic para que el test sea determinista.
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Situación. Resumen de prueba.']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
        ]);

        User::factory()->create(['is_active' => true]); // autor para el comentario
        $client = Client::factory()->create(['razon_social' => 'Acme Corp SAS']);
        $service = $this->makeServiceType('Proceso Ordinario Laboral');

        $ing = $this->makeIngestion([
            'action' => 'nuevo_caso',
            'confidence' => 0.9,
            'client_name' => 'Acme Corp',
            'service_type' => 'Proceso Ordinario',
            'title' => 'Despido injustificado - Juan Pérez',
            'summary' => 'Cliente reporta despido sin justa causa de un trabajador, solicita representación.',
        ]);

        $status = $this->router()->route($ing);

        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('processes', [
            'client_id' => $client->id,
            'service_type_id' => $service->id,
            // El título corto viene del campo `title` de la IA, no del summary largo.
            'titulo' => 'Despido injustificado - Juan Pérez',
        ]);
        $ing->refresh();
        $this->assertNotNull($ing->process_id);

        // El resumen ejecutivo quedó persistido automáticamente al crear el caso.
        $this->assertSame('Situación. Resumen de prueba.', $ing->process->resumen_ia);
        $this->assertNotNull($ing->process->resumen_ia_generado_at);
    }

    public function test_nuevo_caso_without_title_falls_back_to_subject(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Resumen.']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['razon_social' => 'Acme Corp SAS']);
        $this->makeServiceType('Proceso Ordinario Laboral');

        // Sin `title`: el título del proceso debe caer al asunto del correo.
        $ing = $this->makeIngestion(
            [
                'action' => 'nuevo_caso',
                'confidence' => 0.9,
                'client_name' => 'Acme Corp',
                'service_type' => 'Proceso Ordinario',
                'summary' => 'Resumen largo del correo entrante.',
            ],
            ['subject' => 'Caso laboral urgente'],
        );

        $this->router()->route($ing);

        $this->assertDatabaseHas('processes', [
            'client_id' => $client->id,
            'titulo' => 'Caso laboral urgente',
        ]);
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

    // === asignación manual (bandeja de revisión) ===

    public function test_assign_to_process_links_and_attaches_once(): void
    {
        $process = $this->makeProcess('PL-MAN-1');

        $ing = $this->makeIngestion(
            [
                'action' => 'requiere_revision_humana',
                'confidence' => 0.4,
                'summary' => 'Correo dudoso asignado a mano',
            ],
            [
                'status' => EmailIngestion::STATUS_NEEDS_REVIEW,
                'message_id' => 'msg-man-1',
                'raw_payload' => ['attachments' => [
                    ['filename' => 'soporte.pdf', 'mime_type' => 'application/pdf', 'size' => 1024],
                ]],
            ]
        );

        // Idempotente: asignar dos veces no duplica comentario ni documento.
        $this->router()->assignToProcess($ing, $process);
        $this->router()->assignToProcess($ing->fresh(), $process);

        $ing->refresh();
        $this->assertSame($process->id, $ing->process_id);
        $this->assertSame(1, $process->comments()->count());
        $this->assertDatabaseHas('documents', [
            'email_ingestion_id' => $ing->id,
            'ruta' => 'inbound/msg-man-1/soporte.pdf',
        ]);
        $this->assertSame(1, Document::where('email_ingestion_id', $ing->id)->count());
    }
}
