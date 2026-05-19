<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientContactController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        if (! $request->user()->can('clients.update')) {
            abort(403);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'es_principal' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($client, $data) {
            if (! empty($data['es_principal'])) {
                $client->contactos()->update(['es_principal' => false]);
            }

            $client->contactos()->create([
                'nombre' => $data['nombre'],
                'cargo' => $data['cargo'] ?? null,
                'email' => $data['email'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'es_principal' => $data['es_principal'] ?? false,
            ]);
        });

        return back()->with('success', 'Contacto agregado.');
    }

    public function update(Request $request, Client $client, ClientContact $contact): RedirectResponse
    {
        if (! $request->user()->can('clients.update')) {
            abort(403);
        }

        abort_unless($contact->client_id === $client->id, 404);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'es_principal' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($client, $contact, $data) {
            if (! empty($data['es_principal'])) {
                $client->contactos()->where('id', '!=', $contact->id)->update(['es_principal' => false]);
            }

            $contact->update($data);
        });

        return back()->with('success', 'Contacto actualizado.');
    }

    public function destroy(Request $request, Client $client, ClientContact $contact): RedirectResponse
    {
        if (! $request->user()->can('clients.update')) {
            abort(403);
        }

        abort_unless($contact->client_id === $client->id, 404);

        $contact->delete();

        return back()->with('success', 'Contacto eliminado.');
    }
}
