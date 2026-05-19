<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreContractRequest;
use App\Http\Requests\Admin\UpdateContractRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public const ESTADOS = ['borrador', 'activo', 'pausado', 'finalizado', 'cancelado'];
    public const MODALIDADES_PAGO = ['mensual', 'unico', 'por_etapa', 'por_hora'];

    public function index(Request $request): Response
    {
        $query = Contract::query()
            ->with(['client:id,razon_social,nit', 'serviceType:id,nombre,modalidad'])
            ->withCount('processes')
            ->latest('fecha_inicio');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', "%{$search}%")
                  ->orWhereHas('client', fn ($c) => $c->where('razon_social', 'like', "%{$search}%"));
            });
        }

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }

        if ($clientId = $request->integer('client_id')) {
            $query->where('client_id', $clientId);
        }

        if ($serviceTypeId = $request->integer('service_type_id')) {
            $query->where('service_type_id', $serviceTypeId);
        }

        $contracts = $query->paginate(15)->withQueryString()->through(fn (Contract $c) => [
            'id' => $c->id,
            'codigo' => $c->codigo,
            'estado' => $c->estado,
            'modalidad_pago' => $c->modalidad_pago,
            'valor' => $c->valor,
            'fecha_inicio' => $c->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin?->format('Y-m-d'),
            'client' => $c->client ? ['id' => $c->client->id, 'razon_social' => $c->client->razon_social, 'nit' => $c->client->nit] : null,
            'service' => $c->serviceType ? ['id' => $c->serviceType->id, 'nombre' => $c->serviceType->nombre, 'modalidad' => $c->serviceType->modalidad] : null,
            'processes_count' => $c->processes_count,
        ]);

        $totals = [
            'count' => Contract::count(),
            'activos' => Contract::where('estado', 'activo')->count(),
            'valor_activos' => (float) Contract::where('estado', 'activo')->sum('valor'),
        ];

        return Inertia::render('Admin/Contracts/Index', [
            'contracts' => $contracts,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'estado' => $request->string('estado')->toString(),
                'client_id' => $request->input('client_id'),
                'service_type_id' => $request->input('service_type_id'),
            ],
            'estados' => self::ESTADOS,
            'clients' => Client::orderBy('razon_social')->get(['id', 'razon_social']),
            'serviceTypes' => ServiceType::where('es_activo', true)->orderBy('nombre')->get(['id', 'nombre', 'modalidad']),
            'totals' => $totals,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Contracts/Create', [
            'estados' => self::ESTADOS,
            'modalidadesPago' => self::MODALIDADES_PAGO,
            'clients' => Client::orderBy('razon_social')->get(['id', 'razon_social', 'nit']),
            'serviceTypes' => ServiceType::where('es_activo', true)->orderBy('nombre')->get(['id', 'nombre', 'modalidad']),
            'preselectClientId' => $request->integer('client_id') ?: null,
            'suggestedCode' => $this->suggestCode(),
        ]);
    }

    public function store(StoreContractRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['codigo'] = $data['codigo'] ?: $this->suggestCode();

        $contract = Contract::create($data);

        return redirect()
            ->route('admin.contracts.show', $contract)
            ->with('success', 'Contrato creado exitosamente.');
    }

    public function show(Contract $contract): Response
    {
        $contract->load([
            'client:id,razon_social,nit,dv,ciudad,sector,estado,email,telefono',
            'serviceType:id,nombre,modalidad,descripcion',
            'processes' => fn ($q) => $q->with('abogadoLider:id,name')->latest('fecha_apertura'),
            'invoices' => fn ($q) => $q->latest('fecha_emision'),
        ]);

        return Inertia::render('Admin/Contracts/Show', [
            'contract' => [
                'id' => $contract->id,
                'codigo' => $contract->codigo,
                'estado' => $contract->estado,
                'modalidad_pago' => $contract->modalidad_pago,
                'valor' => $contract->valor,
                'fecha_inicio' => $contract->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $contract->fecha_fin?->format('Y-m-d'),
                'notas' => $contract->notas,
                'created_at' => $contract->created_at->toIso8601String(),
                'client' => $contract->client ? [
                    'id' => $contract->client->id,
                    'razon_social' => $contract->client->razon_social,
                    'nit' => $contract->client->nit,
                    'dv' => $contract->client->dv,
                    'ciudad' => $contract->client->ciudad,
                    'sector' => $contract->client->sector,
                    'estado' => $contract->client->estado,
                    'email' => $contract->client->email,
                    'telefono' => $contract->client->telefono,
                ] : null,
                'service' => $contract->serviceType ? [
                    'id' => $contract->serviceType->id,
                    'nombre' => $contract->serviceType->nombre,
                    'modalidad' => $contract->serviceType->modalidad,
                    'descripcion' => $contract->serviceType->descripcion,
                ] : null,
                'processes' => $contract->processes->map(fn ($p) => [
                    'id' => $p->id,
                    'codigo' => $p->codigo,
                    'titulo' => $p->titulo,
                    'estado' => $p->estado,
                    'fecha_apertura' => $p->fecha_apertura?->format('Y-m-d'),
                    'lider' => $p->abogadoLider?->name,
                ]),
                'invoices' => $contract->invoices->map(fn ($i) => [
                    'id' => $i->id,
                    'numero' => $i->numero,
                    'estado' => $i->estado,
                    'total' => $i->total,
                    'fecha_emision' => $i->fecha_emision?->format('Y-m-d'),
                    'fecha_pago' => $i->fecha_pago?->format('Y-m-d'),
                ]),
            ],
        ]);
    }

    public function edit(Contract $contract): Response
    {
        return Inertia::render('Admin/Contracts/Edit', [
            'contract' => [
                'id' => $contract->id,
                'client_id' => $contract->client_id,
                'service_type_id' => $contract->service_type_id,
                'codigo' => $contract->codigo,
                'estado' => $contract->estado,
                'modalidad_pago' => $contract->modalidad_pago,
                'valor' => $contract->valor,
                'fecha_inicio' => $contract->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $contract->fecha_fin?->format('Y-m-d'),
                'notas' => $contract->notas,
            ],
            'estados' => self::ESTADOS,
            'modalidadesPago' => self::MODALIDADES_PAGO,
            'clients' => Client::orderBy('razon_social')->get(['id', 'razon_social', 'nit']),
            'serviceTypes' => ServiceType::where('es_activo', true)->orderBy('nombre')->get(['id', 'nombre', 'modalidad']),
        ]);
    }

    public function update(UpdateContractRequest $request, Contract $contract): RedirectResponse
    {
        $contract->update($request->validated());

        return redirect()
            ->route('admin.contracts.show', $contract)
            ->with('success', 'Contrato actualizado.');
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        if (! request()->user()->can('contracts.delete')) {
            abort(403);
        }

        $contract->delete();

        return redirect()
            ->route('admin.contracts.index')
            ->with('success', 'Contrato eliminado.');
    }

    private function suggestCode(): string
    {
        $year = now()->year;
        $count = Contract::whereYear('created_at', $year)->withTrashed()->count() + 1;

        return sprintf('CTR-%d-%04d', $year, $count);
    }
}
