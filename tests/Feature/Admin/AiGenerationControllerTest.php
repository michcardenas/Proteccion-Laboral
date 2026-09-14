<?php

namespace Tests\Feature\Admin;

use App\Jobs\GenerateAiDraft;
use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\AiService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

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
    protected function makeProcess(string $codigo = 'PL-TEST-001'): Process
    {
        // firstOrCreate y no create: el slug es unico, y hay tests que necesitan
        // dos procesos distintos dentro del mismo caso.
        $serviceType = ServiceType::firstOrCreate(
            ['slug' => 'proceso-ordinario-laboral'],
            [
                'nombre' => 'Proceso Ordinario Laboral',
                'descripcion' => 'Tipo de servicio para tests',
                'modalidad' => 'judicial',
                'es_activo' => true,
            ]
        );

        $client = Client::factory()->create();

        return Process::factory()->create([
            'client_id' => $client->id,
            'service_type_id' => $serviceType->id,
            'codigo' => $codigo,
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

    public function test_generate_enqueues_the_draft_and_returns_pending_row(): void
    {
        Queue::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // tiene ai.use
        $process = $this->makeProcess();

        $response = $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            [
                'template' => 'draft_demanda',
                'placeholders' => [
                    'facts' => 'El trabajador fue despedido sin justa causa.',
                    'requested_claims' => 'Indemnizacion + cesantias.',
                ],
            ]
        );

        // 202 y SIN borrador: el texto todavia no existe, se recoge sondeando.
        $response->assertStatus(202)
            ->assertJsonStructure(['id', 'estado'])
            ->assertJson(['estado' => 'pendiente'])
            ->assertJsonMissing(['borrador']);

        $this->assertDatabaseCount('ai_generations', 1);

        $generation = AiGeneration::first();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(Process::class, $generation->contexto_tipo);
        $this->assertSame($process->id, $generation->contexto_id);
        $this->assertSame('anthropic', $generation->proveedor);
        $this->assertSame('pendiente', $generation->estado);
        // El prompt ya esta persistido: el job no lo recibe por el payload.
        $this->assertStringContainsString($process->codigo, $generation->prompt);
        $this->assertNull($generation->respuesta);

        Queue::assertPushed(GenerateAiDraft::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_job_writes_the_draft_and_closes_the_row_as_ok(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeClaudeResponse('Borrador de prueba.'), 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $generation = $this->makePendingGeneration($user, $process);

        (new GenerateAiDraft($generation->id))->handle(app(AiService::class));

        $generation->refresh();
        $this->assertSame('ok', $generation->estado);
        $this->assertSame('Borrador de prueba.', $generation->respuesta);
        $this->assertSame('claude-sonnet-4-6', $generation->modelo);
        $this->assertSame(120, $generation->tokens_in);
        $this->assertSame(80, $generation->tokens_out);
        $this->assertNotNull($generation->latencia_ms);
        $this->assertSame(64, strlen($generation->request_hash));
        // Costo: (120/1M * $3) + (80/1M * $15) = 0.00036 + 0.0012 = 0.00156
        $this->assertEqualsWithDelta(0.00156, (float) $generation->costo_usd, 1e-6);
    }

    public function test_job_does_not_redo_a_row_that_is_no_longer_pending(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeClaudeResponse('No deberia llamarse.'), 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $generation = $this->makePendingGeneration($user, $process);
        $generation->update(['estado' => 'ok', 'respuesta' => 'Borrador ya escrito.']);

        (new GenerateAiDraft($generation->id))->handle(app(AiService::class));

        // Sin esta guarda, un reintento de la cola volveria a pagar la misma llamada.
        Http::assertNothingSent();
        $this->assertSame('Borrador ya escrito.', $generation->fresh()->respuesta);
    }

    public function test_failed_job_closes_the_row_as_error_so_the_screen_stops_polling(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $generation = $this->makePendingGeneration($user, $process);

        (new GenerateAiDraft($generation->id))->failed(new RuntimeException('Anthropic sobrecargado'));

        $generation->refresh();
        $this->assertSame('error', $generation->estado);
        $this->assertStringContainsString('Anthropic sobrecargado', $generation->error_mensaje);
    }

    /**
     * El recorrido completo tal y como lo vive la pantalla: encolar, dejar que el
     * worker lo procese, y recoger el borrador sondeando.
     *
     * Usa la cola `database` de verdad (no Queue::fake) a proposito: asi se ejercita
     * la serializacion del job, que es donde se rompen estas cosas al desplegar.
     */
    public function test_full_round_trip_enqueue_work_and_poll(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeClaudeResponse('Contestacion redactada.'), 200),
        ]);
        config()->set('queue.default', 'database');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno');
        $process = $this->makeProcess();

        $id = $this->actingAs($user)
            ->postJson(route('admin.processes.ai.generate', $process), ['template' => 'draft_respuesta'])
            ->assertStatus(202)
            ->json('id');

        $ruta = route('admin.processes.ai.show', ['process' => $process, 'generation' => $id]);

        // Mientras el worker no pasa, la pantalla sigue viendo `pendiente`.
        $this->assertDatabaseCount('jobs', 1);
        $this->actingAs($user)->getJson($ruta)->assertJson(['estado' => 'pendiente', 'borrador' => null]);

        Artisan::call('queue:work', ['--once' => true, '--no-interaction' => true]);

        $this->actingAs($user)->getJson($ruta)
            ->assertStatus(200)
            ->assertJson(['estado' => 'ok', 'borrador' => 'Contestacion redactada.']);

        $this->assertDatabaseCount('jobs', 0);
    }

    // ============================================================
    // show - GET /admin/processes/{process}/ai/generations/{generation}
    // ============================================================

    public function test_show_returns_the_state_of_a_generation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        $generation = $this->makePendingGeneration($user, $process);

        $this->actingAs($user)
            ->getJson(route('admin.processes.ai.show', ['process' => $process, 'generation' => $generation]))
            ->assertStatus(200)
            ->assertJson(['id' => $generation->id, 'estado' => 'pendiente', 'borrador' => null]);

        $generation->update(['estado' => 'ok', 'respuesta' => 'Ya esta listo.']);

        $this->actingAs($user)
            ->getJson(route('admin.processes.ai.show', ['process' => $process, 'generation' => $generation]))
            ->assertStatus(200)
            ->assertJson(['estado' => 'ok', 'borrador' => 'Ya esta listo.']);
    }

    public function test_show_does_not_leak_a_generation_from_another_process(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();
        $otro = $this->makeProcess('PL-TEST-002');

        $generation = $this->makePendingGeneration($user, $otro);

        $this->actingAs($user)
            ->getJson(route('admin.processes.ai.show', ['process' => $process, 'generation' => $generation]))
            ->assertStatus(404);
    }

    public function test_show_forbidden_without_ai_use(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cliente'); // sin ai.use
        $process = $this->makeProcess();
        $generation = $this->makePendingGeneration($user, $process);

        $this->actingAs($user)
            ->getJson(route('admin.processes.ai.show', ['process' => $process, 'generation' => $generation]))
            ->assertStatus(403);
    }

    /**
     * Fila recien encolada, tal y como la deja `store`.
     */
    protected function makePendingGeneration(User $user, Process $process): AiGeneration
    {
        return AiGeneration::create([
            'user_id' => $user->id,
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => 'claude-sonnet-4-6',
            'prompt' => 'Redacta un borrador para '.$process->codigo,
            'estado' => 'pendiente',
        ]);
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

        $generation = $this->makePendingGeneration($user, $process);

        // El job propaga para que la cola lo marque fallido; `failed()` cierra la fila.
        try {
            (new GenerateAiDraft($generation->id))->handle(app(AiService::class));
            $this->fail('Se esperaba que el job propagara el fallo de Anthropic.');
        } catch (Throwable $e) {
            (new GenerateAiDraft($generation->id))->failed($e);
        }

        $generation->refresh();
        $this->assertSame('error', $generation->estado);
        $this->assertNotNull($generation->error_mensaje);
    }

    public function test_placeholders_from_process_are_injected_into_prompt(): void
    {
        Queue::fake();

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

    public function test_no_placeholder_reaches_claude_with_its_braces(): void
    {
        Queue::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        foreach (['draft_demanda', 'draft_respuesta', 'draft_dictamen', 'draft_comunicacion_cliente'] as $template) {
            $this->actingAs($user)->postJson(
                route('admin.processes.ai.generate', $process),
                ['template' => $template]
            )->assertStatus(202);
        }

        foreach (AiGeneration::all() as $generation) {
            $this->assertDoesNotMatchRegularExpression('/\{\{[a-z_]+\}\}/', $generation->prompt);
            $this->assertStringContainsString('[FALTA', $generation->prompt);
        }
    }

    public function test_respuesta_receives_the_latest_email_as_the_communication_answered(): void
    {
        Queue::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $process = $this->makeProcess();

        foreach ([['viejo', now()->subDays(3), 'Correo antiguo que no toca.'], ['nuevo', now(), 'Accion Persuasiva No. 02 de Colpensiones.']] as [$id, $fecha, $cuerpo]) {
            EmailIngestion::create([
                'message_id' => 'msg-'.$id,
                'from' => 'Colpensiones <notificaciones@colpensiones.gov.co>',
                'to' => 'automatizacion@proteccionlaboral.co',
                'subject' => 'Asunto '.$id,
                'received_at' => $fecha,
                'raw_payload' => [],
                'body_text' => $cuerpo,
                'status' => EmailIngestion::STATUS_PROCESSED,
                'process_id' => $process->id,
            ]);
        }

        $this->actingAs($user)->postJson(
            route('admin.processes.ai.generate', $process),
            ['template' => 'draft_respuesta']
        )->assertStatus(202);

        $prompt = AiGeneration::first()->prompt;
        $this->assertStringContainsString('Accion Persuasiva No. 02 de Colpensiones.', $prompt);
        $this->assertStringContainsString('Asunto nuevo', $prompt);
        $this->assertStringNotContainsString('{{original_complaint}}', $prompt);
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
