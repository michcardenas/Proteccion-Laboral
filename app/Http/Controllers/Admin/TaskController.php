<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Process;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public const ESTADOS = ['pendiente', 'en_curso', 'bloqueada', 'completada', 'cancelada'];

    public const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];

    /**
     * Tablero Kanban. El selector de procesos del front filtra; entrar por
     * `processes/{process}/board` solo lo deja preseleccionado.
     *
     * Visibilidad: con permiso `processes.view` se ven las tareas de todos los
     * procesos; si el usuario solo tiene `processes.view_assigned`, se limita a
     * las tareas asignadas a él MÁS las de los procesos en los que está asignado
     * (líder, apoderado o coordinador).
     */
    public function board(Request $request, ?Process $process = null): Response
    {
        $user = $request->user();
        $restringido = ! $user->can('processes.view') && $user->can('processes.view_assigned');

        // Cierre reutilizable: restringe una consulta de Process a los del usuario.
        $soloMisProcesos = fn ($q) => $q->where(function ($qq) use ($user) {
            $qq->where('abogado_lider_id', $user->id)
               ->orWhere('apoderado_id', $user->id)
               ->orWhere('coordinador_id', $user->id);
        });

        // Tareas visibles = asignadas a mí  OR  de un proceso que lidero/apodero/coordino.
        $tareasVisibles = fn ($q) => $q->where(function ($qq) use ($user, $soloMisProcesos) {
            $qq->where('asignado_a', $user->id)
               ->orWhereHas('process', $soloMisProcesos);
        });

        // No preseleccionar un proceso al que el usuario restringido no tiene acceso
        // (ni lo lidera ni tiene tareas suyas en él).
        if ($restringido && $process && ! $this->canAccessProcess($user, $process)) {
            $process = null;
        }

        $tasks = Task::query()
            ->with(['process:id,codigo,titulo', 'asignado:id,name'])
            ->when($restringido, $tareasVisibles)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'titulo' => $t->titulo,
                'estado' => $t->estado,
                'prioridad' => $t->prioridad,
                'fecha_limite' => $t->fecha_limite?->format('Y-m-d'),
                'asignado' => $t->asignado?->name,
                'process' => $t->process ? [
                    'id' => $t->process->id,
                    'codigo' => $t->process->codigo,
                    'titulo' => $t->process->titulo,
                ] : null,
            ]);

        return Inertia::render('Admin/Tasks/Board', [
            'tasks' => $tasks,
            'estados' => self::ESTADOS,
            'prioridades' => self::PRIORIDADES,
            'initialProcessId' => $process?->id,
            // Selector: mismos procesos que aparecen en las tareas visibles (los que
            // lidero/apodero/coordino + aquellos donde tengo alguna tarea asignada).
            'processes' => Process::query()
                ->when($restringido, fn ($q) => $q->where(function ($qq) use ($user, $soloMisProcesos) {
                    $soloMisProcesos($qq)->orWhereHas('tasks', fn ($t) => $t->where('asignado_a', $user->id));
                }))
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'titulo']),
            'assignees' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'googlePicker' => [
                'enabled' => filled(config('services.google.picker_client_id')) && filled(config('services.google.picker_api_key')),
                'client_id' => config('services.google.picker_client_id'),
                'api_key' => config('services.google.picker_api_key'),
                'app_id' => config('services.google.picker_app_id'),
                'scope' => config('services.google.picker_scope'),
            ],
        ]);
    }

    /**
     * Detalle de una tarea para el panel lateral, con el resumen IA del correo
     * de origen del proceso (si existe).
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        $task->load([
            'process:id,codigo,titulo,resumen_ia,resumen_ia_generado_at,abogado_lider_id,apoderado_id,coordinador_id',
            'asignado:id,name',
            'creador:id,name',
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
            'documents' => fn ($q) => $q->with('uploader:id,name')->latest(),
        ]);

        $this->authorizeTaskAccess($request, $task);

        return response()->json([
            'id' => $task->id,
            'titulo' => $task->titulo,
            'descripcion' => $task->descripcion,
            'estado' => $task->estado,
            'prioridad' => $task->prioridad,
            'fecha_limite' => $task->fecha_limite?->format('Y-m-d'),
            'completada_at' => $task->completada_at?->toIso8601String(),
            'asignado' => $task->asignado?->name,
            'creador' => $task->creador?->name,
            'process' => $task->process ? [
                'id' => $task->process->id,
                'codigo' => $task->process->codigo,
                'titulo' => $task->process->titulo,
            ] : null,
            'comments' => $task->comments->map(fn ($c) => [
                'id' => $c->id,
                'body' => $c->body,
                'user' => $c->user?->name,
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'documents' => $task->documents->map(fn (Document $d) => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                // Siempre vía el endpoint de descarga: sirve los archivos locales
                // (adjuntos de correo, borradores IA) y redirige a Drive cuando aplica.
                'url' => route('admin.documents.download', $d->id),
                'disco' => $d->disco,
                'mime' => $d->mime,
                'subido_por' => $d->uploader?->name,
                'created_at' => $d->created_at?->toIso8601String(),
            ]),
            'ai' => $this->resolveAiSummary($task),
        ]);
    }

    /**
     * Adjunta un documento de Google Drive (enlace) a la tarea.
     */
    public function storeAttachment(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'url' => ['required', 'url', 'max:500'],
            'mime' => ['nullable', 'string', 'max:120'],
        ]);

        $document = new Document();
        $document->task_id = $task->id;
        $document->process_id = $task->process_id;
        $document->nombre = $data['nombre'];
        $document->ruta = $data['url'];
        $document->disco = 'gdrive';
        $document->tipo = 'otro';
        $document->mime = $data['mime'] ?? null;
        $document->subido_por = $request->user()?->id;
        $document->save();

        return response()->json([
            'id' => $document->id,
            'nombre' => $document->nombre,
            'url' => route('admin.documents.download', $document->id),
            'disco' => $document->disco,
            'mime' => $document->mime,
            'subido_por' => $request->user()?->name,
            'created_at' => $document->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Quita un adjunto de la tarea (soft delete).
     */
    public function destroyAttachment(Request $request, Task $task, Document $document): JsonResponse
    {
        abort_unless($document->task_id === $task->id, 404);
        $this->authorizeTaskAccess($request, $task);

        $document->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Crea una nueva tarjeta desde el tablero Kanban. Queda registrada en el
     * historial del proceso vía LogsActivity (evento "created").
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'process_id' => ['required', 'exists:processes,id'],
            'process_stage_id' => ['nullable', 'exists:process_stages,id'],
            'asignado_a' => ['nullable', 'exists:users,id'],
            'prioridad' => ['required', Rule::in(self::PRIORIDADES)],
            'estado' => ['required', Rule::in(self::ESTADOS)],
            'fecha_limite' => ['nullable', 'date'],
        ]);

        // Un usuario restringido no puede crear tareas en procesos ajenos.
        $this->authorizeProcessAccess($request, Process::find($data['process_id']));

        $data['creado_por'] = $request->user()?->id;
        $data['completada_at'] = $data['estado'] === 'completada' ? now() : null;

        Task::create($data);

        return back()->with('success', 'Tarjeta creada.');
    }

    /**
     * Actualiza el estado de una tarea (al arrastrarla en el tablero).
     */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $validated = $request->validate([
            'estado' => ['required', 'string', Rule::in(self::ESTADOS)],
        ]);

        $task->estado = $validated['estado'];
        $task->completada_at = $validated['estado'] === 'completada' ? now() : null;
        $task->save();

        return back();
    }

    /**
     * ¿Tiene el usuario visibilidad restringida (solo `processes.view_assigned`,
     * sin `processes.view`)? Los demás (director, coordinador, etc.) ven todo.
     */
    private function visibilidadRestringida(\App\Models\User $user): bool
    {
        return ! $user->can('processes.view') && $user->can('processes.view_assigned');
    }

    /**
     * ¿Es el usuario líder, apoderado o coordinador de este proceso?
     */
    private function canAccessProcess(\App\Models\User $user, ?Process $process): bool
    {
        return $process
            && ($process->abogado_lider_id === $user->id
                || $process->apoderado_id === $user->id
                || $process->coordinador_id === $user->id);
    }

    /**
     * Autoriza una acción sobre una TAREA concreta. Para un usuario restringido,
     * exige que la tarea esté asignada a él O que el proceso sea suyo — la misma
     * unión que decide qué ve en el tablero. Evita que el filtrado se salte
     * pidiendo una tarea ajena por su id.
     */
    private function authorizeTaskAccess(Request $request, Task $task): void
    {
        $user = $request->user();
        if (! $this->visibilidadRestringida($user)) {
            return;
        }

        $accesible = $task->asignado_a === $user->id
            || $this->canAccessProcess($user, $task->loadMissing('process')->process);

        abort_unless($accesible, 403);
    }

    /**
     * Autoriza CREAR una tarea en un proceso. Un usuario restringido solo puede
     * crear tarjetas en procesos que lidera/apodera/coordina.
     */
    private function authorizeProcessAccess(Request $request, ?Process $process): void
    {
        $user = $request->user();
        if (! $this->visibilidadRestringida($user)) {
            return;
        }

        abort_unless($this->canAccessProcess($user, $process), 403);
    }

    /**
     * Resumen IA del PROCESO al que pertenece la tarea (no del correo ni de la tarea).
     * Se genera y persiste desde la ficha del proceso (processes.resumen_ia).
     */
    private function resolveAiSummary(Task $task): ?array
    {
        $process = $task->process;

        if (! $process || ! $process->resumen_ia) {
            return null;
        }

        return [
            'summary' => $process->resumen_ia,
            'generado_at' => $process->resumen_ia_generado_at?->toIso8601String(),
        ];
    }
}
