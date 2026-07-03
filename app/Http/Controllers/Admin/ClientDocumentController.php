<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientDocumentController extends Controller
{
    /**
     * Tipos válidos para la columna `tipo` de documents (mismos que usa el módulo IA).
     */
    public const DOCUMENT_TYPES = [
        'contrato', 'concepto', 'informe', 'escrito', 'comunicacion', 'soporte', 'otro',
    ];

    /**
     * POST /admin/clients/{client}/documents
     * Adjunta un archivo (PDF del contrato, diagnóstico pre-jurídico, etc.) al cliente.
     */
    public function store(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()?->can('documents.upload'), 403);
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'archivo' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,txt'],
            'nombre' => ['nullable', 'string', 'max:200'],
            'tipo' => ['nullable', 'string', 'in:'.implode(',', self::DOCUMENT_TYPES)],
            'visible_cliente' => ['sometimes', 'boolean'],
        ]);

        $file = $validated['archivo'];
        $ruta = $file->store("clients/client_{$client->id}", 'local');

        Document::create([
            'client_id' => $client->id,
            'nombre' => $validated['nombre'] ?: $file->getClientOriginalName(),
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => $validated['tipo'] ?? 'otro',
            'mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'generado_por_ia' => false,
            'subido_por' => $request->user()?->id,
            'visible_cliente' => $request->boolean('visible_cliente'),
        ]);

        return back()->with('success', 'Documento adjuntado al cliente.');
    }

    /**
     * DELETE /admin/clients/{client}/documents/{document}
     */
    public function destroy(Request $request, Client $client, Document $document): RedirectResponse
    {
        abort_unless($request->user()?->can('documents.delete'), 403);
        abort_unless($document->client_id === $client->id, 404);
        $this->authorizeClientAccess($request, $client);

        $document->delete();

        return back()->with('success', 'Documento eliminado.');
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
