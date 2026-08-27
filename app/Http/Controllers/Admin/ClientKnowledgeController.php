<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientKnowledgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientKnowledgeController extends Controller
{
    /**
     * POST /admin/clients/{client}/knowledge/regenerate
     * Regenera manualmente la ficha de conocimiento (además del disparo automático al
     * subir/borrar documentos). Se ejecuta en línea para dar feedback inmediato.
     */
    public function regenerate(Request $request, Client $client, ClientKnowledgeService $service): RedirectResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);
        $this->authorizeClientAccess($request, $client);

        $ok = $service->build($client);

        return $ok
            ? back()->with('success', 'Ficha de conocimiento actualizada.')
            : back()->with('error', 'No se pudo actualizar la ficha (revisa el registro de IA).');
    }

    /**
     * Aborta 403 si el usuario tiene visibilidad restringida de clientes
     * (`clients.view_assigned` sin `clients.view`) y no está asignado al cliente.
     */
    private function authorizeClientAccess(Request $request, Client $client): void
    {
        /** @var User $user */
        $user = $request->user();

        $restringido = ! $user->can('clients.view') && $user->can('clients.view_assigned');
        if (! $restringido) {
            return;
        }

        abort_unless($client->asignados()->where('users.id', $user->id)->exists(), 403);
    }
}
