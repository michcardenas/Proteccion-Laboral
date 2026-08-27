<?php

namespace App\Services;

use App\Models\Process;
use Illuminate\Support\Str;

class ProcessSummaryService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly ProcessContextBuilder $contexto,
    ) {}

    /**
     * Genera el resumen ejecutivo del proceso con IA y lo persiste en
     * `processes.resumen_ia`. Devuelve el proceso actualizado.
     *
     * Pensado para usarse tanto desde la ficha (botón manual) como desde el
     * pipeline de correos entrantes (al crear un caso nuevo). NO captura
     * excepciones: el llamador decide cómo manejarlas (responder 502, loguear, etc.).
     */
    public function generate(Process $process): Process
    {
        $process->loadMissing([
            // El cliente entero, no dos columnas: el ProcessContextBuilder
            // necesita `resumen_documental`, y si la relacion ya viene cargada
            // recortada su propio loadMissing la da por buena y la ficha no
            // llega nunca. Falla en silencio, con el resumen saliendo pobre y
            // sin ningun error que lo delate.
            'client',
            'serviceType:id,nombre,modalidad',
            'abogadoLider:id,name',
            'apoderado:id,name',
            'stages' => fn ($q) => $q->with('checklistResponses')->orderBy('orden'),
            'tasks' => fn ($q) => $q->with('asignado:id,name')->latest(),
            'comments' => fn ($q) => $q->latest()->limit(8),
        ]);

        $prompt = $this->renderPrompt($process);

        $result = $this->ai->generateDraft($prompt);

        $process->forceFill([
            'resumen_ia' => trim($result['text']),
            'resumen_ia_generado_at' => now(),
        ])->saveQuietly();

        return $process;
    }

    /**
     * Genera el resumen pero nunca lanza: si falla, lo reporta y deja el proceso
     * sin resumen. Útil en jobs/pipelines donde el fallo del resumen no debe
     * tumbar el procesamiento del correo.
     */
    public function generateQuietly(Process $process): void
    {
        try {
            $this->generate($process);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Construye el prompt del resumen rellenando process_summary.md con el expediente.
     */
    private function renderPrompt(Process $process): string
    {
        $template = file_get_contents(resource_path('prompts/process_summary.md'));

        $etapas = $process->stages->map(function ($s) {
            $total = $s->checklistResponses->count();
            $done = $s->checklistResponses->where('completado', true)->count();
            $limite = $s->fecha_limite?->format('Y-m-d');

            return sprintf(
                '- Etapa %s: %s [%s] — checklist %d/%d%s',
                $s->orden,
                $s->nombre,
                $s->estado,
                $done,
                $total,
                $limite ? " — límite {$limite}" : '',
            );
        })->implode("\n") ?: '(sin etapas)';

        $tareas = $process->tasks->map(function ($t) {
            $limite = $t->fecha_limite?->format('Y-m-d');

            return sprintf(
                '- %s [%s, prioridad %s]%s%s',
                $t->titulo,
                $t->estado,
                $t->prioridad,
                $t->asignado ? " — {$t->asignado->name}" : '',
                $limite ? " — vence {$limite}" : '',
            );
        })->implode("\n") ?: '(sin tareas)';

        $comentarios = $process->comments->map(
            fn ($c) => '- '.$c->created_at?->format('Y-m-d').': '.Str::limit($c->body, 300)
        )->implode("\n") ?: '(sin comentarios)';

        // Si alguien edita la plantilla y se lleva por delante el placeholder,
        // el contexto se anexa igualmente en vez de perderse en silencio.
        if (! str_contains($template, '{{expediente_contexto}}')) {
            $template .= '

## Expediente completo

{{expediente_contexto}}
';
        }

        return strtr($template, [
            '{{codigo}}' => $process->codigo ?? '',
            '{{titulo}}' => $process->titulo ?? '',
            '{{estado}}' => $process->estado ?? '',
            '{{client_name}}' => $process->client?->razon_social ?? 'Sin cliente',
            '{{service_type}}' => $process->serviceType?->nombre ?? 'Sin servicio',
            '{{modalidad}}' => $process->serviceType?->modalidad ?? '',
            '{{fecha_apertura}}' => $process->fecha_apertura?->format('Y-m-d') ?? '',
            '{{lider}}' => $process->abogadoLider?->name ?? 'Sin asignar',
            '{{apoderado}}' => $process->apoderado?->name ?? 'Sin asignar',
            '{{descripcion}}' => $process->descripcion ?: '(sin descripción)',
            '{{etapas}}' => $etapas,
            '{{tareas}}' => $tareas,
            '{{comentarios}}' => $comentarios,

            // Sin esto el resumen solo veia etapas, tareas y comentarios: te
            // contaba como va la GESTION del caso y nada de que trata el caso.
            // En un proceso importado de Drive —seis documentos y cero tareas,
            // porque nadie ha trabajado en el dentro de la app— salia vacio.
            '{{expediente_contexto}}' => $this->contexto->build($process),
        ]);
    }
}
