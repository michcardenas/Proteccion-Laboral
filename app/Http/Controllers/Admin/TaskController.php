<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

        // Tareas visibles = las de un proceso en el que el usuario está asignado
        // (líder, apoderado o coordinador).
        $tareasVisibles = fn ($q) => $q->whereHas('process', $soloMisProcesos);

        // No preseleccionar un proceso al que el usuario restringido no tiene acceso.
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

        // Correos del proceso para la columna "Bandeja" del tablero. Respeta la
        // misma restricción de visibilidad que las tareas.
        $emailsQuery = EmailIngestion::query()
            ->whereNotNull('process_id')
            ->with('process:id,codigo,titulo')
            ->latest('received_at');

        if ($restringido) {
            $emailsQuery->whereHas('process', $soloMisProcesos);
        }

        $emails = $emailsQuery->get()->map(fn (EmailIngestion $e) => [
            'id' => $e->id,
            'from' => $e->from,
            'subject' => $e->subject ?: '(sin asunto)',
            'received_at' => $e->received_at?->toIso8601String(),
            'status' => $e->status,
            'respondido' => $e->respondido_at !== null,
            'body_preview' => Str::limit((string) $e->body_text, 400),
            'process' => $e->process ? [
                'id' => $e->process->id,
                'codigo' => $e->process->codigo,
                'titulo' => $e->process->titulo,
            ] : null,
        ]);

        return Inertia::render('Admin/Tasks/Board', [
            'tasks' => $tasks,
            'emails' => $emails,
            'estados' => self::ESTADOS,
            'prioridades' => self::PRIORIDADES,
            'initialProcessId' => $process?->id,
            // Selector: solo los procesos en los que el usuario está asignado
            // (líder, apoderado o coordinador).
            'processes' => Process::query()
                ->when($restringido, $soloMisProcesos)
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
            'emailIngestions' => fn ($q) => $q->latest('received_at'),
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
            'asignado_a' => $task->asignado_a,
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
            // Documentos del proceso que aún no están en ninguna tarjeta (los que la
            // IA importó del correo, borradores, etc.) — disponibles para adjuntar.
            'processDocuments' => $this->availableProcessDocuments($task),
            // Correos adjuntos a la tarjeta (contexto) + correos del proceso disponibles.
            'emails' => $task->emailIngestions->map(fn (EmailIngestion $e) => $this->mapEmail($e)),
            'processEmails' => $this->availableProcessEmails($task),
            'ai' => $this->resolveAiSummary($task),
        ]);
    }

    /**
     * Forma compacta de un correo ingestado para el panel de la tarjeta.
     */
    private function mapEmail(EmailIngestion $email): array
    {
        return [
            'id' => $email->id,
            'from' => $email->from,
            'subject' => $email->subject ?: '(sin asunto)',
            'received_at' => $email->received_at?->toIso8601String(),
            'preview' => Str::limit((string) $email->body_text, 220),
        ];
    }

    /**
     * Correos del proceso de la tarea que todavía NO están adjuntos a esta tarjeta,
     * para ofrecerlos como adjuntables (contexto para quien la ejecuta).
     *
     * @return Collection<int, array>
     */
    private function availableProcessEmails(Task $task)
    {
        if (! $task->process_id) {
            return collect();
        }

        $yaAdjuntos = $task->emailIngestions->pluck('id');

        return EmailIngestion::query()
            ->where('process_id', $task->process_id)
            ->whereNotIn('id', $yaAdjuntos)
            ->latest('received_at')
            ->get()
            ->map(fn (EmailIngestion $e) => $this->mapEmail($e));
    }

    /**
     * Adjunta a la tarjeta un correo ingestado que pertenece al mismo proceso.
     */
    public function attachEmail(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $data = $request->validate([
            'email_ingestion_id' => ['required', 'integer', 'exists:email_ingestions,id'],
        ]);

        $email = EmailIngestion::query()
            ->where('id', $data['email_ingestion_id'])
            ->where('process_id', $task->process_id) // solo correos del mismo proceso
            ->firstOrFail();

        $task->emailIngestions()->syncWithoutDetaching([
            $email->id => ['attached_by' => $request->user()?->id],
        ]);

        return response()->json($this->mapEmail($email), 201);
    }

    /**
     * Quita un correo adjunto de la tarjeta (no borra el correo, solo el vínculo).
     */
    public function detachEmail(Request $request, Task $task, EmailIngestion $ingestion): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $task->emailIngestions()->detach($ingestion->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Documentos del proceso de la tarea que todavía no están vinculados a una
     * tarjeta (task_id null), para ofrecerlos como adjuntables.
     *
     * @return Collection<int, array>
     */
    private function availableProcessDocuments(Task $task)
    {
        if (! $task->process_id) {
            return collect();
        }

        return Document::query()
            ->where('process_id', $task->process_id)
            ->whereNull('task_id')
            ->latest()
            ->get()
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'mime' => $d->mime,
                'tipo' => $d->tipo,
                'generado_por_ia' => (bool) $d->generado_por_ia,
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

        $document = new Document;
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
     * Vincula a la tarjeta un documento que ya pertenece al proceso (p. ej. uno
     * que la IA importó del correo). El documento "se mueve": se le asigna el
     * task_id, dejando de aparecer como documento general del proceso.
     */
    public function attachProcessDocument(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:documents,id'],
        ]);

        $document = Document::query()
            ->where('id', $data['document_id'])
            ->where('process_id', $task->process_id) // solo documentos del mismo proceso
            ->firstOrFail();

        $document->task_id = $task->id;
        $document->save();

        $document->loadMissing('uploader:id,name');

        return response()->json([
            'id' => $document->id,
            'nombre' => $document->nombre,
            'url' => route('admin.documents.download', $document->id),
            'disco' => $document->disco,
            'mime' => $document->mime,
            'subido_por' => $document->uploader?->name,
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
     * Actualiza una tarea. El arrastre en el tablero manda solo `estado` (Inertia,
     * responde redirect). El panel de detalle puede editar asignado/prioridad/fecha
     * límite (pide JSON, responde la tarea actualizada). Todos los campos opcionales.
     */
    public function update(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $validated = $request->validate([
            'estado' => ['sometimes', 'required', 'string', Rule::in(self::ESTADOS)],
            'asignado_a' => ['sometimes', 'nullable', 'exists:users,id'],
            'prioridad' => ['sometimes', 'required', Rule::in(self::PRIORIDADES)],
            'fecha_limite' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('estado', $validated)) {
            $task->estado = $validated['estado'];
            $task->completada_at = $validated['estado'] === 'completada' ? now() : null;
        }
        foreach (['asignado_a', 'prioridad', 'fecha_limite'] as $campo) {
            if (array_key_exists($campo, $validated)) {
                $task->{$campo} = $validated[$campo];
            }
        }
        $task->save();

        if ($request->wantsJson()) {
            $task->loadMissing('asignado:id,name');

            return response()->json([
                'id' => $task->id,
                'estado' => $task->estado,
                'prioridad' => $task->prioridad,
                'fecha_limite' => $task->fecha_limite?->format('Y-m-d'),
                'asignado' => $task->asignado?->name,
                'asignado_a' => $task->asignado_a,
                'completada_at' => $task->completada_at?->toIso8601String(),
            ]);
        }

        return back();
    }

    /**
     * ¿Tiene el usuario visibilidad restringida (solo `processes.view_assigned`,
     * sin `processes.view`)? Los demás (director, coordinador, etc.) ven todo.
     */
    private function visibilidadRestringida(User $user): bool
    {
        return ! $user->can('processes.view') && $user->can('processes.view_assigned');
    }

    /**
     * ¿Es el usuario líder, apoderado o coordinador de este proceso?
     */
    private function canAccessProcess(User $user, ?Process $process): bool
    {
        return $process
            && ($process->abogado_lider_id === $user->id
                || $process->apoderado_id === $user->id
                || $process->coordinador_id === $user->id);
    }

    /**
     * Autoriza una acción sobre una TAREA concreta. Para un usuario restringido,
     * exige que el proceso de la tarea sea suyo (líder, apoderado o coordinador)
     * — mismo criterio que decide qué ve en el tablero. Evita que el filtrado se
     * salte pidiendo una tarea ajena por su id.
     */
    private function authorizeTaskAccess(Request $request, Task $task): void
    {
        $user = $request->user();
        if (! $this->visibilidadRestringida($user)) {
            return;
        }

        abort_unless($this->canAccessProcess($user, $task->loadMissing('process')->process), 403);
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
