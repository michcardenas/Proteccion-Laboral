<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\ChecklistResponse;
use App\Models\Process;
use App\Models\ServiceChecklistItem;
use App\Models\ServiceStageTemplate;
use App\Models\ServiceTaskTemplate;
use App\Services\AiService;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Importación asistida por IA de un plan de trabajo / contrato: el abogado sube
 * el documento, la IA lo interpreta a una estructura editable y, tras la
 * confirmación, se vuelca en las etapas/entregables/tareas del proceso.
 */
class PlanImportController extends Controller
{
    private const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];

    public function __construct(
        private readonly AiService $ai,
        private readonly DocumentTextExtractor $extractor,
    ) {}

    /**
     * POST /admin/processes/{process}/plan/analyze
     * Sube el documento, extrae el texto, lo interpreta con IA y devuelve la
     * estructura propuesta (sin persistir cambios en el proceso).
     */
    public function analyze(Request $request, Process $process): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);

        // La interpretación con Claude puede tardar; subimos el límite de ejecución.
        set_time_limit(180);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,docx,txt,md', 'max:20480'], // 20 MB
        ]);

        try {
            $texto = $this->extractor->extract($request->file('archivo'));
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'No se pudo leer el documento.',
                'detail' => $e->getMessage(),
            ], 422);
        }

        if (trim($texto) === '') {
            return response()->json([
                'error' => 'El documento no contiene texto legible (¿es un PDF escaneado sin OCR?).',
            ], 422);
        }

        $process->loadMissing(['client:id,razon_social', 'serviceType:id,nombre']);

        try {
            $extraction = $this->ai->extractWorkPlan($texto, [
                'today' => now()->toDateString(),
                'process_code' => $process->codigo,
                'client_name' => $process->client?->razon_social,
                'service_type' => $process->serviceType?->nombre,
                'fecha_apertura' => $process->fecha_apertura?->toDateString(),
            ]);

            $cost = $this->ai->estimateCost(
                $extraction['usage']['input_tokens'],
                $extraction['usage']['output_tokens'],
            );

            AiGeneration::create([
                'user_id' => Auth::id(),
                'contexto_tipo' => Process::class,
                'contexto_id' => $process->id,
                'proveedor' => 'anthropic',
                'modelo' => config('anthropic.model'),
                'request_hash' => $extraction['request_hash'],
                'prompt' => 'extract_work_plan: '.$request->file('archivo')->getClientOriginalName(),
                'respuesta' => json_encode($extraction, JSON_UNESCAPED_UNICODE),
                'tokens_in' => $extraction['usage']['input_tokens'],
                'tokens_out' => $extraction['usage']['output_tokens'],
                'latencia_ms' => $extraction['latencia_ms'],
                'costo_usd' => $cost,
                'estado' => 'ok',
            ]);

            return response()->json([
                'archivo' => $request->file('archivo')->getClientOriginalName(),
                'tipo_documento' => $extraction['tipo_documento'],
                'resumen' => $extraction['resumen'],
                'etapas' => $extraction['etapas'],
                'transversales' => $extraction['transversales'],
                'tareas' => $extraction['tareas'],
                'costo_usd' => $cost,
                'tokens' => $extraction['usage'],
            ]);
        } catch (Throwable $e) {
            AiGeneration::create([
                'user_id' => Auth::id(),
                'contexto_tipo' => Process::class,
                'contexto_id' => $process->id,
                'proveedor' => 'anthropic',
                'modelo' => config('anthropic.model'),
                'prompt' => 'extract_work_plan: '.$request->file('archivo')->getClientOriginalName(),
                'estado' => 'error',
                'error_mensaje' => $e->getMessage(),
            ]);

            report($e);

            return response()->json([
                'error' => 'La IA no pudo interpretar el documento.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 502);
        }
    }

    /**
     * POST /admin/processes/{process}/plan/apply
     * Aplica la estructura (ya revisada/editada por el abogado) al proceso:
     * crea etapas + entregables + tareas. Opcionalmente la guarda como plantilla
     * del tipo de servicio para reutilizarla en futuros procesos.
     */
    public function apply(Request $request, Process $process): JsonResponse
    {
        abort_unless($request->user()?->can('processes.update'), 403);

        $data = $request->validate([
            'etapas' => ['present', 'array'],
            'etapas.*.nombre' => ['required', 'string', 'max:160'],
            'etapas.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'etapas.*.fecha_entrega' => ['nullable', 'date'],
            'etapas.*.entregables' => ['present', 'array'],
            'etapas.*.entregables.*' => ['string', 'max:1000'],
            'transversales' => ['present', 'array'],
            'transversales.*' => ['string', 'max:1000'],
            'tareas' => ['present', 'array'],
            'tareas.*.titulo' => ['required', 'string', 'max:200'],
            'tareas.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'tareas.*.prioridad' => ['required', Rule::in(self::PRIORIDADES)],
            'tareas.*.fecha_limite' => ['nullable', 'date'],
            'reemplazar_plan' => ['sometimes', 'boolean'],
            'guardar_plantilla' => ['sometimes', 'boolean'],
        ]);

        $reemplazar = $data['reemplazar_plan'] ?? true;
        $guardarPlantilla = $data['guardar_plantilla'] ?? false;

        $result = DB::transaction(function () use ($process, $data, $reemplazar, $guardarPlantilla) {
            if ($reemplazar) {
                // Borrar el plan actual (las checklist_responses caen por cascade).
                $process->stages()->delete();
            }

            $ordenBase = $reemplazar ? 0 : (int) $process->stages()->max('orden');

            $etapasCreadas = 0;
            foreach ($data['etapas'] as $i => $etapa) {
                $stage = $process->stages()->create([
                    'orden' => $ordenBase + $i + 1,
                    'nombre' => $etapa['nombre'],
                    'descripcion' => $etapa['descripcion'] ?? null,
                    'estado' => 'pendiente',
                    'fecha_limite' => $etapa['fecha_entrega'] ?? null,
                ]);
                $etapasCreadas++;

                foreach (($etapa['entregables'] ?? []) as $entregable) {
                    if (trim($entregable) === '') {
                        continue;
                    }
                    ChecklistResponse::create([
                        'process_stage_id' => $stage->id,
                        'descripcion' => $entregable,
                        'es_obligatorio' => true,
                    ]);
                }

                // Los transversales se enganchan a la primera etapa creada (mismo
                // criterio que ProcessService::createFromTemplate).
                if ($i === 0) {
                    foreach ($data['transversales'] as $transversal) {
                        if (trim($transversal) === '') {
                            continue;
                        }
                        ChecklistResponse::create([
                            'process_stage_id' => $stage->id,
                            'descripcion' => $transversal,
                            'es_obligatorio' => false,
                        ]);
                    }
                }
            }

            $tareasCreadas = 0;
            foreach ($data['tareas'] as $tarea) {
                $process->tasks()->create([
                    'titulo' => $tarea['titulo'],
                    'descripcion' => $tarea['descripcion'] ?? null,
                    'prioridad' => $tarea['prioridad'],
                    'estado' => 'pendiente',
                    'creado_por' => Auth::id(),
                    'fecha_limite' => $tarea['fecha_limite'] ?? null,
                ]);
                $tareasCreadas++;
            }

            $plantillaActualizada = false;
            if ($guardarPlantilla && $process->service_type_id) {
                $this->guardarComoPlantilla($process, $data);
                $plantillaActualizada = true;
            }

            return compact('etapasCreadas', 'tareasCreadas', 'plantillaActualizada');
        });

        return response()->json([
            'message' => 'Plan aplicado al proceso.',
            'etapas_creadas' => $result['etapasCreadas'],
            'tareas_creadas' => $result['tareasCreadas'],
            'plantilla_actualizada' => $result['plantillaActualizada'],
        ], 201);
    }

    /**
     * Vuelca la estructura extraída en las plantillas del tipo de servicio del
     * proceso, para que futuros procesos del mismo tipo la hereden. Las fechas
     * absolutas se convierten a SLA (días desde la apertura del proceso).
     */
    private function guardarComoPlantilla(Process $process, array $data): void
    {
        $serviceTypeId = $process->service_type_id;
        $apertura = $process->fecha_apertura;

        // Limpiar plantillas previas del servicio (idéntico criterio que el seeder).
        ServiceStageTemplate::where('service_type_id', $serviceTypeId)->delete();
        ServiceChecklistItem::where('service_type_id', $serviceTypeId)
            ->whereNull('service_stage_template_id')
            ->delete();
        ServiceTaskTemplate::where('service_type_id', $serviceTypeId)->delete();

        $slaDias = function (?string $fecha) use ($apertura): ?int {
            if (! $fecha || ! $apertura) {
                return null;
            }
            $dias = $apertura->diffInDays(Carbon::parse($fecha), false);

            return $dias >= 0 ? (int) $dias : null;
        };

        foreach ($data['etapas'] as $i => $etapa) {
            $template = ServiceStageTemplate::create([
                'service_type_id' => $serviceTypeId,
                'orden' => $i + 1,
                'nombre' => $etapa['nombre'],
                'descripcion' => $etapa['descripcion'] ?? null,
                'sla_dias' => $slaDias($etapa['fecha_entrega'] ?? null),
            ]);

            foreach (($etapa['entregables'] ?? []) as $orden => $entregable) {
                if (trim($entregable) === '') {
                    continue;
                }
                ServiceChecklistItem::create([
                    'service_stage_template_id' => $template->id,
                    'descripcion' => $entregable,
                    'es_obligatorio' => true,
                    'orden' => $orden + 1,
                ]);
            }
        }

        foreach ($data['transversales'] as $orden => $transversal) {
            if (trim($transversal) === '') {
                continue;
            }
            ServiceChecklistItem::create([
                'service_type_id' => $serviceTypeId,
                'service_stage_template_id' => null,
                'descripcion' => $transversal,
                'es_obligatorio' => false,
                'orden' => $orden + 1,
            ]);
        }

        foreach ($data['tareas'] as $orden => $tarea) {
            ServiceTaskTemplate::create([
                'service_type_id' => $serviceTypeId,
                'orden' => $orden + 1,
                'titulo' => $tarea['titulo'],
                'descripcion' => $tarea['descripcion'] ?? null,
                'prioridad' => $tarea['prioridad'],
                'sla_dias' => $slaDias($tarea['fecha_limite'] ?? null),
                'es_activo' => true,
            ]);
        }
    }
}
