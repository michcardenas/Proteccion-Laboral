<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProcessRequest;
use App\Http\Requests\Admin\UpdateProcessRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\Task;
use App\Models\User;
use App\Models\Visit;
use App\Services\ProcessService;
use App\Services\ProcessSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ProcessController extends Controller
{
    public const ESTADOS = ['abierto', 'en_curso', 'en_revision', 'cerrado', 'archivado'];

    public function __construct(private readonly ProcessService $processService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Process::query()
            ->with([
                'client:id,razon_social,nit',
                'serviceType:id,nombre,modalidad',
                'abogadoLider:id,name',
                'apoderado:id,name',
            ])
            ->withCount(['stages', 'tasks'])
            ->withCount(['stages as completed_stages_count' => fn ($q) => $q->where('estado', 'completada')])
            ->latest('fecha_apertura');

        if (! $user->can('processes.view') && $user->can('processes.view_assigned')) {
            $query->where(function ($q) use ($user) {
                $q->where('abogado_lider_id', $user->id)
                    ->orWhere('apoderado_id', $user->id)
                    ->orWhere('coordinador_id', $user->id);
            });
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', "%{$search}%")
                    ->orWhere('titulo', 'like', "%{$search}%")
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

        $processes = $query->paginate(15)->withQueryString()->through(fn (Process $p) => [
            'id' => $p->id,
            'codigo' => $p->codigo,
            'titulo' => $p->titulo,
            'estado' => $p->estado,
            'fecha_apertura' => $p->fecha_apertura?->format('Y-m-d'),
            'fecha_cierre' => $p->fecha_cierre?->format('Y-m-d'),
            'client' => $p->client ? ['id' => $p->client->id, 'razon_social' => $p->client->razon_social] : null,
            'service' => $p->serviceType ? ['nombre' => $p->serviceType->nombre, 'modalidad' => $p->serviceType->modalidad] : null,
            'lider' => $p->abogadoLider?->name,
            'apoderado' => $p->apoderado?->name,
            'stages_count' => $p->stages_count,
            'completed_stages_count' => $p->completed_stages_count,
            'tasks_count' => $p->tasks_count,
        ]);

        $totals = [
            'count' => Process::count(),
            'abiertos' => Process::whereIn('estado', ['abierto', 'en_curso', 'en_revision'])->count(),
            'cerrados' => Process::whereIn('estado', ['cerrado', 'archivado'])->count(),
        ];

        return Inertia::render('Admin/Processes/Index', [
            'processes' => $processes,
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
        return Inertia::render('Admin/Processes/Create', [
            'estados' => self::ESTADOS,
            'clients' => Client::orderBy('razon_social')->get(['id', 'razon_social', 'nit']),
            'serviceTypes' => ServiceType::with('stageTemplates:id,service_type_id,orden,nombre')
                ->where('es_activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'modalidad']),
            'contracts' => Contract::with('client:id,razon_social')
                ->whereIn('estado', ['borrador', 'activo'])
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'client_id', 'service_type_id']),
            'staff' => $this->staffOptions(),
            'preselectClientId' => $request->integer('client_id') ?: null,
            'preselectContractId' => $request->integer('contract_id') ?: null,
            'suggestedCode' => $this->suggestCode(),
        ]);
    }

    public function store(StoreProcessRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['codigo'] = $data['codigo'] ?: $this->suggestCode();

        $process = $this->processService->createFromTemplate($data);

        return redirect()
            ->route('admin.processes.show', $process)
            ->with('success', 'Proceso creado. Se clonaron '.$process->stages->count().' etapas desde la plantilla del servicio.');
    }

    public function show(Process $process): Response
    {
        $process->load([
            'client:id,razon_social,nit,dv,ciudad,sector,estado',
            'serviceType:id,nombre,modalidad,descripcion',
            'contract:id,codigo,estado,modalidad_pago,valor',
            'abogadoLider:id,name,email',
            'apoderado:id,name,email',
            'coordinador:id,name,email',
            'stages' => fn ($q) => $q->with(['responsable:id,name', 'checklistResponses.completador:id,name'])->orderBy('orden'),
            'tasks' => fn ($q) => $q->with('asignado:id,name')->latest(),
            'documents' => fn ($q) => $q->with('uploader:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
            'visits' => fn ($q) => $q->with(['registradaPor:id,name', 'asistentes:id,name', 'documents'])->orderByDesc('fecha'),
            'emailIngestions' => fn ($q) => $q->orderByDesc('received_at'),
            'payments' => fn ($q) => $q->with(['registradoPor:id,name', 'documents'])->orderByDesc('fecha_pago'),
        ]);

        $totalChecklist = $process->stages->sum(fn ($s) => $s->checklistResponses->count());
        $completedChecklist = $process->stages->sum(fn ($s) => $s->checklistResponses->where('completado', true)->count());

        // Historial = actividad del proceso + actividad de sus tarjetas del Kanban.
        $processActivities = $process->activities()
            ->with('causer:id,name')
            ->get()
            ->map(fn ($a) => ['tipo' => 'proceso', 'act' => $a]);

        $taskActivities = collect();
        if ($process->tasks->isNotEmpty()) {
            $taskActivities = Activity::query()
                ->where('subject_type', (new Task)->getMorphClass())
                ->whereIn('subject_id', $process->tasks->pluck('id'))
                ->with('causer:id,name')
                ->get()
                ->map(fn ($a) => ['tipo' => 'tarea', 'act' => $a]);
        }

        $historial = $processActivities->concat($taskActivities)
            // Más reciente primero; el id desempata eventos del mismo segundo.
            ->sortByDesc(fn ($x) => [$x['act']->created_at?->getTimestamp() ?? 0, $x['act']->id])
            ->values();

        // Resolver IDs de usuario (líder/apoderado/asignado) a nombres legibles.
        $userIds = $historial->flatMap(function ($x) {
            $attrs = $x['act']->properties->get('attributes', []);
            $old = $x['act']->properties->get('old', []);

            return collect(['abogado_lider_id', 'apoderado_id', 'asignado_a'])
                ->flatMap(fn ($f) => [$attrs[$f] ?? null, $old[$f] ?? null]);
        })->filter()->unique()->values();

        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');
        $tasksById = $process->tasks->keyBy('id');

        return Inertia::render('Admin/Processes/Show', [
            'process' => [
                'id' => $process->id,
                'codigo' => $process->codigo,
                'titulo' => $process->titulo,
                'descripcion' => $process->descripcion,
                'resumen_ia' => $process->resumen_ia,
                'resumen_ia_generado_at' => $process->resumen_ia_generado_at?->toIso8601String(),
                'estado' => $process->estado,
                'fecha_apertura' => $process->fecha_apertura?->format('Y-m-d'),
                'fecha_cierre' => $process->fecha_cierre?->format('Y-m-d'),
                'client' => $process->client ? [
                    'id' => $process->client->id,
                    'razon_social' => $process->client->razon_social,
                    'nit' => $process->client->nit,
                ] : null,
                'service' => $process->serviceType ? [
                    'nombre' => $process->serviceType->nombre,
                    'modalidad' => $process->serviceType->modalidad,
                    'descripcion' => $process->serviceType->descripcion,
                ] : null,
                'contract' => $process->contract ? [
                    'id' => $process->contract->id,
                    'codigo' => $process->contract->codigo,
                    'estado' => $process->contract->estado,
                ] : null,
                'lider' => $process->abogadoLider ? ['id' => $process->abogadoLider->id, 'name' => $process->abogadoLider->name, 'email' => $process->abogadoLider->email] : null,
                'apoderado' => $process->apoderado ? ['id' => $process->apoderado->id, 'name' => $process->apoderado->name, 'email' => $process->apoderado->email] : null,
                'coordinador' => $process->coordinador ? ['id' => $process->coordinador->id, 'name' => $process->coordinador->name, 'email' => $process->coordinador->email] : null,
                'stages' => $process->stages->map(fn ($s) => [
                    'id' => $s->id,
                    'orden' => $s->orden,
                    'nombre' => $s->nombre,
                    'estado' => $s->estado,
                    'fecha_inicio' => $s->fecha_inicio?->format('Y-m-d'),
                    'fecha_limite' => $s->fecha_limite?->format('Y-m-d'),
                    'fecha_completada' => $s->fecha_completada?->toIso8601String(),
                    'responsable' => $s->responsable?->name,
                    'checklist' => $s->checklistResponses->map(fn ($r) => [
                        'id' => $r->id,
                        'descripcion' => $r->descripcion,
                        'es_obligatorio' => $r->es_obligatorio,
                        'completado' => $r->completado,
                        'completado_por' => $r->completador?->name,
                        'completado_at' => $r->completado_at?->toIso8601String(),
                    ]),
                ]),
                'tasks' => $process->tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'titulo' => $t->titulo,
                    'estado' => $t->estado,
                    'prioridad' => $t->prioridad,
                    'fecha_limite' => $t->fecha_limite?->format('Y-m-d'),
                    'asignado' => $t->asignado?->name,
                ]),
                'documents' => $process->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'tipo' => $d->tipo,
                    'mime' => $d->mime,
                    'url' => route('admin.documents.download', $d->id),
                    'generado_por_ia' => (bool) $d->generado_por_ia,
                    'visible_cliente' => (bool) $d->visible_cliente,
                    'subido_por' => $d->uploader?->name,
                    'created_at' => $d->created_at?->toIso8601String(),
                ]),
                'comments' => $process->comments->map(fn ($c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'visible_cliente' => (bool) $c->visible_cliente,
                    'user' => $c->user?->name,
                    'created_at' => $c->created_at?->toIso8601String(),
                ]),
                'visits' => $process->visits->map(fn ($v) => [
                    'id' => $v->id,
                    'tipo' => $v->tipo,
                    'fecha' => $v->fecha?->format('Y-m-d'),
                    'titulo' => $v->titulo,
                    'descripcion' => $v->descripcion,
                    'visible_cliente' => (bool) $v->visible_cliente,
                    'registrada_por' => $v->registradaPor?->name,
                    'asistentes' => $v->asistentes->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
                    'documentos' => $v->documents->map(fn ($d) => [
                        'id' => $d->id,
                        'nombre' => $d->nombre,
                        'url' => route('admin.documents.download', $d->id),
                    ]),
                    'created_at' => $v->created_at?->toIso8601String(),
                ]),
                // Correos del proceso (ingestados desde Gmail) para responder desde la ficha.
                'correos' => $process->emailIngestions->map(fn ($e) => [
                    'id' => $e->id,
                    'from' => $e->from,
                    'to' => $e->to,
                    'subject' => $e->subject,
                    'body_preview' => Str::limit($e->body_text ?? '', 280),
                    'body_text' => $e->body_text,
                    'status' => $e->status,
                    'received_at' => $e->received_at?->toIso8601String(),
                    'puede_responder' => ! empty($e->from),
                ]),
                'pagos' => $process->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'monto' => (float) $p->monto,
                    'fecha_pago' => $p->fecha_pago?->format('Y-m-d'),
                    'concepto' => $p->concepto,
                    'metodo' => $p->metodo,
                    'referencia' => $p->referencia,
                    'notas' => $p->notas,
                    'registrado_por' => $p->registradoPor?->name,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'documentos' => $p->documents->map(fn ($d) => [
                        'id' => $d->id,
                        'nombre' => $d->nombre,
                        'mime' => $d->mime,
                        'tamano_bytes' => $d->tamano_bytes,
                    ])->values(),
                ]),
                'pagos_total' => (float) $process->payments->sum('monto'),
                'progress' => [
                    'total' => $totalChecklist,
                    'completed' => $completedChecklist,
                    'percent' => $totalChecklist > 0 ? round(($completedChecklist / $totalChecklist) * 100) : 0,
                ],
                'historial' => $historial->map(fn ($x) => [
                    'id' => $x['tipo'].'-'.$x['act']->id,
                    'tipo' => $x['tipo'],
                    'evento' => $x['act']->event,
                    'descripcion' => $x['act']->description,
                    'objeto' => $x['tipo'] === 'tarea'
                        ? ($x['act']->properties->get('attributes', [])['titulo'] ?? $tasksById->get($x['act']->subject_id)?->titulo)
                        : null,
                    'causer' => $x['act']->causer?->name,
                    'cambios' => $this->formatActivityChanges($x['act'], $userNames),
                    'created_at' => $x['act']->created_at?->toIso8601String(),
                ]),
            ],
            'aiTemplates' => AiGenerationController::ALLOWED_TEMPLATES,
            'staff' => $this->staffOptions(),
            'visitTipos' => Visit::TIPOS,
            'paymentMetodos' => Payment::METODOS,
        ]);
    }

    /**
     * Convierte las propiedades de una actividad en una lista legible de cambios
     * (campo, valor anterior, valor nuevo), resolviendo IDs de usuario a nombres.
     */
    private function formatActivityChanges($activity, $userNames): array
    {
        $labels = [
            'codigo' => 'Código',
            'titulo' => 'Título',
            'estado' => 'Estado',
            'abogado_lider_id' => 'Abogado líder',
            'apoderado_id' => 'Apoderado',
            'fecha_cierre' => 'Fecha de cierre',
            'prioridad' => 'Prioridad',
            'asignado_a' => 'Asignado a',
            'fecha_limite' => 'Fecha límite',
        ];

        $userFields = ['abogado_lider_id', 'apoderado_id', 'asignado_a'];
        $new = $activity->properties->get('attributes', []);
        $old = $activity->properties->get('old', []);

        $format = function ($field, $value) use ($userFields, $userNames) {
            if ($value === null || $value === '') {
                return null;
            }

            return in_array($field, $userFields, true)
                ? ($userNames[$value] ?? "Usuario #{$value}")
                : (string) $value;
        };

        $changes = [];
        foreach ($new as $field => $value) {
            $changes[] = [
                'campo' => $labels[$field] ?? $field,
                'antes' => $format($field, $old[$field] ?? null),
                'despues' => $format($field, $value),
            ];
        }

        return $changes;
    }

    public function edit(Process $process): Response
    {
        return Inertia::render('Admin/Processes/Edit', [
            'process' => [
                'id' => $process->id,
                'client_id' => $process->client_id,
                'service_type_id' => $process->service_type_id,
                'contract_id' => $process->contract_id,
                'codigo' => $process->codigo,
                'titulo' => $process->titulo,
                'descripcion' => $process->descripcion,
                'estado' => $process->estado,
                'fecha_apertura' => $process->fecha_apertura?->format('Y-m-d'),
                'fecha_cierre' => $process->fecha_cierre?->format('Y-m-d'),
                'abogado_lider_id' => $process->abogado_lider_id,
                'apoderado_id' => $process->apoderado_id,
                'coordinador_id' => $process->coordinador_id,
            ],
            'estados' => self::ESTADOS,
            'clients' => Client::orderBy('razon_social')->get(['id', 'razon_social']),
            'serviceTypes' => ServiceType::where('es_activo', true)->orderBy('nombre')->get(['id', 'nombre', 'modalidad']),
            'contracts' => Contract::orderBy('codigo')->get(['id', 'codigo', 'client_id']),
            'staff' => $this->staffOptions(),
        ]);
    }

    public function update(UpdateProcessRequest $request, Process $process): RedirectResponse
    {
        $process->update($request->validated());

        return redirect()
            ->route('admin.processes.show', $process)
            ->with('success', 'Proceso actualizado.');
    }

    public function destroy(Process $process): RedirectResponse
    {
        if (! request()->user()->can('processes.update')) {
            abort(403);
        }

        $process->delete();

        return redirect()
            ->route('admin.processes.index')
            ->with('success', 'Proceso archivado.');
    }

    /**
     * POST /admin/processes/{process}/ai/summary
     * Genera (o regenera) manualmente el resumen ejecutivo del proceso con IA y lo
     * persiste en `processes.resumen_ia`. Devuelve el texto y la marca de tiempo.
     *
     * El resumen también se genera automáticamente al crear un caso desde el
     * pipeline de correos entrantes (ver EmailRouter::handleNuevoCaso); este
     * endpoint cubre los procesos creados a mano o cuando se quiere refrescar.
     */
    public function generateSummary(Request $request, Process $process, ProcessSummaryService $summaries): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);

        // El resumen puede tardar varios segundos; subimos el límite solo para esta request.
        set_time_limit(120);

        try {
            $summaries->generate($process);

            return response()->json([
                'resumen_ia' => $process->resumen_ia,
                'resumen_ia_generado_at' => $process->resumen_ia_generado_at?->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'No se pudo generar el resumen del proceso.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 502);
        }
    }

    private function staffOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['director', 'coordinador', 'abogado_interno', 'abogado_externo', 'apoderado']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roles->first()?->name,
            ])
            ->toArray();
    }

    private function suggestCode(): string
    {
        $year = now()->year;
        $count = Process::whereYear('created_at', $year)->withTrashed()->count() + 1;

        return sprintf('PRC-%d-%04d', $year, $count);
    }
}
