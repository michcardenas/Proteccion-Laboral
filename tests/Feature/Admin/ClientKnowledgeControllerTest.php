<?php

namespace Tests\Feature\Admin;

use App\Jobs\RegenerateClientKnowledge;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientKnowledgeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
    }

    protected function makeClientDoc(Client $client): Document
    {
        $ruta = "clients/client_{$client->id}/doc.txt";
        Storage::disk('local')->put($ruta, 'Contenido del contrato de prueba.');

        return Document::create([
            'client_id' => $client->id,
            'nombre' => 'doc.txt',
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => 'contrato',
            'generado_por_ia' => false,
        ]);
    }

    public function test_regenerar_requiere_permiso_ai_use(): void
    {
        $user = User::factory()->create(['is_active' => true]); // sin rol → sin ai.use
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.clients.knowledge.regenerate', $client))
            ->assertForbidden();
    }

    public function test_regenerar_construye_la_ficha_y_redirige(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => '### Identidad y perfil\n- ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $client = Client::factory()->create();
        $this->makeClientDoc($client);

        $this->actingAs($user)
            ->post(route('admin.clients.knowledge.regenerate', $client))
            ->assertRedirect();

        $this->assertNotNull($client->fresh()->resumen_documental);
    }

    public function test_subir_documento_despacha_regeneracion(): void
    {
        Bus::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $client = Client::factory()->create();

        $this->actingAs($user)->post(
            route('admin.clients.documents.store', $client),
            ['archivo' => UploadedFile::fake()->create('c.txt', 5, 'text/plain'), 'nombre' => 'c.txt', 'tipo' => 'contrato'],
        )->assertSessionHasNoErrors();

        Bus::assertDispatchedAfterResponse(RegenerateClientKnowledge::class,
            fn (RegenerateClientKnowledge $job) => $job->clientId === $client->id && $job->force === false);
    }

    public function test_borrar_documento_despacha_regeneracion_forzada(): void
    {
        Bus::fake();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('director');
        $client = Client::factory()->create();
        $doc = $this->makeClientDoc($client);

        $this->actingAs($user)->delete(
            route('admin.clients.documents.destroy', [$client, $doc]),
        );

        Bus::assertDispatchedAfterResponse(RegenerateClientKnowledge::class,
            fn (RegenerateClientKnowledge $job) => $job->clientId === $client->id && $job->force === true);
    }
}
