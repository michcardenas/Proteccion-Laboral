<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Process;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VisitController extends Controller
{
    /**
     * Registra una visita del abogado al cliente dentro de un proceso.
     * Puede adjuntar un acta/soporte (archivo) y marcar asistentes.
     */
    public function store(Request $request, Process $process): RedirectResponse
    {
        $this->authorizeProcessAccess($request, $process);

        $data = $request->validate([
            'tipo' => ['required', Rule::in(Visit::TIPOS)],
            'fecha' => ['required', 'date'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'visible_cliente' => ['sometimes', 'boolean'],
            'asistentes' => ['sometimes', 'array'],
            'asistentes.*' => ['integer', 'exists:users,id'],
            'acta' => ['nullable', 'file', 'max:20480'], // 20 MB
        ]);

        $visit = Visit::create([
            'process_id' => $process->id,
            'client_id' => $process->client_id,
            'registrada_por' => $request->user()?->id,
            'tipo' => $data['tipo'],
            'fecha' => $data['fecha'],
            'titulo' => $data['titulo'],
            'descripcion' => $data['descripcion'] ?? null,
            'visible_cliente' => $data['visible_cliente'] ?? true,
        ]);

        // Asistentes: por defecto, quien registra; o los seleccionados.
        $asistentes = $data['asistentes'] ?? [$request->user()?->id];
        $visit->asistentes()->sync(array_filter($asistentes));

        // Acta opcional: se guarda como Document ligado a la visita y al proceso.
        if ($request->hasFile('acta')) {
            $this->storeActa($request, $process, $visit);
        }

        return back()->with('success', 'Visita registrada.');
    }

    /**
     * Actualiza una visita.
     */
    public function update(Request $request, Process $process, Visit $visit): RedirectResponse
    {
        $this->authorizeProcessAccess($request, $process);
        abort_unless($visit->process_id === $process->id, 404);

        $data = $request->validate([
            'tipo' => ['required', Rule::in(Visit::TIPOS)],
            'fecha' => ['required', 'date'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'visible_cliente' => ['sometimes', 'boolean'],
            'asistentes' => ['sometimes', 'array'],
            'asistentes.*' => ['integer', 'exists:users,id'],
        ]);

        $visit->update([
            'tipo' => $data['tipo'],
            'fecha' => $data['fecha'],
            'titulo' => $data['titulo'],
            'descripcion' => $data['descripcion'] ?? null,
            'visible_cliente' => $data['visible_cliente'] ?? $visit->visible_cliente,
        ]);

        if (array_key_exists('asistentes', $data)) {
            $visit->asistentes()->sync(array_filter($data['asistentes']));
        }

        return back()->with('success', 'Visita actualizada.');
    }

    /**
     * Elimina una visita (soft delete).
     */
    public function destroy(Request $request, Process $process, Visit $visit): RedirectResponse
    {
        $this->authorizeProcessAccess($request, $process);
        abort_unless($visit->process_id === $process->id, 404);

        $visit->delete();

        return back()->with('success', 'Visita eliminada.');
    }

    /**
     * Guarda el archivo del acta como Document del proceso y de la visita.
     */
    private function storeActa(Request $request, Process $process, Visit $visit): void
    {
        $file = $request->file('acta');
        $ruta = $file->store("visits/process_{$process->id}", 'local');

        Document::create([
            'process_id' => $process->id,
            'client_id' => $process->client_id,
            'visit_id' => $visit->id,
            'nombre' => $file->getClientOriginalName() ?: ('Acta visita '.Str::limit($visit->titulo, 40)),
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => 'soporte',
            'mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'generado_por_ia' => false,
            'subido_por' => $request->user()?->id,
            'visible_cliente' => $visit->visible_cliente,
        ]);
    }

    /**
     * Aborta con 403 si el usuario tiene visibilidad restringida y el proceso
     * no es suyo. Mismo criterio que el resto del módulo admin.
     */
    private function authorizeProcessAccess(Request $request, Process $process): void
    {
        $user = $request->user();

        if ($user->can('processes.view') || ! $user->can('processes.view_assigned')) {
            // Ve todo, o su acceso lo gobierna otro permiso. Igual exige el permiso de gestión.
            abort_unless($user->can('visits.manage') || $user->can('processes.update'), 403);

            return;
        }

        $esMio = $process->abogado_lider_id === $user->id
            || $process->apoderado_id === $user->id
            || $process->coordinador_id === $user->id;

        abort_unless($esMio, 403);
    }
}
