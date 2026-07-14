<?php

namespace Tests\Feature\Services;

use App\Models\Client;
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
}
