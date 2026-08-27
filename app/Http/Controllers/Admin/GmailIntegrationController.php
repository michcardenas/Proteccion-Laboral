<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Services\GmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GmailIntegrationController extends Controller
{
    public function __construct(private readonly GmailService $gmail) {}

    /**
     * GET /admin/integrations/gmail/connect
     * Redirige al consentimiento OAuth de Google.
     */
    public function connect(): RedirectResponse
    {
        return redirect()->away($this->gmail->getAuthUrl());
    }

    /**
     * GET /admin/integrations/gmail/callback
     * Recibe el `code` de Google, intercambia tokens y los persiste.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('error')) {
            return redirect()
                ->route('admin.integrations.gmail.status')
                ->with('error', 'Autorización cancelada o denegada en Google.');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()
                ->route('admin.integrations.gmail.status')
                ->with('error', 'No se recibió el código de autorización.');
        }

        try {
            $this->gmail->handleCallback($code, $request->user()->id);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.integrations.gmail.status')
                ->with('error', 'No se pudo completar la conexión con Gmail: '.$e->getMessage());
        }

        return redirect()
            ->route('admin.integrations.gmail.status')
            ->with('success', 'Cuenta de Gmail conectada correctamente.');
    }

    /**
     * POST /admin/integrations/gmail/disconnect
     *
     * Desconecta UNA cuenta. Borraba todas de golpe, que valia cuando el
     * despacho compartia una sola bandeja: desde que cada abogada conecta la
     * suya, un clic aqui dejaba a todo el mundo sin correo, y sin nada que
     * avisara de que se estaban llevando por delante las cuentas de los demas.
     *
     * Cada quien desconecta la suya; direccion puede desconectar cualquiera.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'token_id' => ['required', 'integer', 'exists:integration_tokens,id'],
        ]);

        $token = IntegrationToken::findOrFail($datos['token_id']);

        abort_unless(
            $request->user()->hasRole('director') || $token->connected_by_user_id === $request->user()->id,
            403,
            'Esa cuenta la conectó otra persona.',
        );

        $correo = $token->account_email;
        $token->delete();

        return redirect()
            ->route('admin.integrations.gmail.status')
            ->with('success', "Cuenta «{$correo}» desconectada. Sus correos ya ingeridos se conservan.");
    }

    /**
     * GET /admin/integrations/gmail/status
     * Página del asistente: muestra el estado de la conexión.
     */
    public function status(): Response
    {
        // Todas las cuentas conectadas, no solo la ultima. Con una bandeja por
        // abogada, enseñar solo la mas reciente hacia creer que las demas se
        // habian perdido.
        $tokens = IntegrationToken::query()
            ->where('provider', IntegrationToken::PROVIDER_GMAIL)
            ->with('connectedBy:id,name')
            ->orderBy('id')
            ->get();

        $cuentas = $tokens->map(fn (IntegrationToken $t) => [
            'id' => $t->id,
            'account_email' => $t->account_email,
            'scopes' => $t->scopes,
            'expires_at' => $t->expires_at?->toIso8601String(),
            'is_expired' => $t->isExpired(),
            'connected_by' => $t->connectedBy?->name,
            'connected_by_user_id' => $t->connected_by_user_id,
            'connected_at' => $t->created_at?->toIso8601String(),
            'missing_scopes' => array_values(array_filter(
                config('gmail.scopes', []),
                fn (string $scope) => ! $t->hasScope($scope),
            )),
        ])->all();

        $token = $tokens->last();

        return Inertia::render('Admin/Integrations/Gmail', [
            'cuentas' => $cuentas,
            'connection' => $token ? [
                'connected' => true,
                'id' => $token->id,
                'account_email' => $token->account_email,
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_expired' => $token->isExpired(),
                'connected_by' => $token->connectedBy?->name,
                'connected_at' => $token->created_at?->toIso8601String(),
                // Scopes pedidos en la config que este token NO trae: se otorgan
                // reconectando la cuenta (Google no amplía un token ya emitido).
                'missing_scopes' => array_values(array_filter(
                    config('gmail.scopes', []),
                    fn (string $scope) => ! $token->hasScope($scope),
                )),
            ] : [
                'connected' => false,
            ],
        ]);
    }
}
