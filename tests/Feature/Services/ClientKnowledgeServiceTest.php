<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\Document;
use App\Services\ClientKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientKnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');
    }

    /** Crea un documento de nivel cliente (sin proceso) con contenido de texto real en disco. */
    protected function makeClientDoc(Client $client, string $nombre, string $contenido): Document
    {
        $ruta = "clients/client_{$client->id}/".$nombre;
        Storage::disk('local')->put($ruta, $contenido);

        return Document::create([
            'client_id' => $client->id,
            'nombre' => $nombre,
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => 'contrato',
            'mime' => 'text/plain',
            'tamano_bytes' => strlen($contenido),
            'generado_por_ia' => false,
        ]);
    }

    protected function fakeDigestResponse(string $texto): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $texto]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 800, 'output_tokens' => 200],
        ];
    }

    public function test_genera_la_ficha_a_partir_de_los_documentos_del_cliente(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                $this->fakeDigestResponse("### Identidad y perfil\n- Cliente de prueba."), 200),
        ]);

        $client = Client::factory()->create();
        $this->makeClientDoc($client, 'contrato.txt', 'Contrato de prestación de servicios con honorarios de $5.000.000.');

        $ok = app(ClientKnowledgeService::class)->build($client);

        $this->assertTrue($ok);
        $client->refresh();
        $this->assertStringContainsString('Identidad y perfil', $client->resumen_documental);
        $this->assertNotNull($client->resumen_documental_at);
        $this->assertDatabaseHas('ai_generations', [
            'contexto_tipo' => Client::class,
            'contexto_id' => $client->id,
            'estado' => 'ok',
        ]);
    }

    public function test_incluye_el_texto_del_documento_en_el_prompt_enviado(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fakeDigestResponse('ficha'), 200),
        ]);

        $client = Client::factory()->create();
        $this->makeClientDoc($client, 'diagnostico.txt', 'PALABRA_CLAVE_UNICA_XYZ en el diagnóstico.');

        app(ClientKnowledgeService::class)->build($client);

        Http::assertSent(function ($request) {
            $prompt = $request['messages'][0]['content'] ?? '';

            return str_contains($prompt, 'PALABRA_CLAVE_UNICA_XYZ');
        });
    }

    public function test_sin_documentos_deja_la_ficha_en_null(): void
    {
        Http::fake(); // no debería llamarse a la API

        $client = Client::factory()->create(['resumen_documental' => 'ficha vieja']);

        $ok = app(ClientKnowledgeService::class)->build($client);

        $this->assertTrue($ok);
        $client->refresh();
        $this->assertNull($client->resumen_documental);
        Http::assertNothingSent();
    }

    public function test_error_de_api_conserva_la_ficha_anterior_y_registra_error(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response('boom', 500),
        ]);

        $client = Client::factory()->create(['resumen_documental' => 'ficha previa valida']);
        $this->makeClientDoc($client, 'contrato.txt', 'Texto del contrato.');

        $ok = app(ClientKnowledgeService::class)->build($client);

        $this->assertFalse($ok);
        $client->refresh();
        $this->assertSame('ficha previa valida', $client->resumen_documental);
        $this->assertDatabaseHas('ai_generations', [
            'contexto_tipo' => Client::class,
            'contexto_id' => $client->id,
            'estado' => 'error',
        ]);
    }
}
