<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Services\ProcessSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El resumen del proceso mira el expediente, no solo su gestión.
 *
 * Antes solo recibía etapas, tareas y comentarios: te contaba cómo va la
 * GESTIÓN del caso y nada de qué trata el caso. En un proceso importado de
 * Drive —seis documentos y cero tareas, porque nadie ha trabajado en él dentro
 * de la app— el resumen salía prácticamente vacío. Y esos son justo los ciento
 * veintiocho procesos que entran del despacho.
 */
class ResumenDelProcesoConExpedienteTest extends TestCase
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

        ServiceType::create([
            'nombre' => 'Servicio de prueba',
            'slug' => 'servicio-de-prueba',
            'descripcion' => 'x',
            'modalidad' => 'permanente',
            'es_activo' => true,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Resumen generado.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
        ]);
    }

    /** El prompt que se envió de verdad. */
    private function promptEnviado(): string
    {
        $enviadas = Http::recorded();
        $this->assertNotEmpty($enviadas, 'no se llamó a la API');

        return $enviadas[0][0]->data()['messages'][0]['content'];
    }

    public function test_el_prompt_lleva_la_ficha_del_cliente_y_sus_documentos(): void
    {
        $client = Client::factory()->create([
            'resumen_documental' => "### Identidad\n- Molinería de arroz en Ibagué, NIT 890702902.",
            'resumen_documental_at' => now(),
        ]);
        $process = Process::factory()->create(['client_id' => $client->id]);

        Document::create([
            'client_id' => $client->id,
            'process_id' => $process->id,
            'nombre' => 'compraventa-acciones.pdf',
            'tipo' => 'contrato',
            'disco' => 'local',
            'ruta' => 'x/compraventa-acciones.pdf',
            'resumen_ia' => 'Compraventa de 300.000 acciones entre los socios fundadores.',
        ]);

        app(ProcessSummaryService::class)->generate($process);

        $prompt = $this->promptEnviado();

        $this->assertStringContainsString('Molinería de arroz en Ibagué', $prompt, 'falta la ficha del cliente');
        $this->assertStringContainsString('300.000 acciones', $prompt, 'falta el contenido de sus documentos');
    }

    /** Y se guarda, como antes. */
    public function test_el_resumen_se_persiste(): void
    {
        $process = Process::factory()->create(['client_id' => Client::factory()->create()->id]);

        app(ProcessSummaryService::class)->generate($process);

        $this->assertSame('Resumen generado.', $process->fresh()->resumen_ia);
        $this->assertNotNull($process->fresh()->resumen_ia_generado_at);
    }
}
