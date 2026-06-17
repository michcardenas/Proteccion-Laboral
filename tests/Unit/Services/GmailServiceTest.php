<?php

namespace Tests\Unit\Services;

use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\GmailService;
use Google\Client as GoogleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GmailServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Codifica una cadena en base64url (la variante que usa Gmail en los cuerpos).
     */
    protected function b64url(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    // === getAuthUrl ===

    public function test_get_auth_url_delegates_to_google_client(): void
    {
        $client = Mockery::mock(GoogleClient::class);
        $client->shouldReceive('createAuthUrl')
            ->once()
            ->andReturn('https://accounts.google.com/o/oauth2/auth?foo=bar');

        $service = (new GmailService())->setClient($client);

        $this->assertSame(
            'https://accounts.google.com/o/oauth2/auth?foo=bar',
            $service->getAuthUrl()
        );
    }

    // === handleCallback ===

    public function test_handle_callback_persists_integration_token(): void
    {
        $client = Mockery::mock(GoogleClient::class);
        $client->shouldReceive('fetchAccessTokenWithAuthCode')
            ->once()
            ->with('code-123')
            ->andReturn([
                'access_token' => 'access-abc',
                'refresh_token' => 'refresh-xyz',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.modify',
            ]);
        $client->shouldReceive('setAccessToken')->once();

        // Sobreescribimos fetchAccountEmail para no llamar a la API real de Google.
        $service = new class extends GmailService {
            protected function fetchAccountEmail(): string
            {
                return 'legal@proteccionlaboral.co';
            }
        };
        $service->setClient($client);

        $user = User::factory()->create();

        $token = $service->handleCallback('code-123', $user->id);

        $this->assertSame('access-abc', $token['access_token']);

        $this->assertDatabaseHas('integration_tokens', [
            'provider' => 'gmail',
            'account_email' => 'legal@proteccionlaboral.co',
            'connected_by_user_id' => $user->id,
        ]);

        $stored = IntegrationToken::first();
        $this->assertSame('access-abc', $stored->access_token);   // descifrado por el cast
        $this->assertSame('refresh-xyz', $stored->refresh_token);
        $this->assertEqualsCanonicalizing([
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.modify',
        ], $stored->scopes);
        $this->assertNotNull($stored->expires_at);
    }

    public function test_handle_callback_throws_on_oauth_error(): void
    {
        $client = Mockery::mock(GoogleClient::class);
        $client->shouldReceive('fetchAccessTokenWithAuthCode')
            ->once()
            ->andReturn(['error' => 'invalid_grant', 'error_description' => 'Bad code']);

        $service = (new GmailService())->setClient($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad code');

        $service->handleCallback('mal-code', 1);
    }

    // === normalizeMessage (helper puro) ===

    public function test_normalize_message_extracts_headers_body_and_attachments(): void
    {
        $raw = [
            'id' => 'msg-1',
            'internalDate' => '1700000000000',
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => [
                    ['name' => 'From', 'value' => 'Juzgado <juez@ramajudicial.gov.co>'],
                    ['name' => 'Subject', 'value' => 'Fijación de audiencia'],
                    ['name' => 'Date', 'value' => 'Mon, 1 Jan 2024 10:00:00 +0000'],
                ],
                'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64url('Hola, cuerpo en texto.')]],
                    ['mimeType' => 'text/html', 'body' => ['data' => $this->b64url('<p>Hola HTML</p>')]],
                    [
                        'mimeType' => 'application/pdf',
                        'filename' => 'auto_audiencia.pdf',
                        'body' => ['attachmentId' => 'att-1', 'size' => 2048],
                    ],
                ],
            ],
        ];

        $result = (new GmailService())->normalizeMessage($raw);

        $this->assertSame('msg-1', $result['message_id']);
        $this->assertSame('Juzgado <juez@ramajudicial.gov.co>', $result['from']);
        $this->assertSame('Fijación de audiencia', $result['subject']);
        $this->assertSame('Hola, cuerpo en texto.', $result['body_text']);
        $this->assertSame('<p>Hola HTML</p>', $result['body_html']);
        $this->assertCount(1, $result['attachments']);
        $this->assertSame('auto_audiencia.pdf', $result['attachments'][0]['filename']);
        $this->assertSame('application/pdf', $result['attachments'][0]['mime_type']);
        $this->assertSame('att-1', $result['attachments'][0]['attachment_id']);
        $this->assertSame(2048, $result['attachments'][0]['size']);
        $this->assertIsString($result['received_at']);
        $this->assertStringContainsString('2023-11', $result['received_at']);
    }

    public function test_normalize_message_handles_nested_parts(): void
    {
        $raw = [
            'id' => 'msg-2',
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => [['name' => 'Subject', 'value' => 'Anidado']],
                'parts' => [
                    [
                        'mimeType' => 'multipart/alternative',
                        'parts' => [
                            ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64url('Texto anidado')]],
                        ],
                    ],
                ],
            ],
        ];

        $result = (new GmailService())->normalizeMessage($raw);

        $this->assertSame('Texto anidado', $result['body_text']);
        $this->assertSame('Anidado', $result['subject']);
    }

    // === decodeBase64Url / extractHeader (helpers puros) ===

    public function test_decode_base64_url(): void
    {
        $service = new GmailService();
        $encoded = $this->b64url('Contenido con ñ y +/');

        $this->assertSame('Contenido con ñ y +/', $service->decodeBase64Url($encoded));
    }

    public function test_extract_header_is_case_insensitive(): void
    {
        $service = new GmailService();
        $headers = [
            ['name' => 'From', 'value' => 'a@b.com'],
            ['name' => 'Subject', 'value' => 'Hola'],
        ];

        $this->assertSame('a@b.com', $service->extractHeader($headers, 'from'));
        $this->assertSame('Hola', $service->extractHeader($headers, 'SUBJECT'));
        $this->assertSame('', $service->extractHeader($headers, 'Cc'));
    }

    // === normalizeMessage: datos de enhebrado (thread_id / Message-ID) ===

    public function test_normalize_message_captures_thread_id_and_message_id_header(): void
    {
        $raw = [
            'id' => 'msg-9',
            'threadId' => 'thr-9',
            'payload' => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<abc123@mail.gmail.com>'],
                    ['name' => 'Subject', 'value' => 'Consulta'],
                ],
            ],
        ];

        $result = (new GmailService())->normalizeMessage($raw);

        $this->assertSame('thr-9', $result['thread_id']);
        $this->assertSame('<abc123@mail.gmail.com>', $result['message_id_header']);
    }

    // === buildRawMessage (helper puro) ===

    public function test_build_raw_message_encodes_headers_threading_and_body(): void
    {
        $encoded = (new GmailService())->buildRawMessage(
            'cliente@empresa.com',
            'Re: Fijación de audiencia',
            'Estimado cliente, confirmamos recepción.',
            '<orig-123@mail.gmail.com>',
        );

        // base64url → MIME plano.
        $mime = base64_decode(strtr($encoded, '-_', '+/'));

        $this->assertStringContainsString('To: cliente@empresa.com', $mime);
        $this->assertStringContainsString('In-Reply-To: <orig-123@mail.gmail.com>', $mime);
        $this->assertStringContainsString('References: <orig-123@mail.gmail.com>', $mime);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        // Asunto con tilde → encoded-word RFC 2047.
        $this->assertStringContainsString('=?UTF-8?B?', $mime);
        // Cuerpo tras la línea en blanco.
        $this->assertStringContainsString("\r\n\r\nEstimado cliente, confirmamos recepción.", $mime);
    }

    public function test_build_raw_message_without_threading_omits_reply_headers(): void
    {
        $encoded = (new GmailService())->buildRawMessage('a@b.com', 'Hola', 'Cuerpo');
        $mime = base64_decode(strtr($encoded, '-_', '+/'));

        $this->assertStringNotContainsString('In-Reply-To:', $mime);
        $this->assertStringContainsString('To: a@b.com', $mime);
        $this->assertStringContainsString('Subject: Hola', $mime);
    }
}
