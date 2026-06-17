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

        $nit = trim($credentials['nit']);

        $client = Client::query()
            ->where('nit', $nit)
            ->where('portal_activo', true)
            ->first();

        // Mensaje genérico para no revelar si el NIT existe o el portal está activo.
        if (! $client
            || ! $client->password
            || ! Auth::guard('client')->attempt(['nit' => $nit, 'password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'nit' => 'Las credenciales no son válidas o el portal aún no está habilitado para este NIT.',
            ]);
        }

        // Defensa adicional: debe tener un proceso con abogado asignado.
        if (! $client->puedeAccederPortal()) {
            Auth::guard('client')->logout();

            throw ValidationException::withMessages([
                'nit' => 'Tu portal aún no tiene un proceso activo asignado. Contacta a tu abogado.',
            ]);
        }

        $request->session()->regenerate();
        $client->forceFill(['portal_last_login_at' => now()])->saveQuietly();

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
