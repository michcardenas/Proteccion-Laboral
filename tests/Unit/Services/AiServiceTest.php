<?php

namespace Tests\Unit\Services;

use App\Services\AiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    public function test_generate_draft_returns_text_and_usage_from_anthropic_response(): void
    {
        config()->set('anthropic.api_key', 'test-key');
        config()->set('anthropic.model', 'claude-sonnet-4-6');
        config()->set('anthropic.max_tokens', 4096);
        config()->set('anthropic.timeout', 60);
        config()->set('anthropic.base_url', 'https://api.anthropic.com/v1');
        config()->set('anthropic.anthropic_version', '2023-06-01');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test_01',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Borrador IA generado']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 25],
            ], 200),
        ]);

        $service = new AiService;
        $result = $service->generateDraft(
            prompt: 'Genera un borrador para el caso X',
            systemPrompt: 'Eres un asistente legal'
        );

        $this->assertSame('Borrador IA generado', $result['text']);
        $this->assertSame('claude-sonnet-4-6', $result['model']);
        $this->assertSame('end_turn', $result['stop_reason']);
        $this->assertSame(10, $result['usage']['input_tokens']);
        $this->assertSame(25, $result['usage']['output_tokens']);
        $this->assertIsString($result['request_hash']);
        $this->assertSame(64, strlen($result['request_hash'])); // sha256 hex
        $this->assertIsInt($result['latencia_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latencia_ms']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->method() === 'POST'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-sonnet-4-6'
                && $request['max_tokens'] === 4096
                && $request['system'] === 'Eres un asistente legal'
                && $request['messages'][0]['role'] === 'user'
                && $request['messages'][0]['content'] === 'Genera un borrador para el caso X';
        });
    }

    public function test_generate_draft_concatenates_multiple_text_blocks(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Parte 1. '],
                    ['type' => 'text', 'text' => 'Parte 2.'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 8],
            ], 200),
        ]);

        $result = (new AiService)->generateDraft('hola');

        $this->assertSame('Parte 1. Parte 2.', $result['text']);
    }

    public function test_generate_draft_omits_system_when_null(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        (new AiService)->generateDraft('hola');

        Http::assertSent(fn ($request) => ! isset($request->data()['system']));
    }

    // === estimateCost ===

    public function test_estimate_cost_sonnet_pricing(): void
    {
        $ai = new AiService(model: 'claude-sonnet-4-6');

        // 1M input @ $3 = 3.00
        $this->assertSame(3.00, $ai->estimateCost(1_000_000, 0));
        // 1M output @ $15 = 15.00
        $this->assertSame(15.00, $ai->estimateCost(0, 1_000_000));
        // 10k in (0.03) + 1k out (0.015) = 0.045
        $this->assertEqualsWithDelta(0.045, $ai->estimateCost(10_000, 1_000), 1e-9);
    }

    public function test_estimate_cost_haiku_pricing(): void
    {
        $ai = new AiService;

        // 1M+1M Haiku: 0.80 + 4.00 = 4.80
        $this->assertEqualsWithDelta(4.80, $ai->estimateCost(1_000_000, 1_000_000, 'claude-haiku-4-5'), 1e-9);
    }

    public function test_estimate_cost_unknown_model_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AiService)->estimateCost(10, 5, 'modelo-inexistente');
    }

    // === classifyEmail ===

    public function test_classify_email_parses_json_and_returns_structured_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'action' => 'seguimiento_proceso',
                    'confidence' => 0.92,
                    'process_code' => 'PL-2026-0012',
                    'client_name' => 'Juana Pérez',
                    'stage_hint' => 'audiencia_inicial',
                    'summary' => 'El juzgado fija audiencia inicial para el 15 de junio.',
                    'extracted_fields' => [
                        'dates' => ['2026-06-15'],
                        'amounts' => [],
                        'references' => ['Rad. 11001310500120260012300'],
                        'people' => ['Juez 5 Laboral'],
                    ],
                ])]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 240, 'output_tokens' => 120],
            ], 200),
        ]);

        $result = (new AiService)->classifyEmail([
            'from' => 'notificaciones@ramajudicial.gov.co',
            'subject' => 'Fijación de audiencia inicial',
            'body_text' => 'Cordial saludo. Se fija audiencia inicial...',
            'attachments' => ['auto_audiencia.pdf'],
        ], [
            'known_processes' => [
                ['code' => 'PL-2026-0012', 'client_name' => 'Juana Pérez'],
            ],
        ]);

        $this->assertSame('seguimiento_proceso', $result['action']);
        $this->assertSame(0.92, $result['confidence']);
        $this->assertSame('PL-2026-0012', $result['process_code']);
        $this->assertSame('Juana Pérez', $result['client_name']);
        $this->assertSame(240, $result['usage']['input_tokens']);
        $this->assertSame(120, $result['usage']['output_tokens']);
        $this->assertSame(['2026-06-15'], $result['extracted_fields']['dates']);
        $this->assertIsString($result['request_hash']);
        $this->assertIsInt($result['latencia_ms']);
    }

    public function test_classify_email_forces_review_when_confidence_below_threshold(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'action' => 'nuevo_caso',
                    'confidence' => 0.45,
                    'summary' => 'Email confuso, posible consulta nueva.',
                    'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
                ])]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 80, 'output_tokens' => 40],
            ], 200),
        ]);

        $result = (new AiService)->classifyEmail([
            'from' => 'desconocido@gmail.com',
            'subject' => '?',
            'body_text' => 'Hola, necesito ayuda con algo laboral.',
            'attachments' => [],
        ]);

        $this->assertSame('requiere_revision_humana', $result['action']);
        $this->assertSame(0.45, $result['confidence']);
    }

    public function test_classify_email_extracts_json_from_markdown_code_block(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => "```json\n".json_encode([
                    'action' => 'spam_o_irrelevante',
                    'confidence' => 0.95,
                    'summary' => 'Publicidad no solicitada.',
                    'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
                ])."\n```"]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 60, 'output_tokens' => 30],
            ], 200),
        ]);

        $result = (new AiService)->classifyEmail([
            'from' => 'promo@spam.com',
            'subject' => '¡Oferta!',
            'body_text' => 'Compre ahora...',
            'attachments' => [],
        ]);

        $this->assertSame('spam_o_irrelevante', $result['action']);
    }

    public function test_classify_email_throws_when_required_field_missing(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'confidence' => 0.9,
                    'summary' => 'No tiene action.',
                    'extracted_fields' => ['dates' => [], 'amounts' => [], 'references' => [], 'people' => []],
                ])]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'action'");

        (new AiService)->classifyEmail([
            'from' => 'a@b.com',
            'subject' => 'x',
            'body_text' => 'y',
            'attachments' => [],
        ]);
    }
}
