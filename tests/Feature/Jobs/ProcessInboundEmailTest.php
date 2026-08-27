<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessInboundEmail;
use App\Models\AiGeneration;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\AiService;
use App\Services\EmailRouter;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProcessInboundEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');
    }

    protected function fakeClassification(array $payload): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => json_encode($payload)]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
            ], 200),
        ]);
    }

    protected function makeIngestion(): EmailIngestion
    {
        return EmailIngestion::create([
            'message_id' => 'inb-'.uniqid(),
            'from' => 'remitente@ejemplo.com',
            'to' => 'bandeja@proteccionlaboral.co',
            'subject' => 'Correo entrante',
            'body_text' => 'Contenido del correo.',
            'received_at' => now(),
            'raw_payload' => ['attachments' => []],
            'status' => EmailIngestion::STATUS_PENDING,
        ]);
    }

    protected function runJob(EmailIngestion $ing): void
    {
        $gmail = Mockery::mock(GmailService::class); // sin adjuntos no se invoca getAttachments
        // Tras procesar con éxito el pipeline marca el correo como leído (best-effort).
        $gmail->shouldReceive('markAsRead')->zeroOrMoreTimes()->andReturnNull();
        (new ProcessInboundEmail($ing->id))->handle($gmail, app(AiService::class), app(EmailRouter::class));
    }

    public function test_pipeline_classifies_and_marks_processed(): void
    {
        $this->fakeClassification([
            'action' => 'spam_o_irrelevante',
            'confidence' => 0.96,
            'summary' => 'Publicidad no solicitada',
            'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
        ]);

        $ing = $this->makeIngestion();
        $this->runJob($ing);

        $ing->refresh();
        $this->assertNotNull($ing->ai_classification);
        $this->assertSame('spam_o_irrelevante', $ing->ai_classification['action']);
        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $ing->status);
        $this->assertNotNull($ing->processed_at);
    }

    public function test_pipeline_is_idempotent(): void
    {
        ServiceType::create([
            'nombre' => 'Proceso Ordinario Laboral',
            'slug' => 'pol-'.uniqid(),
            'descripcion' => 'x',
            'modalidad' => 'judicial',
            'es_activo' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $process = Process::factory()->create([
            'codigo' => 'PL-INB-1',
            'abogado_lider_id' => $user->id,
        ]);

        $this->fakeClassification([
            'action' => 'seguimiento_proceso',
            'confidence' => 0.9,
            'process_code' => 'PL-INB-1',
            'summary' => 'El juzgado fija audiencia inicial',
            'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
        ]);

        $ing = $this->makeIngestion();

        $this->runJob($ing);
        $this->runJob($ing); // segunda ejecución no debe duplicar

        $this->assertSame(1, $process->comments()->count());
        $this->assertSame(EmailIngestion::STATUS_PROCESSED, $ing->fresh()->status);
    }

    public function test_classification_failure_marks_ingestion_failed(): void
    {
        // Respuesta sin el campo requerido 'action' → classifyEmail lanza RuntimeException.
        $this->fakeClassification([
            'confidence' => 0.9,
            'summary' => 'Sin action',
            'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
        ]);

        $ing = $this->makeIngestion();
        $this->runJob($ing);

        $ing->refresh();
        $this->assertSame(EmailIngestion::STATUS_FAILED, $ing->status);
        $this->assertNotNull($ing->error);
    }

    /**
     * Cada clasificación deja su fila en `ai_generations`.
     *
     * Era el camino de IA con más volumen de la app —un correo entrante, una
     * llamada— y el único que no anotaba nada. El panel de costos no mostraba
     * cero por error: mostraba cero porque nadie escribía. Novecientas
     * clasificaciones (~12 USD) se gastaron sin dejar rastro antes de esto.
     */
    public function test_la_clasificacion_queda_registrada_con_su_costo(): void
    {
        $this->fakeClassification([
            'action' => 'spam_o_irrelevante',
            'confidence' => 0.96,
            'summary' => 'Publicidad no solicitada',
            'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
        ]);

        $ing = $this->makeIngestion();
        $this->runJob($ing);

        $fila = AiGeneration::where('contexto_tipo', EmailIngestion::class)
            ->where('contexto_id', $ing->id)
            ->sole();

        $this->assertSame('ok', $fila->estado);
        $this->assertSame(20, $fila->tokens_in);
        $this->assertSame(10, $fila->tokens_out);
        $this->assertSame('claude-sonnet-4-6', $fila->modelo);
        $this->assertGreaterThan(0, (float) $fila->costo_usd, 'sin costo la fila no sirve para facturar');
        $this->assertStringContainsString('Correo entrante', $fila->prompt);
    }

    /**
     * Y si el modelo responde pero la respuesta no se puede leer, ese correo ya
     * está pagado: la fila se escribe igual, marcada como error.
     */
    public function test_una_respuesta_ilegible_tambien_se_registra(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'esto no es JSON']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
            ], 200),
        ]);

        $ing = $this->makeIngestion();
        $this->runJob($ing);

        $fila = AiGeneration::where('contexto_id', $ing->id)->sole();
        $this->assertSame('error', $fila->estado);
        $this->assertNotNull($fila->error_mensaje);

        // El correo queda marcado como fallido, como antes de este cambio.
        $this->assertSame(EmailIngestion::STATUS_FAILED, $ing->fresh()->status);
    }
}
