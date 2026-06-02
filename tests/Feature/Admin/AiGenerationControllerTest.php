<?php

namespace Tests\Feature\Admin;

use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiGenerationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Config Anthropic determinístico para los tests
        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');

        // El componente Inertia Admin/AiUsage/Index.vue todavía no existe
        // (es trabajo frontend pendiente). Desactivamos la verificación de archivo
        // para que assertInertia valide solo la estructura del payload.
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    /**
     * Crea un proceso con cliente y service_type ad-hoc (sin depender de un factory inexistente).
     */
    protected function makeProcess(): Process
    {
        $serviceType = ServiceType::create([
            'nombre' => 'Proceso Ordinario Laboral',
            'slug' => 'proceso-ordinario-laboral',
            'descripcion' => 'Tipo de servicio para tests',
            'modalidad' => 'judicial',
            'es_activo' => true,
        ]);

        $client = Client::factory()->create();

        return Process::factory()->create([
            'client_id' => $client->id,
            'service_type_id' => $serviceType->id,
            'codigo' => 'PL-TEST-001',
            'titulo' => 'Proceso de prueba',
        ]);
    }

    /**
     * Respuesta tipo de la API Anthropic para mockear Http::fake().
     */
    protected function fakeClaudeResponse(string $text = 'Borrador generado por IA', int $in = 120, int $out = 80): array
    {
        return [
            'id' => 'msg_test_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    // ============================================================
    // store — POST /admin/processes/{process}/ai/generate
    // ============================================================

    public function test_user_with_ai_use_permission_can_generate_draft_and_persist_record(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeClaudeResponse('Borrador de prueba.'), 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // tiene ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            [
                'template' => 'draft_demanda',
                'placeholders' => [
                    'facts' => 'El trabajador fue despedido sin justa causa.',
                    'requested_claims' => 'Indemnización + cesantías.',
                ],
            ]
        );

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'borrador', 'modelo', 'tokens' => ['input_tokens', 'output_tokens'], 'costo_usd', 'latencia_ms'])
            ->assertJson([
                'borrador' => 'Borrador de prueba.',
                'modelo' => 'claude-sonnet-4-6',
            ]);

        $this->assertDatabaseCount('ai_generations', 1);

        $generation = AiGeneration::first();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(Process::class, $generation->contexto_tipo);
        $this->assertSame($process->id, $generation->contexto_id);
        $this->assertSame('anthropic', $generation->proveedor);
        $this->assertSame('claude-sonnet-4-6', $generation->modelo);
        $this->assertSame(120, $generation->tokens_in);
        $this->assertSame(80, $generation->tokens_out);
        $this->assertSame('ok', $generation->estado);
        $this->assertNotNull($generation->request_hash);
        $this->assertSame(64, strlen($generation->request_hash));
        $this->assertNotNull($generation->latencia_ms);
        // Costo: (120/1M * $3) + (80/1M * $15) = 0.00036 + 0.0012 = 0.00156
        $this->assertEqualsWithDelta(0.00156, (float) $generation->costo_usd, 1e-6);
    }

    public function test_user_without_ai_use_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cliente'); // no tiene ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            ['template' => 'draft_demanda']
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('ai_generations', 0);
    }

    public function test_invalid_template_name_returns_validation_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            ['template' => 'plantilla_inexistente']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template']);
    }

    public function test_anthropic_failure_is_persisted_as_error_record(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 503),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            ['template' => 'draft_dictamen']
        );

        $response->assertStatus(502)
            ->assertJsonStructure(['error']);

        $this->assertDatabaseCount('ai_generations', 1);
        $generation = AiGeneration::first();
        $this->assertSame('error', $generation->estado);
        $this->assertNotNull($generation->error_mensaje);
    }

    public function test_placeholders_from_process_are_injected_into_prompt(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeClaudeResponse(), 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            ['template' => 'draft_demanda']
        );

        $generation = AiGeneration::first();

        // Verificar que en el prompt persistido aparecen los datos del proceso
        $this->assertStringContainsString($process->codigo, $generation->prompt);
        $this->assertStringContainsString($process->client->razon_social, $generation->prompt);
        $this->assertStringContainsString('Proceso Ordinario Laboral', $generation->prompt);
        // Y que los marcadores fueron reemplazados (no quedan {{...}} en el prompt)
        $this->assertStringNotContainsString('{{process_code}}', $generation->prompt);
        $this->assertStringNotContainsString('{{client_name}}', $generation->prompt);
    }

    // ============================================================
    // index — GET /admin/ai/usage
    // ============================================================

    public function test_user_with_usage_view_permission_sees_monthly_generations(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('coordinador'); // tiene ai.usage_view

        $process = $this->makeProcess();

        // 3 generations este mes
        AiGeneration::create([
            'user_id' => $user->id,
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => 'claude-sonnet-4-6',
            'prompt' => 'p1',
            'respuesta' => 'r1',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'costo_usd' => 0.001,
            'latencia_ms' => 250,
            'estado' => 'ok',
        ]);
        AiGeneration::create([
            'user_id' => $user->id,
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => 'claude-sonnet-4-6',
            'prompt' => 'p2',
            'respuesta' => 'r2',
            'tokens_in' => 200,
            'tokens_out' => 100,
            'costo_usd' => 0.002,
            'latencia_ms' => 300,
            'estado' => 'ok',
        ]);
        AiGeneration::create([
            'user_id' => $user->id,
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => 'claude-haiku-4-5',
            'prompt' => 'p3',
            'tokens_in' => 50,
            'tokens_out' => 20,
            'costo_usd' => 0.0001,
            'latencia_ms' => 120,
            'estado' => 'error',
            'error_mensaje' => 'rate_limit',
        ]);

        $response = $this->actingAs($user)->get(route('admin.ai.usage'));

        $response->assertStatus(200);
        // Verificar payload de Inertia
        $response->assertInertia(
            fn ($page) => $page
                ->component('Admin/AiUsage/Index')
                ->has('generations.data', 3)
                ->where('stats.total', 3)
                ->where('stats.tokens_in_total', 350)
                ->where('stats.tokens_out_total', 170)
                ->where('stats.costo_total', fn ($v) => abs($v - 0.0031) < 1e-6)
        );
    }

    public function test_user_without_usage_view_permission_is_forbidden_from_index(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // solo tiene ai.use, NO ai.usage_view

        $response = $this->actingAs($user)->get(route('admin.ai.usage'));

        $response->assertStatus(403);
    }

    // ============================================================
    // storeAsDocument — POST /admin/processes/{process}/ai/document
    // ============================================================

    public function test_user_with_ai_use_can_save_draft_as_document(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.document', $process),
            [
                'contenido' => "Primera línea del borrador.\nSegunda línea.",
                'nombre' => 'Demanda laboral (borrador IA)',
                'tipo' => 'escrito',
                'visible_cliente' => true,
            ]
        );

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'nombre', 'tipo', 'generado_por_ia', 'visible_cliente', 'created_at']);

        $this->assertDatabaseCount('documents', 1);

        $doc = Document::first();
        $this->assertSame($process->id, $doc->process_id);
        $this->assertSame($process->client_id, $doc->client_id);
        $this->assertSame('escrito', $doc->tipo);
        $this->assertSame('text/html', $doc->mime);
        $this->assertSame($user->id, $doc->subido_por);
        $this->assertTrue((bool) $doc->generado_por_ia);
        $this->assertTrue((bool) $doc->visible_cliente);

        Storage::disk('local')->assertExists($doc->ruta);
        $this->assertStringContainsString('Primera línea del borrador.', Storage::disk('local')->get($doc->ruta));
    }

    public function test_save_as_document_requires_contenido(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.document', $process),
            ['nombre' => 'Sin contenido']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contenido']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_save_as_document_forbidden_without_ai_use(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cliente'); // sin ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.document', $process),
            ['contenido' => 'algo']
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('documents', 0);
    }

    // ============================================================
    // storeAsComment — POST /admin/processes/{process}/ai/comment
    // ============================================================

    public function test_user_with_ai_use_can_save_draft_as_comment(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.comment', $process),
            ['body' => 'Resumen IA del proceso.', 'visible_cliente' => false]
        );

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'body', 'visible_cliente', 'user', 'created_at']);

        $this->assertDatabaseHas('comments', [
            'commentable_type' => Process::class,
            'commentable_id' => $process->id,
            'user_id' => $user->id,
            'body' => 'Resumen IA del proceso.',
        ]);

        $this->assertSame(1, $process->comments()->count());
    }

    public function test_save_as_comment_requires_body(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.comment', $process),
            []
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_save_as_comment_forbidden_without_ai_use(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cliente'); // sin ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.comment', $process),
            ['body' => 'algo']
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('comments', 0);
    }

    // ============================================================
    // index — filtros por mes / usuario / modelo (D1-W5)
    // ============================================================

    public function test_usage_index_filters_by_model_user_and_month(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('coordinador'); // ai.usage_view
        $otro = User::factory()->create(['is_active' => true]);
        $process = $this->makeProcess();

        // Mes actual: 2 sonnet (viewer) + 1 haiku (otro)
        $this->makeGeneration($viewer, $process, 'claude-sonnet-4-6');
        $this->makeGeneration($viewer, $process, 'claude-sonnet-4-6');
        $this->makeGeneration($otro, $process, 'claude-haiku-4-5');

        // Mes anterior: 1 sonnet (viewer)
        $previa = $this->makeGeneration($viewer, $process, 'claude-sonnet-4-6');
        $previa->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()])->save();

        $mesActual = now()->format('Y-m');
        $mesAnterior = now()->subMonthNoOverflow()->format('Y-m');

        // Sin filtros → mes actual → 3 registros, ambas opciones de filtro presentes
        $this->actingAs($viewer)->get(route('admin.ai.usage'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AiUsage/Index')
                ->has('generations.data', 3)
                ->where('stats.total', 3)
                ->where('filters.mes', $mesActual)
                ->has('filterOptions.modelos', 2)
                ->has('filterOptions.meses', 2)
            );

        // Filtro por modelo
        $this->actingAs($viewer)->get(route('admin.ai.usage', ['modelo' => 'claude-haiku-4-5']))
            ->assertInertia(fn ($page) => $page
                ->has('generations.data', 1)
                ->where('stats.total', 1)
                ->where('filters.modelo', 'claude-haiku-4-5')
            );

        // Filtro por usuario
        $this->actingAs($viewer)->get(route('admin.ai.usage', ['user_id' => $viewer->id]))
            ->assertInertia(fn ($page) => $page
                ->has('generations.data', 2)
                ->where('stats.total', 2)
                ->where('filters.user_id', $viewer->id)
            );

        // Filtro por mes anterior
        $this->actingAs($viewer)->get(route('admin.ai.usage', ['mes' => $mesAnterior]))
            ->assertInertia(fn ($page) => $page
                ->has('generations.data', 1)
                ->where('stats.total', 1)
                ->where('filters.mes', $mesAnterior)
            );
    }

    /**
     * Crea una generación IA mínima para los tests de la página de uso.
     */
    protected function makeGeneration(User $user, Process $process, string $modelo): AiGeneration
    {
        return AiGeneration::create([
            'user_id' => $user->id,
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => $modelo,
            'prompt' => 'p',
            'respuesta' => 'r',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'costo_usd' => 0.001,
            'latencia_ms' => 200,
            'estado' => 'ok',
        ]);
    }
}
