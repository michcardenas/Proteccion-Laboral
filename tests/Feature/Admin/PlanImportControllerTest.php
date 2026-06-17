<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Process;
use App\Models\ServiceStageTemplate;
use App\Models\ServiceTaskTemplate;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlanImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');
    }

    protected function makeProcess(): Process
    {
        $serviceType = ServiceType::create([
            'nombre' => 'Diagnóstico de prueba',
            'slug' => 'diagnostico-de-prueba',
            'descripcion' => 'Servicio para tests',
            'modalidad' => 'diagnostico_implementacion',
            'es_activo' => true,
        ]);

        $client = Client::factory()->create();

        return Process::factory()->create([
            'client_id' => $client->id,
            'service_type_id' => $serviceType->id,
            'codigo' => 'PL-TEST-IMP',
            'titulo' => 'Proceso de prueba',
            'fecha_apertura' => '2026-06-01',
        ]);
    }

    /** Respuesta de Claude cuyo texto es el JSON de extracción. */
    protected function fakeExtraction(array $json): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 500, 'output_tokens' => 300],
        ];
    }

    protected function samplePayload(): array
    {
        return [
            'etapas' => [
                [
                    'nombre' => 'Etapa 1 · Diagnóstico',
                    'descripcion' => 'Revisión contractual',
                    'fecha_entrega' => '2026-06-10',
                    'entregables' => ['Actualizar contratos', 'Revisar carpetas'],
                ],
                [
                    'nombre' => 'Etapa 2 · Manuales',
                    'descripcion' => null,
                    'fecha_entrega' => '2026-06-17',
                    'entregables' => ['Manuales de funciones'],
                ],
            ],
            'transversales' => ['Atención de consultas', 'Acompañamiento disciplinario'],
            'tareas' => [
                ['titulo' => 'Elaborar RIT', 'descripcion' => null, 'prioridad' => 'alta', 'fecha_limite' => '2026-06-15'],
                ['titulo' => 'Revisar expedientes', 'descripcion' => null, 'prioridad' => 'media', 'fecha_limite' => null],
            ],
        ];
    }

    public function test_analyze_interpreta_el_documento_y_devuelve_estructura(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeExtraction([
                'tipo_documento' => 'plan_trabajo',
                'resumen' => 'Plan de diagnóstico e implementación.',
                'etapas' => $this->samplePayload()['etapas'],
                'transversales' => $this->samplePayload()['transversales'],
                'tareas' => $this->samplePayload()['tareas'],
            ]), 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();

        $file = UploadedFile::fake()->createWithContent('plan.txt', 'Texto del plan de trabajo con etapas.');

        $response = $this->actingAs($user)->post(
            route('admin.processes.plan.analyze', $process),
            ['archivo' => $file],
        );

        $response->assertStatus(200)
            ->assertJson([
                'tipo_documento' => 'plan_trabajo',
                'resumen' => 'Plan de diagnóstico e implementación.',
            ])
            ->assertJsonCount(2, 'etapas')
            ->assertJsonCount(2, 'transversales')
            ->assertJsonCount(2, 'tareas');

        $this->assertDatabaseCount('ai_generations', 1);
        $this->assertDatabaseHas('ai_generations', ['estado' => 'ok']);
    }

    public function test_analyze_requiere_permiso_ai_use(): void
    {
        $user = User::factory()->create(['is_active' => true]); // sin rol → sin ai.use
        $process = $this->makeProcess();
        $file = UploadedFile::fake()->createWithContent('plan.txt', 'x');

        $this->actingAs($user)
            ->post(route('admin.processes.plan.analyze', $process), ['archivo' => $file])
            ->assertForbidden();
    }

    public function test_apply_crea_etapas_entregables_y_tareas(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // tiene processes.update
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.plan.apply', $process),
            $this->samplePayload(),
        );

        $response->assertStatus(201)
            ->assertJson(['etapas_creadas' => 2, 'tareas_creadas' => 2, 'plantilla_actualizada' => false]);

        $this->assertDatabaseCount('process_stages', 2);
        // Etapa 1: 2 entregables + 2 transversales = 4; Etapa 2: 1. Total 5.
        $this->assertDatabaseCount('checklist_responses', 5);
        $this->assertDatabaseCount('tasks', 2);

        $primera = $process->stages()->where('orden', 1)->first();
        $this->assertSame('2026-06-10', $primera->fecha_limite->toDateString());
        $this->assertSame(4, $primera->checklistResponses()->count());

        $rit = $process->tasks()->where('titulo', 'Elaborar RIT')->first();
        $this->assertSame('alta', $rit->prioridad);
        $this->assertSame('2026-06-15', $rit->fecha_limite->toDateString());
    }

    public function test_apply_reemplaza_el_plan_existente(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();

        // Etapa previa que debe desaparecer al reemplazar.
        $process->stages()->create(['orden' => 1, 'nombre' => 'Etapa vieja', 'estado' => 'pendiente']);

        $this->actingAs($user)->postJson(
            route('admin.processes.plan.apply', $process),
            array_merge($this->samplePayload(), ['reemplazar_plan' => true]),
        )->assertStatus(201);

        $this->assertDatabaseMissing('process_stages', ['nombre' => 'Etapa vieja']);
        $this->assertDatabaseCount('process_stages', 2);
    }

    public function test_apply_guarda_como_plantilla_del_servicio(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();

        $this->actingAs($user)->postJson(
            route('admin.processes.plan.apply', $process),
            array_merge($this->samplePayload(), ['guardar_plantilla' => true]),
        )->assertStatus(201)->assertJson(['plantilla_actualizada' => true]);

        $serviceId = $process->service_type_id;
        $this->assertSame(2, ServiceStageTemplate::where('service_type_id', $serviceId)->count());
        $this->assertSame(2, ServiceTaskTemplate::where('service_type_id', $serviceId)->count());

        // fecha_entrega 2026-06-10 sobre apertura 2026-06-01 → sla 9 días.
        $etapa1 = ServiceStageTemplate::where('service_type_id', $serviceId)->where('orden', 1)->first();
        $this->assertSame(9, $etapa1->sla_dias);
    }
}
