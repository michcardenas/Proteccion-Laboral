<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Services\ProcessContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessContextBuilderFichaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Evita que ProcessFactory intente un ServiceTypeFactory inexistente.
        ServiceType::create([
            'nombre' => 'Servicio de prueba',
            'slug' => 'servicio-de-prueba',
            'descripcion' => 'x',
            'modalidad' => 'permanente',
            'es_activo' => true,
        ]);
    }

    public function test_inyecta_la_ficha_de_conocimiento_del_cliente(): void
    {
        $client = Client::factory()->create([
            'resumen_documental' => "### Identidad y perfil\n- Empresa del sector salud con NIT 900123456.",
            'resumen_documental_at' => now(),
        ]);
        $process = Process::factory()->create(['client_id' => $client->id]);

        $contexto = app(ProcessContextBuilder::class)->build($process);

        $this->assertStringContainsString('Ficha de conocimiento del cliente', $contexto);
        $this->assertStringContainsString('sector salud con NIT 900123456', $contexto);
    }

    public function test_sin_ficha_no_incluye_la_seccion(): void
    {
        $client = Client::factory()->create(['resumen_documental' => null]);
        $process = Process::factory()->create(['client_id' => $client->id]);

        $contexto = app(ProcessContextBuilder::class)->build($process);

        $this->assertStringNotContainsString('Ficha de conocimiento del cliente', $contexto);
    }

    /**
     * Cuando el documento tiene resumen, va el resumen y no el texto crudo.
     *
     * El texto se corta a tres mil caracteres, asi que de un contrato largo el
     * modelo solo veia el encabezado. El resumen cubre el documento entero en
     * una fraccion del espacio, y ya estaba generado y pagado al lado.
     */
    public function test_prefiere_el_resumen_del_documento_al_texto_crudo(): void
    {
        $client = Client::factory()->create();
        $process = Process::factory()->create(['client_id' => $client->id]);

        Document::create([
            'client_id' => $client->id,
            'process_id' => $process->id,
            'nombre' => 'contrato-marco.pdf',
            'tipo' => 'contrato',
            'disco' => 'local',
            'ruta' => 'x/contrato-marco.pdf',
            'texto_extraido' => 'CLAUSULA PRIMERA. '.str_repeat('relleno legal irrelevante. ', 400),
            'resumen_ia' => 'Contrato marco de prestacion de servicios con vigencia hasta 2027.',
        ]);

        $contexto = app(ProcessContextBuilder::class)->build($process);

        $this->assertStringContainsString('vigencia hasta 2027', $contexto);
        $this->assertStringNotContainsString('relleno legal irrelevante', $contexto);
        $this->assertStringContainsString('· resumen', $contexto, 'se marca de donde salio');
    }

    /** Sin resumen, se sigue usando el texto extraido como antes. */
    public function test_sin_resumen_cae_al_texto_extraido(): void
    {
        $client = Client::factory()->create();
        $process = Process::factory()->create(['client_id' => $client->id]);

        Document::create([
            'client_id' => $client->id,
            'process_id' => $process->id,
            'nombre' => 'acta.pdf',
            'tipo' => 'otro',
            'disco' => 'local',
            'ruta' => 'x/acta.pdf',
            'texto_extraido' => 'Acta de reunion del 4 de marzo con los delegados.',
            'texto_extraido_at' => now(),
            'resumen_ia' => null,
        ]);

        $contexto = app(ProcessContextBuilder::class)->build($process);

        $this->assertStringContainsString('Acta de reunion del 4 de marzo', $contexto);
    }
}
