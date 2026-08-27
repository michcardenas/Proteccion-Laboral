<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\Process;
use App\Models\ServiceType;
use App\Services\ProcessService;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceTypeSeeder::class);
    }

    private function diagnosticoService(): ServiceType
    {
        return ServiceType::where('modalidad', 'diagnostico_implementacion')->firstOrFail();
    }

    private function createProcess(): Process
    {
        $client = Client::factory()->create();

        return app(ProcessService::class)->createFromTemplate([
            'client_id' => $client->id,
            'service_type_id' => $this->diagnosticoService()->id,
            'codigo' => 'PL-TEST-001',
            'titulo' => 'Proceso de prueba',
            'estado' => 'abierto',
            'fecha_apertura' => '2026-06-01',
        ]);
    }

    public function test_clona_las_etapas_de_la_plantilla(): void
    {
        $process = $this->createProcess()->load('stages');

        $this->assertCount(3, $process->stages);
        $this->assertSame(
            'Etapa 1 · Diagnóstico y actualización contractual',
            $process->stages->firstWhere('orden', 1)->nombre,
        );
        // fecha_limite = apertura (2026-06-01) + sla_dias (9) = 2026-06-10.
        $this->assertSame('2026-06-10', $process->stages->firstWhere('orden', 1)->fecha_limite->toDateString());
    }

    public function test_los_entregables_transversales_se_enganchan_a_la_primera_etapa(): void
    {
        $process = $this->createProcess()->load('stages.checklistResponses');

        $primera = $process->stages->firstWhere('orden', 1);
        // 6 entregables propios de la etapa 1 + 7 transversales del servicio.
        $this->assertCount(13, $primera->checklistResponses);

        // Las transversales NO se duplican en las demás etapas.
        $this->assertCount(1, $process->stages->firstWhere('orden', 2)->checklistResponses);
        $this->assertCount(6, $process->stages->firstWhere('orden', 3)->checklistResponses);

        $this->assertDatabaseHas('checklist_responses', [
            'descripcion' => 'Atención permanente de consultas jurídico-laborales',
        ]);
    }

    public function test_autogenera_las_tarjetas_kanban_de_la_rubrica(): void
    {
        $process = $this->createProcess()->load('tasks');

        $this->assertCount(5, $process->tasks);

        $rit = $process->tasks->firstWhere('titulo', 'Elaborar y socializar el reglamento interno de trabajo (RIT)');
        $this->assertNotNull($rit);
        $this->assertSame('alta', $rit->prioridad);
        $this->assertSame('pendiente', $rit->estado);
        // fecha_limite = apertura (2026-06-01) + sla_dias (14) = 2026-06-15.
        $this->assertSame('2026-06-15', $rit->fecha_limite->toDateString());
    }

    public function test_un_servicio_sin_tareas_no_genera_tarjetas(): void
    {
        $client = Client::factory()->create();
        $sinTareas = ServiceType::where('modalidad', 'capacitacion')->firstOrFail();

        $process = app(ProcessService::class)->createFromTemplate([
            'client_id' => $client->id,
            'service_type_id' => $sinTareas->id,
            'codigo' => 'PL-TEST-002',
            'titulo' => 'Capacitación de prueba',
            'estado' => 'abierto',
            'fecha_apertura' => '2026-06-01',
        ])->load('tasks');

        $this->assertCount(0, $process->tasks);
    }
}
