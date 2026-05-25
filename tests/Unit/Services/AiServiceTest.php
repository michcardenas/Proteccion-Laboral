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

        $service = new AiService();
        $result = $service->generateDraft(
            prompt: 'Genera un borrador para el caso X',
            systemPrompt: 'Eres un asistente legal'
        );

        $this->assertSame('Borrador IA generado', $result['text']);
        $this->assertSame('claude-sonnet-4-6', $result['model']);
        $this->assertSame('end_turn', $result['stop_reason']);
        $this->assertSame(10, $result['usage']['input_tokens']);
        $this->assertSame(25, $result['usage']['output_tokens']);

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

        $result = (new AiService())->generateDraft('hola');

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

        (new AiService())->generateDraft('hola');

        Http::assertSent(fn ($request) => ! isset($request->data()['system']));
    }
}
