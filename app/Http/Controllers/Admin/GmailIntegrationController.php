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
    public function __construct(private readonly GmailService $gmail)
    {
    }

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
     * Elimina los tokens de Gmail almacenados.
     */
    public function disconnect(): RedirectResponse
    {
        IntegrationToken::query()
            ->where('provider', IntegrationToken::PROVIDER_GMAIL)
            ->delete();

        return redirect()
            ->route('admin.integrations.gmail.status')
            ->with('success', 'Cuenta de Gmail desconectada.');
    }

    /**
     * GET /admin/integrations/gmail/status
     * Página del asistente: muestra el estado de la conexión.
     */
    public function status(): Response
    {
        $token = IntegrationToken::query()
            ->where('provider', IntegrationToken::PROVIDER_GMAIL)
            ->with('connectedBy:id,name')
            ->latest('id')
            ->first();

        return Inertia::render('Admin/Integrations/Gmail', [
            'connection' => $token ? [
                'connected' => true,
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
