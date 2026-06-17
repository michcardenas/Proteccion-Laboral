<?php

namespace App\Services;

use App\Models\ChecklistResponse;
use App\Models\Process;
use App\Models\ProcessStage;
use App\Models\ServiceChecklistItem;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;

class ProcessService
{
    /**
     * Crea un Process y clona automáticamente las ServiceStageTemplate
     * en ProcessStage, junto con los ChecklistItem en ChecklistResponse.
     */
    public function createFromTemplate(array $attributes): Process
    {
        return DB::transaction(function () use ($attributes) {
            /** @var Process $process */
            $process = Process::create($attributes);

            $serviceType = ServiceType::with(['stageTemplates.checklistItems', 'taskTemplates'])
                ->findOrFail($process->service_type_id);

            $serviceLevelChecklist = ServiceChecklistItem::query()
                ->where('service_type_id', $serviceType->id)
                ->whereNull('service_stage_template_id')
                ->orderBy('orden')
                ->get();

            foreach ($serviceType->stageTemplates as $template) {
                /** @var ProcessStage $stage */
                $stage = $process->stages()->create([
                    'service_stage_template_id' => $template->id,
                    'orden' => $template->orden,
                    'nombre' => $template->nombre,
                    'descripcion' => $template->descripcion,
                    'estado' => 'pendiente',
                    'fecha_limite' => $template->sla_dias
                        ? $process->fecha_apertura->copy()->addDays($template->sla_dias)
                        : null,
                ]);

                foreach ($template->checklistItems as $item) {
                    ChecklistResponse::create([
                        'process_stage_id' => $stage->id,
                        'checklist_item_id' => $item->id,
                        'descripcion' => $item->descripcion,
                        'es_obligatorio' => $item->es_obligatorio,
                    ]);
                }

                foreach ($serviceLevelChecklist as $item) {
                    if ($template->orden === $serviceType->stageTemplates->first()->orden) {
                        ChecklistResponse::create([
                            'process_stage_id' => $stage->id,
                            'checklist_item_id' => $item->id,
                            'descripcion' => $item->descripcion,
                            'es_obligatorio' => $item->es_obligatorio,
                        ]);
                    }
                }
            }

            // Tarjetas del tablero Kanban a partir de la rúbrica del servicio.
            foreach ($serviceType->taskTemplates as $taskTemplate) {
                $process->tasks()->create([
                    'titulo' => $taskTemplate->titulo,
                    'descripcion' => $taskTemplate->descripcion,
                    'prioridad' => $taskTemplate->prioridad,
                    'estado' => 'pendiente',
                    'creado_por' => auth()->id(),
                    'fecha_limite' => $taskTemplate->sla_dias
                        ? $process->fecha_apertura->copy()->addDays($taskTemplate->sla_dias)
                        : null,
                ]);
            }

            return $process->load(['stages.checklistResponses', 'tasks']);
        });
    }

    /**
     * Marca una etapa como completada y opcionalmente avanza a la siguiente.
     */
    public function completeStage(ProcessStage $stage, bool $advanceNext = true): ProcessStage
    {
        return DB::transaction(function () use ($stage, $advanceNext) {
            $stage->update([
                'estado' => 'completada',
                'fecha_completada' => now(),
            ]);

            if ($advanceNext) {
                $next = $stage->process->stages()
                    ->where('orden', '>', $stage->orden)
                    ->where('estado', 'pendiente')
                    ->orderBy('orden')
                    ->first();

                if ($next) {
                    $next->update([
                        'estado' => 'en_curso',
                        'fecha_inicio' => now(),
                    ]);
                }
            }

            return $stage->refresh();
        });
    }
}
