<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientAssignmentController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        if (! $request->user()->can('clients.update')) {
            abort(403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'rol_asignacion' => ['required', 'in:lider,apoyo,apoderado,observador'],
        ]);

        $client->asignados()->syncWithoutDetaching([
            $data['user_id'] => ['rol_asignacion' => $data['rol_asignacion']],
        ]);

        return back()->with('success', 'Profesional asignado al cliente.');
    }

    public function destroy(Request $request, Client $client, User $user): RedirectResponse
    {
        if (! $request->user()->can('clients.update')) {
            abort(403);
        }

        $client->asignados()->detach($user->id);

        return back()->with('success', 'Asignación eliminada.');
    }
}
