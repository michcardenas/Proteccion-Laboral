<?php

namespace App\Services;

use App\Models\IntegrationToken;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Label;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\ModifyMessageRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class GmailService
{
    protected ?GoogleClient $client = null;

    public function __construct(
        protected ?string $clientId = null,
        protected ?string $clientSecret = null,
        protected ?string $redirectUri = null,
        protected ?array $scopes = null,
    ) {
        $this->clientId ??= config('gmail.client_id');
        $this->clientSecret ??= config('gmail.client_secret');
        $this->redirectUri ??= config('gmail.redirect_uri');
        $this->scopes ??= config('gmail.scopes', []);
    }

    /**
     * Builds (or returns a cached) Google_Client configured for Gmail OAuth.
     */
    public function client(): GoogleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $client = new GoogleClient;
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes($this->scopes);
        $client->setAccessType(config('gmail.access_type', 'offline'));
        $client->setPrompt(config('gmail.prompt', 'consent'));

        return $this->client = $client;
    }

    /**
     * Inyecta un Google_Client (p. ej. un mock en tests). Tiene prioridad sobre client().
     */
    public function setClient(GoogleClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Returns the consent URL the user must visit to grant Gmail access.
     */
    public function getAuthUrl(): string
    {
        return $this->client()->createAuthUrl();
    }

    /**
     * Exchanges the OAuth code for access/refresh tokens, resuelve el email de la
     * cuenta y persiste todo en integration_tokens.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string}
     *
     * @throws RuntimeException si Google devuelve un error en el intercambio.
     */
    public function handleCallback(string $code, ?int $connectedByUserId = null): array
    {
        $client = $this->client();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('OAuth Gmail falló: '.($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);
        $email = $this->fetchAccountEmail();

        $existing = IntegrationToken::query()
            ->where('provider', IntegrationToken::PROVIDER_GMAIL)
            ->where('account_email', $email)
            ->first();

        IntegrationToken::updateOrCreate(
            ['provider' => IntegrationToken::PROVIDER_GMAIL, 'account_email' => $email],
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $existing?->refresh_token,
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'scopes' => isset($token['scope']) ? explode(' ', $token['scope']) : $this->scopes,
                'connected_by_user_id' => $connectedByUserId ?? Auth::id(),
            ]
        );

        return $token;
    }

    /**
     * Fetches unread messages from the connected Gmail account, normalizados.
     *
     * @return array<int, array{
     *     message_id: string, from: string, subject: string,
     *     body_text: string, body_html: string,
     *     attachments: array<int, array{filename: string, mime_type: string, attachment_id: string, size: ?int}>,
     *     received_at: ?string
     * }>
     */
    public function fetchUnread(int $maxResults = 50): array
    {
        $gmail = $this->gmail();

        $list = $gmail->users_messages->listUsersMessages('me', [
            'q' => 'is:unread',
            'maxResults' => $maxResults,
        ]);

        $messages = [];
        foreach ($list->getMessages() ?? [] as $ref) {
            $message = $gmail->users_messages->get('me', $ref->getId(), ['format' => 'full']);
            $messages[] = $this->normalizeMessage($this->toArray($message));
        }

        return $messages;
    }

    /**
     * Downloads attachment payloads for a given message.
     *
     * @return array<int, array{filename: string, mime_type: string, data: string}>
     */
    public function getAttachments(string $messageId): array
    {
        $gmail = $this->gmail();
        $message = $gmail->users_messages->get('me', $messageId, ['format' => 'full']);

        $collected = ['text' => '', 'html' => '', 'attachments' => []];
        $this->collectParts($this->toArray($message)['payload'] ?? [], $collected);

        $results = [];
        foreach ($collected['attachments'] as $att) {
            $body = $gmail->users_messages_attachments->get('me', $messageId, $att['attachment_id']);
            $results[] = [
                'filename' => $att['filename'],
                'mime_type' => $att['mime_type'],
                'data' => $this->decodeBase64Url($body->getData()),
            ];
        }

        return $results;
    }

    /**
     * Marks a message as read (removes the UNREAD label).
     */
    public function markAsRead(string $messageId): void
    {
        $this->gmail()->users_messages->modify(
            'me',
            $messageId,
            new ModifyMessageRequest(['removeLabelIds' => ['UNREAD']])
        );
    }

    /**
     * Adds a label (by name; creates it if it doesn't exist) to a message.
     */
    public function addLabel(string $messageId, string $label): void
    {
        $gmail = $this->gmail();
        $labelId = $this->findOrCreateLabel($gmail, $label);

        $gmail->users_messages->modify(
            'me',
            $messageId,
            new ModifyMessageRequest(['addLabelIds' => [$labelId]])
        );
    }

    /**
     * Envía una respuesta a través de la cuenta de Gmail conectada.
     *
     * @param  array  $params  [
     *                         'to' => string (requerido), 'subject' => string (requerido), 'body' => string (requerido),
     *                         'thread_id' => ?string (para responder en el mismo hilo),
     *                         'in_reply_to' => ?string (Message-ID original, para In-Reply-To/References)
     *                         ]
     * @return string Id del mensaje enviado.
     */
    public function sendReply(array $params): string
    {
        $raw = $this->buildRawMessage(
            $params['to'],
            $params['subject'],
            $params['body'],
            $params['in_reply_to'] ?? null,
        );

        $message = new GmailMessage(['raw' => $raw]);
        if (! empty($params['thread_id'])) {
            $message->setThreadId($params['thread_id']);
        }

        return $this->gmail()->users_messages->send('me', $message)->getId();
    }

    /**
     * Construye un mensaje MIME (texto plano UTF-8) y lo codifica en base64url,
     * como exige la API de Gmail. Método puro: testeable sin tocar la red.
     */
    public function buildRawMessage(string $to, string $subject, string $body, ?string $inReplyTo = null): string
    {
        $headers = [
            'To: '.$to,
            'Subject: '.$this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // Enhebra la respuesta con el correo original.
        if ($inReplyTo) {
            array_splice($headers, 2, 0, [
                'In-Reply-To: '.$inReplyTo,
                'References: '.$inReplyTo,
            ]);
        }

        $mime = implode("\r\n", $headers)."\r\n\r\n".$body;

        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }

    /**
     * Codifica un header con caracteres no ASCII como encoded-word (RFC 2047),
     * necesario para asuntos con tildes/ñ.
     */
    protected function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?'.base64_encode($value).'?=';
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // Helpers de autorización / acceso a la API
    // ------------------------------------------------------------------

    /**
     * Devuelve el Google_Client ya autorizado con el token guardado (refrescándolo si
     * hace falta). Lo usa DriveService para hablar con la API de Drive con la MISMA
     * cuenta conectada, sin duplicar la lógica de tokens.
     *
     * @throws RuntimeException si no hay una cuenta conectada.
     */
    public function authorizedClient(): GoogleClient
    {
        $this->authorizeFromStoredToken();

        return $this->client();
    }

    /**
     * Devuelve un servicio Gmail autorizado a partir del token almacenado.
     */
    protected function gmail(): Gmail
    {
        $this->authorizeFromStoredToken();

        return new Gmail($this->client());
    }

    /**
     * Carga el token guardado en el cliente y lo refresca si expiró.
     *
     * @throws RuntimeException si no hay una cuenta conectada.
     */
    protected function authorizeFromStoredToken(): void
    {
        $token = IntegrationToken::query()
            ->where('provider', IntegrationToken::PROVIDER_GMAIL)
            ->latest('id')
            ->first();

        if (! $token) {
            throw new RuntimeException('No hay una cuenta de Gmail conectada.');
        }

        $client = $this->client();
        $client->setAccessToken([
            'access_token' => $token->access_token,
            'refresh_token' => $token->refresh_token,
            'expires_in' => $token->expires_at ? now()->diffInSeconds($token->expires_at, false) : 0,
            'created' => now()->timestamp,
        ]);

        if ($client->isAccessTokenExpired() && $token->refresh_token) {
            $refreshed = $client->fetchAccessTokenWithRefreshToken($token->refresh_token);
            if (! isset($refreshed['error'])) {
                $token->update([
                    'access_token' => $refreshed['access_token'],
                    'expires_at' => now()->addSeconds((int) ($refreshed['expires_in'] ?? 3600)),
                ]);
            }
        }
    }

    /**
     * Resuelve el email de la cuenta conectada (separado para poder mockearlo en tests).
     */
    protected function fetchAccountEmail(): string
    {
        return (new Gmail($this->client()))->users->getProfile('me')->getEmailAddress();
    }

    /**
     * Busca un label por nombre (case-insensitive) y lo crea si no existe; devuelve su id.
     */
    protected function findOrCreateLabel(Gmail $gmail, string $label): string
    {
        foreach ($gmail->users_labels->listUsersLabels('me')->getLabels() ?? [] as $existing) {
            if (strcasecmp($existing->getName(), $label) === 0) {
                return $existing->getId();
            }
        }

        $created = $gmail->users_labels->create('me', new Label([
            'name' => $label,
            'labelListVisibility' => 'labelShow',
            'messageListVisibility' => 'show',
        ]));

        return $created->getId();
    }

    /**
     * Convierte un modelo de Google Service en array asociativo con las claves del JSON REST.
     */
    protected function toArray(object $googleModel): array
    {
        return json_decode(json_encode($googleModel->toSimpleObject()), true) ?? [];
    }

    // ------------------------------------------------------------------
    // Helpers puros (testeables sin la API de Google)
    // ------------------------------------------------------------------

    /**
     * Normaliza el payload REST de un mensaje de Gmail a la estructura del dominio.
     *
     * @param  array  $raw  Estructura tal cual la devuelve la API (id, payload, internalDate…).
     * @return array{message_id: string, from: string, subject: string, body_text: string, body_html: string, attachments: array, received_at: ?string}
     */
    public function normalizeMessage(array $raw): array
    {
        $payload = $raw['payload'] ?? [];
        $headers = $payload['headers'] ?? [];

        $collected = ['text' => '', 'html' => '', 'attachments' => []];
        $this->collectParts($payload, $collected);

        $receivedAt = null;
        if (! empty($raw['internalDate'])) {
            $receivedAt = Carbon::createFromTimestampMs((int) $raw['internalDate'])->toIso8601String();
        } elseif ($date = $this->extractHeader($headers, 'Date')) {
            $receivedAt = $date;
        }

        return [
            'message_id' => $raw['id'] ?? '',
            // Datos para enhebrar respuestas: id del hilo de Gmail y el header
            // RFC Message-ID original (para In-Reply-To/References).
            'thread_id' => $raw['threadId'] ?? '',
            'message_id_header' => $this->extractHeader($headers, 'Message-ID'),
            'from' => $this->extractHeader($headers, 'From'),
            'to' => $this->extractHeader($headers, 'To'),
            'subject' => $this->extractHeader($headers, 'Subject'),
            'body_text' => $collected['text'],
            'body_html' => $collected['html'],
            'attachments' => $collected['attachments'],
            'received_at' => $receivedAt,
        ];
    }

    /**
     * Recorre recursivamente las partes MIME acumulando texto, HTML y adjuntos.
     */
    protected function collectParts(array $part, array &$out): void
    {
        $mime = $part['mimeType'] ?? '';
        $filename = $part['filename'] ?? '';
        $body = $part['body'] ?? [];

        if ($filename !== '' && ! empty($body['attachmentId'])) {
            $out['attachments'][] = [
                'filename' => $filename,
                'mime_type' => $mime,
                'attachment_id' => $body['attachmentId'],
                'size' => $body['size'] ?? null,
            ];
        } elseif ($mime === 'text/plain' && ! empty($body['data'])) {
            $out['text'] .= $this->decodeBase64Url($body['data']);
        } elseif ($mime === 'text/html' && ! empty($body['data'])) {
            $out['html'] .= $this->decodeBase64Url($body['data']);
        }

        foreach ($part['parts'] ?? [] as $child) {
            $this->collectParts($child, $out);
        }
    }

    /**
     * Decodifica una cadena en base64url (variante que usa Gmail para los cuerpos).
     */
    public function decodeBase64Url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Devuelve el valor de una cabecera por nombre (case-insensitive) o cadena vacía.
     */
    public function extractHeader(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (isset($header['name']) && strcasecmp($header['name'], $name) === 0) {
                return $header['value'] ?? '';
            }
        }

        return '';
    }
}
