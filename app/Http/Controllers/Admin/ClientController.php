<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public const ESTADOS = ['activo', 'pausado', 'inactivo', 'prospecto'];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Client::query()
            ->with(['asignados:id,name'])
            ->withCount(['contracts', 'processes', 'contactos'])
            ->latest();

        if (! $user->can('clients.view') && $user->can('clients.view_assigned')) {
            $query->whereHas('asignados', fn ($q) => $q->where('users.id', $user->id));
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('razon_social', 'like', "%{$search}%")
                  ->orWhere('nit', 'like', "%{$search}%")
                  ->orWhere('contacto_principal', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }

        if ($sector = $request->string('sector')->toString()) {
            $query->where('sector', $sector);
        }

        $clients = $query->paginate(15)->withQueryString()->through(fn (Client $c) => [
            'id' => $c->id,
            'razon_social' => $c->razon_social,
            'nit' => $c->nit,
            'dv' => $c->dv,
            'ciudad' => $c->ciudad,
            'sector' => $c->sector,
            'estado' => $c->estado,
            'contacto_principal' => $c->contacto_principal,
            'email' => $c->email,
            'telefono' => $c->telefono,
            'contracts_count' => $c->contracts_count,
            'processes_count' => $c->processes_count,
            'contactos_count' => $c->contactos_count,
            'asignados' => $c->asignados->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'created_at' => $c->created_at->toIso8601String(),
        ]);

        $sectores = Client::query()
            ->whereNotNull('sector')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector');

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'estado' => $request->string('estado')->toString(),
                'sector' => $request->string('sector')->toString(),
            ],
            'estados' => self::ESTADOS,
            'sectores' => $sectores,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Clients/Create', [
            'estados' => self::ESTADOS,
        ]);
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $client = Client::create($request->validated());

        return redirect()
            ->route('admin.clients.show', $client)
            ->with('success', 'Cliente creado exitosamente.');
    }

    public function show(Client $client): Response
    {
        $client->load([
            'contactos',
            'asignados:id,name,email',
            'contracts.serviceType:id,nombre,modalidad',
            'processes.serviceType:id,nombre,modalidad',
            'processes.abogadoLider:id,name',
            'documents' => fn ($q) => $q->whereNull('process_id')->latest(),
            'documents.uploader:id,name',
        ]);

        $potentialAssignees = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['abogado_interno', 'abogado_externo', 'apoderado', 'coordinador']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roles->first()?->name,
            ]);

        return Inertia::render('Admin/Clients/Show', [
            'client' => [
                'id' => $client->id,
                'razon_social' => $client->razon_social,
                'nit' => $client->nit,
                'dv' => $client->dv,
                'ciudad' => $client->ciudad,
                'sector' => $client->sector,
                'estado' => $client->estado,
                'contacto_principal' => $client->contacto_principal,
                'email' => $client->email,
                'telefono' => $client->telefono,
                'fecha_alta' => $client->fecha_alta?->format('Y-m-d'),
                'notas' => $client->notas,
                'created_at' => $client->created_at->toIso8601String(),
                // Ficha de conocimiento (digest IA de los documentos del cliente).
                'resumen_documental' => $client->resumen_documental,
                'resumen_documental_at' => $client->resumen_documental_at?->toIso8601String(),
                'ficha_desactualizada' => $client->fichaDesactualizada(),
                // Estado del portal del cliente (NIT + contraseña).
                'portal_activo' => (bool) $client->portal_activo,
                'portal_last_login_at' => $client->portal_last_login_at?->toIso8601String(),
                'puede_acceder_portal' => $client->puedeAccederPortal(),
                'contactos' => $client->contactos->map(fn ($c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'cargo' => $c->cargo,
                    'email' => $c->email,
                    'telefono' => $c->telefono,
                    'es_principal' => $c->es_principal,
                ]),
                'asignados' => $client->asignados->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'rol_asignacion' => $u->pivot->rol_asignacion,
                ]),
                'contracts' => $client->contracts->map(fn ($c) => [
                    'id' => $c->id,
                    'codigo' => $c->codigo,
                    'estado' => $c->estado,
                    'modalidad_pago' => $c->modalidad_pago,
                    'valor' => $c->valor,
                    'fecha_inicio' => $c->fecha_inicio?->format('Y-m-d'),
                    'fecha_fin' => $c->fecha_fin?->format('Y-m-d'),
                    'service' => $c->serviceType ? ['nombre' => $c->serviceType->nombre, 'modalidad' => $c->serviceType->modalidad] : null,
                ]),
                'processes' => $client->processes->map(fn ($p) => [
                    'id' => $p->id,
                    'codigo' => $p->codigo,
                    'titulo' => $p->titulo,
                    'estado' => $p->estado,
                    'fecha_apertura' => $p->fecha_apertura?->format('Y-m-d'),
                    'service' => $p->serviceType ? ['nombre' => $p->serviceType->nombre, 'modalidad' => $p->serviceType->modalidad] : null,
                    'lider' => $p->abogadoLider?->name,
                ]),
                'documentos' => $client->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'tipo' => $d->tipo,
                    'mime' => $d->mime,
                    'tamano_bytes' => $d->tamano_bytes,
                    'visible_cliente' => (bool) $d->visible_cliente,
                    'subido_por' => $d->uploader?->name,
                    'created_at' => $d->created_at?->toIso8601String(),
                ]),
            ],
            'potentialAssignees' => $potentialAssignees,
            'estados' => self::ESTADOS,
            'documentTypes' => \App\Http\Controllers\Admin\ClientDocumentController::DOCUMENT_TYPES,
        ]);
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('Admin/Clients/Edit', [
            'client' => [
                'id' => $client->id,
                'razon_social' => $client->razon_social,
                'nit' => $client->nit,
                'dv' => $client->dv,
                'ciudad' => $client->ciudad,
                'sector' => $client->sector,
                'estado' => $client->estado,
                'contacto_principal' => $client->contacto_principal,
                'email' => $client->email,
                'telefono' => $client->telefono,
                'fecha_alta' => $client->fecha_alta?->format('Y-m-d'),
                'notas' => $client->notas,
            ],
            'estados' => self::ESTADOS,
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        return redirect()
            ->route('admin.clients.show', $client)
            ->with('success', 'Cliente actualizado.');
    }

    /**
     * Activa el portal del cliente y define/genera su contraseña.
     * El cliente entrará con su NIT + esta contraseña.
     */
    public function activatePortal(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->can('clients.update'), 403);

        $data = $request->validate([
            // Opcional: si no se envía, se genera una temporal y se muestra una vez.
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ]);

        $plain = $data['password'] ?? \Illuminate\Support\Str::password(10, symbols: false);

        $client->forceFill([
            'password' => $plain, // el cast 'hashed' del modelo lo cifra
            'portal_activo' => true,
        ])->save();

        // Se devuelve la contraseña en claro UNA sola vez para que el despacho la comparta.
        return back()->with('portal_credentials', [
            'nit' => $client->nit,
            'password' => $plain,
        ])->with('success', 'Portal del cliente activado.');
    }

    /**
     * Desactiva el portal del cliente (no podrá iniciar sesión).
     */
    public function deactivatePortal(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->can('clients.update'), 403);

        $client->forceFill(['portal_activo' => false])->save();

        return back()->with('success', 'Portal del cliente desactivado.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        if (! request()->user()->can('clients.delete')) {
            abort(403);
        }

        $client->delete();

        return redirect()
            ->route('admin.clients.index')
            ->with('success', 'Cliente eliminado.');
    }
}
