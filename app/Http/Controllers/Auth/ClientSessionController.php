<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientSessionController extends Controller
{
    /**
     * Pantalla de login del portal del cliente (NIT + contraseña).
     */
    public function create(): Response
    {
        return Inertia::render('Portal/Login', [
            'status' => session('status'),
        ]);
    }

    /**
     * Autentica al cliente por su NIT contra el guard `client`.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'nit' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Con o sin puntos y digito de verificacion: hay NIT guardados de las
        // dos formas y la clave provisional es el NIT sin puntos.
        $nit = Client::nitSinPuntos($credentials['nit']);

        $client = $nit === null ? null : Client::query()
            ->where('portal_activo', true)
            ->whereNotNull('nit')
            ->get()
            ->first(fn (Client $c) => Client::nitSinPuntos($c->nit) === $nit);

        // Mensaje genérico para no revelar si el NIT existe o el portal está activo.
        if (! $client
            || ! $client->password
            || ! Auth::guard('client')->attempt(['nit' => $client->nit, 'password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'nit' => 'Las credenciales no son válidas o el portal aún no está habilitado para este NIT.',
            ]);
        }

        // Defensa adicional: debe tener un proceso con abogado asignado.
        if (! $client->puedeAccederPortal()) {
            Auth::guard('client')->logout();

            throw ValidationException::withMessages([
                'nit' => 'Tu acceso está activo, pero ninguno de tus procesos tiene todavía un abogado asignado. Contacta al despacho para que lo asigne.',
            ]);
        }

        $request->session()->regenerate();
        $client->forceFill(['portal_last_login_at' => now()])->saveQuietly();

        if ($client->debe_cambiar_clave) {
            return redirect()->route('portal.password.edit');
        }

        return redirect()->intended(route('portal.dashboard'));
    }

    /**
     * Cierra la sesión del cliente.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
