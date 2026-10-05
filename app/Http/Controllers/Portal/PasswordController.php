<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El cliente cambia la clave provisional que le dio el despacho.
 */
class PasswordController extends Controller
{
    public function edit(): Response
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        return Inertia::render('Portal/ChangePassword', [
            'razon_social' => $client->razon_social,
            'obligatorio' => (bool) $client->debe_cambiar_clave,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        // Con letras obligatorias el NIT (solo digitos, y publico) no vale.
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.required' => 'Escribe la nueva contraseña.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.letters' => 'La contraseña debe tener al menos una letra.',
            'password.numbers' => 'La contraseña debe tener al menos un número.',
        ]);

        $client->forceFill([
            'password' => $data['password'], // el cast 'hashed' la cifra
            'debe_cambiar_clave' => false,
        ])->save();

        return redirect()->route('portal.dashboard')->with('success', 'Contraseña actualizada.');
    }
}
