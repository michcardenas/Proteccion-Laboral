<?php

namespace Tests\Feature\Services;

use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\Document;
use App\Services\AiService;
use App\Services\ClientKnowledgeService;
use App\Services\DocumentSummarizer;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * El resumen por documento, que es lo que le permite a la ficha conocer TODO el
 * material del cliente en vez de los primeros que quepan.
 *
 * Medido con datos reales antes de construir esto: con texto crudo, la ficha de
 * MELENDEZ leía 12 de sus 96 documentos y la de ELIAS ACOSTA 12 de 147. No se
 * quedaba sin documentos, se quedaba sin caracteres — un documento legal
 * promedia ~11.000 y el presupuesto entero eran 90.000.
 *
 * La API se falsea siempre: ninguna prueba puede gastar dinero.
 */
class DocumentSummarizerTest extends TestCase
{
    use RefreshDatabase;

    private function ai(string $respuesta = 'Contrato de prestación de servicios entre X y Y.'): AiService
    {
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('generateDraft')->andReturn([
            'text' => $respuesta,
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 90],
            'latencia_ms' => 800,
        ]);
        $ai->shouldReceive('estimateCost')->andReturn(0.008);

        return $ai;
    }

    private function documento(array $overrides = []): Document
    {
        $client = Client::factory()->create();

        return Document::create(array_merge([
            'client_id' => $client->id,
            'nombre' => 'contrato.pdf',
            'ruta' => 'clients/contrato.pdf',
            'disco' => 'local',
            'tipo' => 'contrato',
            'texto_extraido' => str_repeat('cláusula primera del contrato. ', 200),
            'texto_extraido_at' => now(),
        ], $overrides));
    }

    public function test_resume_un_documento_y_lo_guarda(): void
    {
        $doc = $this->documento();

        $resumen = (new DocumentSummarizer($this->ai()))->summarize($doc);

        $this->assertSame('Contrato de prestación de servicios entre X y Y.', $resumen);
        $this->assertSame($resumen, $doc->fresh()->resumen_ia);
        $this->assertNotNull($doc->fresh()->resumen_ia_at);
    }

    /** El gasto queda registrado: es dinero real y tiene que poder auditarse. */
    public function test_deja_rastro_en_ai_generations(): void
    {
        $doc = $this->documento();

        (new DocumentSummarizer($this->ai()))->summarize($doc);

        $g = AiGeneration::where('contexto_tipo', Document::class)->firstOrFail();
        $this->assertSame('ok', $g->estado);
        $this->assertSame(1200, $g->tokens_in);
        $this->assertEqualsWithDelta(0.008, (float) $g->costo_usd, 0.0001);
    }

    /** Lo caro es la llamada: un documento ya resumido no se vuelve a mandar. */
    public function test_no_reprocesa_lo_que_ya_esta_resumido(): void
    {
        $doc = $this->documento();

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('generateDraft');

        $doc->forceFill(['resumen_ia' => 'ya resumido', 'resumen_ia_at' => now()])->saveQuietly();

        $this->assertSame('ya resumido', (new DocumentSummarizer($ai))->summarize($doc->fresh()));
    }

    /** Pero si el documento cambió después, el resumen quedó obsoleto. */
    public function test_rehace_el_resumen_si_el_documento_cambio(): void
    {
        $doc = $this->documento();
        $doc->forceFill([
            'resumen_ia' => 'viejo',
            'resumen_ia_at' => Carbon::parse('2026-01-01'),
            'updated_at' => Carbon::parse('2026-06-01'),
        ])->saveQuietly();

        $this->assertTrue((new DocumentSummarizer($this->ai()))->necesitaResumen($doc->fresh()));
    }

    public function test_un_documento_sin_texto_no_se_manda_a_la_api(): void
    {
        $doc = $this->documento(['texto_extraido' => '']);

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('generateDraft');

        $this->assertNull((new DocumentSummarizer($ai))->summarize($doc));
    }

    /** Un documento que falla no puede tumbar el backfill de los otros 147. */
    public function test_un_fallo_no_lanza_y_queda_registrado(): void
    {
        $doc = $this->documento();

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('generateDraft')->andThrow(new \RuntimeException('529 overloaded'));

        $this->assertNull((new DocumentSummarizer($ai))->summarize($doc));
        $this->assertSame('error', AiGeneration::where('contexto_tipo', Document::class)->firstOrFail()->estado);
    }

    /** El techo es lo que sostiene la promesa de que todo quepa en la ficha. */
    public function test_el_resumen_se_recorta_al_maximo(): void
    {
        $doc = $this->documento();
        $largo = str_repeat('a', DocumentSummarizer::MAX_RESUMEN + 500);

        (new DocumentSummarizer($this->ai($largo)))->summarize($doc);

        $this->assertLessThanOrEqual(
            DocumentSummarizer::MAX_RESUMEN,
            mb_strlen($doc->fresh()->resumen_ia)
        );
    }

    /**
     * La razón de ser de todo esto: con resúmenes entran los documentos que con
     * texto crudo no cabían.
     */
    public function test_la_ficha_prefiere_el_resumen_y_asi_caben_todos(): void
    {
        $client = Client::factory()->create();

        // Documentos largos como los reales: con texto crudo, el presupuesto
        // se agota mucho antes de llegar al último.
        $largos = (int) ceil(ClientKnowledgeService::MAX_TEXTO_TOTAL / ClientKnowledgeService::MAX_TEXTO_DOC) + 15;

        for ($i = 0; $i < $largos; $i++) {
            Document::create([
                'client_id' => $client->id,
                'nombre' => "documento-{$i}.pdf",
                'ruta' => "clients/doc{$i}.pdf",
                'disco' => 'local',
                'texto_extraido' => str_repeat('x', 12000),
                'texto_extraido_at' => now(),
                'resumen_ia' => "Resumen corto del documento {$i}.",
                'resumen_ia_at' => now(),
            ]);
        }

        $svc = new ClientKnowledgeService(app(DocumentTextExtractor::class), $this->ai());
        [$texto, $usados] = $svc->gatherDocumentsText($client);

        $this->assertSame($largos, $usados, 'con resúmenes entran todos');
        $this->assertStringContainsString('[resumen]', $texto, 'se marca cuál viene resumido');
        $this->assertLessThan(ClientKnowledgeService::MAX_TEXTO_TOTAL, mb_strlen($texto));
    }
}
