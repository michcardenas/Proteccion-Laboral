<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChecklistResponse;
use App\Models\Process;
use App\Models\ProcessStage;
use App\Services\ProcessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProcessStageController extends Controller
{
    public function __construct(private readonly ProcessService $processService)
    {
    }

    public function update(Request $request, Process $process, ProcessStage $stage): RedirectResponse
    {
        abort_unless($stage->process_id === $process->id, 404);

        if (! $request->user()->can('stages.update')) {
            abort(403);
        }

        $data = $request->validate([
            'estado' => ['nullable', 'in:pendiente,en_curso,bloqueada,completada'],
            'responsable_id' => ['nullable', 'exists:users,id'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_limite' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ]);

        $stage->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Etapa actualizada.');
    }

    public function complete(Request $request, Process $process, ProcessStage $stage): RedirectResponse
    {
        abort_unless($stage->process_id === $process->id, 404);

        if (! $request->user()->can('stages.complete')) {
            abort(403);
        }

        $unchecked = $stage->checklistResponses()
            ->where('es_obligatorio', true)
            ->where('completado', false)
            ->count();

        if ($unchecked > 0) {
            return back()->with('error', "Aún hay {$unchecked} ítem(s) obligatorio(s) sin completar en esta etapa.");
        }

        $this->processService->completeStage($stage);

        return back()->with('success', 'Etapa completada. Avanzando a la siguiente.');
    }

    public function reopen(Request $request, Process $process, ProcessStage $stage): RedirectResponse
    {
        abort_unless($stage->process_id === $process->id, 404);

        if (! $request->user()->can('stages.update')) {
            abort(403);
        }

        $stage->update([
            'estado' => 'en_curso',
            'fecha_completada' => null,
        ]);

        return back()->with('success', 'Etapa reabierta.');
    }

    public function toggleChecklistItem(Request $request, Process $process, ProcessStage $stage, ChecklistResponse $response): RedirectResponse
    {
        abort_unless($stage->process_id === $process->id, 404);
        abort_unless($response->process_stage_id === $stage->id, 404);

        if (! $request->user()->can('stages.update')) {
            abort(403);
        }

        $isCompleting = ! $response->completado;

        $response->update([
            'completado' => $isCompleting,
            'completado_por' => $isCompleting ? $request->user()->id : null,
            'completado_at' => $isCompleting ? now() : null,
        ]);

        if ($isCompleting && $stage->estado === 'pendiente') {
            $stage->update([
                'estado' => 'en_curso',
                'fecha_inicio' => $stage->fecha_inicio ?: now(),
            ]);
        }

        return back();
    }
}
