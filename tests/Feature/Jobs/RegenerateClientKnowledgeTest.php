<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RegenerateClientKnowledge;
use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Services\AiService;
use App\Services\ClientKnowledgeService;
use App\Services\DocumentSummarizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * El job que arma la ficha resume ANTES los documentos que no tengan resumen.
 *
 * Sin ese paso la cobertura se degradaba sola y en silencio: `DocumentSummarizer`
 * existía pero solo se alcanzaba desde el comando manual, así que cada documento
 * nuevo entraba a la ficha con su texto crudo —~11.000 caracteres frente a los
 * 900 de un resumen— y se comía el presupuesto que hace que quepan todos. La
 * ficha se seguía generando igual; solo cubría menos cada vez.
 */
class RegenerateClientKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function documento(Client $client, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'client_id' => $client->id,
            'nombre' => 'contrato-'.uniqid().'.pdf',
            'ruta' => 'clients/contrato.pdf',
            'disco' => 'local',
            'texto_extraido' => str_repeat('texto del contrato. ', 100),
            'texto_extraido_at' => now(),
        ], $overrides));
    }

    /** @param  Mockery\MockInterface|DocumentSummarizer  $summarizer */
    private function correr(Client $client, $summarizer, bool $force = true): void
    {
        $ficha = Mockery::mock(ClientKnowledgeService::class);
        $ficha->shouldReceive('build')->andReturn(true);

        (new RegenerateClientKnowledge($client->id, $force))->handle($ficha, $summarizer);
    }

    public function test_resume_los_documentos_pendientes_antes_de_armar_la_ficha(): void
    {
        $client = Client::factory()->create();
        $this->documento($client);
        $this->documento($client);

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldReceive('summarize')->twice();

        $this->correr($client, $summarizer);
    }

    /** Lo ya resumido no se vuelve a pagar. */
    public function test_no_reprocesa_los_que_ya_tienen_resumen(): void
    {
        $client = Client::factory()->create();
        $this->documento($client, ['resumen_ia' => 'ya está', 'resumen_ia_at' => now()]);
        $this->documento($client);

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldReceive('summarize')->once();

        $this->correr($client, $summarizer);
    }

    /** Los documentos de otro cliente no son asunto de este job. */
    public function test_no_toca_documentos_de_otro_cliente(): void
    {
        $client = Client::factory()->create();
        $this->documento($client);
        $this->documento(Client::factory()->create());

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldReceive('summarize')->once();

        $this->correr($client, $summarizer);
    }

    /** La ficha solo mira los documentos de nivel cliente; resumir los de proceso sería gasto inútil. */
    public function test_ignora_los_documentos_atados_a_un_proceso(): void
    {
        $client = Client::factory()->create();

        // ServiceType se crea a mano: no existe un factory para él.
        $servicio = ServiceType::create([
            'nombre' => 'Proceso Ordinario Laboral',
            'slug' => 'proceso-ordinario-laboral',
            'modalidad' => 'judicial',
            'es_activo' => true,
        ]);
        $proceso = Process::factory()->create([
            'client_id' => $client->id,
            'service_type_id' => $servicio->id,
            'codigo' => 'PL-TEST-RESUMEN',
        ]);
        $this->documento($client, ['process_id' => $proceso->id]);

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldNotReceive('summarize');

        $this->correr($client, $summarizer);
    }

    public function test_un_documento_sin_texto_no_se_manda_a_resumir(): void
    {
        $client = Client::factory()->create();
        $this->documento($client, ['texto_extraido' => '']);

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldNotReceive('summarize');

        $this->correr($client, $summarizer);
    }

    /**
     * Un volcado de cien documentos no puede convertir una subida en cien
     * llamadas dentro del mismo job.
     */
    public function test_respeta_el_techo_por_corrida(): void
    {
        $client = Client::factory()->create();
        for ($i = 0; $i < RegenerateClientKnowledge::MAX_RESUMENES_POR_CORRIDA + 8; $i++) {
            $this->documento($client);
        }

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldReceive('summarize')->times(RegenerateClientKnowledge::MAX_RESUMENES_POR_CORRIDA);

        $this->correr($client, $summarizer);
    }

    /**
     * Lo importante: resumir es una mejora, no un requisito. Si falla, la ficha
     * se genera igual — con texto crudo, que es como funcionaba antes.
     */
    public function test_si_falla_el_resumen_la_ficha_se_genera_igual(): void
    {
        $client = Client::factory()->create();
        $this->documento($client);

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldReceive('summarize')->andReturnNull();

        $ficha = Mockery::mock(ClientKnowledgeService::class);
        $ficha->shouldReceive('build')->once()->andReturn(true);

        (new RegenerateClientKnowledge($client->id, true))->handle($ficha, $summarizer);
    }

    /** El debounce sigue vivo: sin `force` y con la ficha al día, no se gasta nada. */
    public function test_con_la_ficha_al_dia_no_resume_ni_regenera(): void
    {
        $client = Client::factory()->create([
            'resumen_documental' => 'ficha vigente',
            'resumen_documental_at' => now()->addDay(),
        ]);
        $this->documento($client);

        $summarizer = Mockery::mock(DocumentSummarizer::class);
        $summarizer->shouldNotReceive('summarize');

        $ficha = Mockery::mock(ClientKnowledgeService::class);
        $ficha->shouldNotReceive('build');

        (new RegenerateClientKnowledge($client->id, false))->handle($ficha, $summarizer);
    }

    /** El servicio real se puede resolver del contenedor: el job es inyectable de verdad. */
    public function test_el_job_se_resuelve_con_las_dependencias_reales(): void
    {
        $this->assertInstanceOf(DocumentSummarizer::class, app(DocumentSummarizer::class));
        $this->assertInstanceOf(AiService::class, app(AiService::class));
    }
}
