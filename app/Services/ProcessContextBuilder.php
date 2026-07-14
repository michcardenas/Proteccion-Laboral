<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\Document;
use App\Models\Process;
use Illuminate\Support\Str;

/**
 * Arma un bloque de texto (markdown) con el contexto REAL del expediente para
 * inyectarlo en los prompts de IA (borradores, respuestas, resúmenes). El objetivo
 * es que Claude redacte "conociendo el caso": etapas, entregables, tareas, historial,
 * correos y borradores previos — no solo el código y el nombre del cliente.
 *
 * Es puramente de lectura y acotado (límites + truncado) para no disparar el tamaño
 * del prompt. NO extrae texto de archivos (solo lista documentos); eso queda para RAG (Fase 2).
 */
class ProcessContextBuilder
{
    /** Máximo de ítems por sección para acotar el prompt. */
    public const MAX_COMENTARIOS = 12;

    public const MAX_CORREOS = 8;

    public const MAX_TAREAS = 20;

    public const MAX_DOCUMENTOS = 25;

    public const MAX_BORRADORES = 3;

    /** Máximo de caracteres por fragmento de texto largo (comentarios, correos, borradores). */
    public const MAX_TEXTO = 600;

    /** Cuántos documentos/adjuntos incluir con su TEXTO extraído (no solo el nombre). */
    public const MAX_DOCS_TEXTO = 8;

    /** Máximo de caracteres del texto extraído por documento. */
    public const MAX_TEXTO_DOC = 3000;

    /** Máximo de caracteres de la ficha de conocimiento del cliente inyectada. */
    public const MAX_FICHA_CLIENTE = 8000;

    public function __construct(private readonly DocumentTextExtractor $extractor)
    {
    }

    public function build(Process $process): string
    {
        $process->loadMissing([
            'client:id,razon_social,nit,sector,ciudad,email,resumen_documental,resumen_documental_at',
            'serviceType:id,nombre',
            'abogadoLider:id,name',
            'apoderado:id,name',
            'coordinador:id,name',
            'stages' => fn ($q) => $q->orderBy('orden'),
            'stages.checklistResponses',
            'tasks:id,process_id,titulo,estado,prioridad,fecha_limite',
            'documents:id,process_id,nombre,tipo,generado_por_ia,created_at',
            'comments' => fn ($q) => $q->with('user:id,name')->latest()->limit(self::MAX_COMENTARIOS),
            'emailIngestions' => fn ($q) => $q->latest('received_at')->limit(self::MAX_CORREOS),
        ]);

        $s = [];
        $s[] = '## Contexto del expediente (datos reales del caso — úsalos para redactar con precisión)';
        $s[] = '';
        $s[] = $this->seccionProceso($process);

        if ($ficha = $this->seccionFichaCliente($process)) {
            $s[] = $ficha;
        }
        if ($etapas = $this->seccionEtapas($process)) {
            $s[] = $etapas;
        }
        if ($tareas = $this->seccionTareas($process)) {
            $s[] = $tareas;
        }
        if ($docs = $this->seccionDocumentos($process)) {
            $s[] = $docs;
        }
        if ($contenido = $this->seccionContenidoDocumentos($process)) {
            $s[] = $contenido;
        }
        if ($correos = $this->seccionCorreos($process)) {
            $s[] = $correos;
        }
        if ($historial = $this->seccionComentarios($process)) {
            $s[] = $historial;
        }
        if ($borradores = $this->seccionBorradoresPrevios($process)) {
            $s[] = $borradores;
        }

        $s[] = '';
        $s[] = '> Este contexto proviene del sistema. Si un dato necesario para la redacción no aparece aquí, márcalo con `[FALTA: ...]` en lugar de inventarlo.';

        return implode("\n", $s);
    }

    protected function seccionProceso(Process $process): string
    {
        $c = $process->client;
        $l = [];
        $l[] = '### Datos generales';
        $l[] = '- **Proceso:** '.($process->codigo ?? '—').' — '.($process->titulo ?? 'sin título');
        $l[] = '- **Estado:** '.($process->estado ?? '—')
            .($process->fecha_apertura ? ' · Apertura '.$process->fecha_apertura->format('Y-m-d') : '')
            .($process->fecha_cierre ? ' · Cierre '.$process->fecha_cierre->format('Y-m-d') : '');
        $l[] = '- **Servicio:** '.($process->serviceType?->nombre ?? '—');

        if ($c) {
            $ident = array_filter([
                $c->nit ? 'NIT '.$c->nit : null,
                $c->sector,
                $c->ciudad,
            ]);
            $l[] = '- **Cliente:** '.($c->razon_social ?? '—').($ident ? ' ('.implode(' · ', $ident).')' : '');
        }

        $equipo = array_filter([
            $process->abogadoLider ? 'Líder: '.$process->abogadoLider->name : null,
            $process->apoderado ? 'Apoderado: '.$process->apoderado->name : null,
            $process->coordinador ? 'Coordinador: '.$process->coordinador->name : null,
        ]);
        if ($equipo) {
            $l[] = '- **Equipo:** '.implode(' · ', $equipo);
        }

        if ($d = trim((string) $process->descripcion)) {
            $l[] = '- **Descripción:** '.Str::limit($d, self::MAX_TEXTO);
        }
        if ($r = trim((string) $process->resumen_ia)) {
            $l[] = '- **Resumen IA del caso:** '.Str::limit($r, self::MAX_TEXTO);
        }

        return implode("\n", $l);
    }

    /**
     * Ficha de conocimiento del cliente (digest IA de TODOS sus documentos). Da AMPLITUD:
     * cubre todo el material del cliente sin el tope de la sección de texto crudo, que
     * aporta la PROFUNDIDAD literal de lo más reciente. Ver ClientKnowledgeService.
     */
    protected function seccionFichaCliente(Process $process): ?string
    {
        $ficha = trim((string) $process->client?->resumen_documental);
        if ($ficha === '') {
            return null;
        }

        $fecha = $process->client?->resumen_documental_at?->format('Y-m-d');

        $l = ['### Ficha de conocimiento del cliente'
            .($fecha ? ' (resumen IA de sus documentos, actualizado '.$fecha.')' : '')];
        $l[] = Str::limit($ficha, self::MAX_FICHA_CLIENTE);

        return implode("\n", $l);
    }

    protected function seccionEtapas(Process $process): ?string
    {
        if ($process->stages->isEmpty()) {
            return null;
        }

        $l = ['### Plan de trabajo (etapas y entregables)'];
        foreach ($process->stages as $etapa) {
            $meta = array_filter([
                $etapa->estado,
                $etapa->fecha_limite ? 'vence '.$etapa->fecha_limite->format('Y-m-d') : null,
                $etapa->fecha_completada ? 'completada '.$etapa->fecha_completada->format('Y-m-d') : null,
            ]);
            $l[] = '- **'.($etapa->nombre ?? 'Etapa').'** — '.implode(' · ', $meta);

            foreach ($etapa->checklistResponses as $item) {
                $marca = $item->completado ? '[x]' : '[ ]';
                $obl = $item->es_obligatorio ? ' *(obligatorio)*' : '';
                $obs = trim((string) $item->observacion);
                $l[] = '    - '.$marca.' '.($item->descripcion ?? 'entregable').$obl
                    .($obs !== '' ? ' — '.Str::limit($obs, 160) : '');
            }
        }

        return implode("\n", $l);
    }

    protected function seccionTareas(Process $process): ?string
    {
        if ($process->tasks->isEmpty()) {
            return null;
        }

        $tareas = $process->tasks->take(self::MAX_TAREAS);
        $l = ['### Tareas del tablero'];
        foreach ($tareas as $t) {
            $meta = array_filter([
                $t->estado,
                $t->prioridad ? 'prioridad '.$t->prioridad : null,
                $t->fecha_limite ? 'límite '.$t->fecha_limite->format('Y-m-d') : null,
            ]);
            $l[] = '- '.($t->titulo ?? 'Tarea').' ('.implode(' · ', $meta).')';
        }
        if ($process->tasks->count() > self::MAX_TAREAS) {
            $l[] = '- … y '.($process->tasks->count() - self::MAX_TAREAS).' tarea(s) más.';
        }

        return implode("\n", $l);
    }

    protected function seccionDocumentos(Process $process): ?string
    {
        if ($process->documents->isEmpty()) {
            return null;
        }

        $docs = $process->documents->sortByDesc('created_at')->take(self::MAX_DOCUMENTOS);
        $l = ['### Documentos del expediente (referencia; el texto no se incluye aquí)'];
        foreach ($docs as $doc) {
            $etiquetas = array_filter([
                $doc->tipo,
                $doc->generado_por_ia ? 'generado por IA' : null,
                $doc->created_at?->format('Y-m-d'),
            ]);
            $l[] = '- '.($doc->nombre ?? 'documento').($etiquetas ? ' ('.implode(' · ', $etiquetas).')' : '');
        }
        if ($process->documents->count() > self::MAX_DOCUMENTOS) {
            $l[] = '- … y '.($process->documents->count() - self::MAX_DOCUMENTOS).' documento(s) más.';
        }

        return implode("\n", $l);
    }

    /**
     * Incluye el TEXTO extraído (no solo el nombre) de los documentos más relevantes:
     * adjuntos de los correos del proceso, documentos del proceso y documentos subidos
     * a nivel del cliente (pestaña "Documentos" del cliente). La extracción es perezosa
     * y cacheada por el DocumentTextExtractor. Acotado para no disparar el tamaño del prompt.
     */
    protected function seccionContenidoDocumentos(Process $process): ?string
    {
        // Candidatos: docs del proceso (incluye adjuntos de correo enlazados por EmailRouter)
        // + docs del cliente (sin proceso). Pedimos más candidatos que el cupo porque algunos
        // no serán extraíbles (imágenes, xls, etc.) y se saltan.
        $candidatos = Document::query()
            ->where(function ($q) use ($process) {
                $q->where('process_id', $process->id);
                if ($process->client_id) {
                    $q->orWhere(function ($qq) use ($process) {
                        $qq->whereNull('process_id')->where('client_id', $process->client_id);
                    });
                }
            })
            ->latest()
            ->limit(self::MAX_DOCS_TEXTO * 3)
            ->get();

        if ($candidatos->isEmpty()) {
            return null;
        }

        $l = [];
        $incluidos = 0;
        foreach ($candidatos as $doc) {
            if ($incluidos >= self::MAX_DOCS_TEXTO) {
                break;
            }

            $texto = $this->extractor->extractFromDocument($doc);
            if ($texto === null || trim($texto) === '') {
                continue;
            }

            $origen = $doc->email_ingestion_id
                ? 'adjunto de correo'
                : ($doc->process_id ? 'documento del proceso' : 'documento del cliente');
            $l[] = '#### '.($doc->nombre ?? 'documento').' ('.$origen
                .($doc->created_at ? ' · '.$doc->created_at->format('Y-m-d') : '').')';
            $l[] = Str::limit(trim($texto), self::MAX_TEXTO_DOC);
            $l[] = '';
            $incluidos++;
        }

        if ($incluidos === 0) {
            return null;
        }

        array_unshift($l, '### Contenido de documentos y adjuntos (texto extraído — úsalo como fuente al redactar)');

        return implode("\n", $l);
    }

    protected function seccionCorreos(Process $process): ?string
    {
        if ($process->emailIngestions->isEmpty()) {
            return null;
        }

        $l = ['### Correos recientes del proceso'];
        foreach ($process->emailIngestions as $mail) {
            $fecha = $mail->received_at ? $mail->received_at->format('Y-m-d') : '';
            $cuerpo = Str::limit(trim((string) $mail->body_text), self::MAX_TEXTO);
            $l[] = '- **'.trim((string) $mail->subject ?: '(sin asunto)').'** — de '.trim((string) $mail->from)
                .($fecha ? ' · '.$fecha : '');
            if ($cuerpo !== '') {
                $l[] = '    '.$cuerpo;
            }
        }

        return implode("\n", $l);
    }

    protected function seccionComentarios(Process $process): ?string
    {
        if ($process->comments->isEmpty()) {
            return null;
        }

        // El eager load ya trae los más recientes; los mostramos en orden cronológico.
        $comentarios = $process->comments->sortBy('created_at');
        $l = ['### Historial de comentarios del equipo'];
        foreach ($comentarios as $com) {
            $autor = $com->user?->name ?? 'Sistema';
            $fecha = $com->created_at?->format('Y-m-d H:i') ?? '';
            $cuerpo = Str::limit(trim((string) $com->body), self::MAX_TEXTO);
            $l[] = '- **'.$autor.'** ('.$fecha.'): '.$cuerpo;
        }

        return implode("\n", $l);
    }

    protected function seccionBorradoresPrevios(Process $process): ?string
    {
        $borradores = AiGeneration::query()
            ->where('contexto_tipo', Process::class)
            ->where('contexto_id', $process->id)
            ->whereNotNull('respuesta')
            ->where('respuesta', '!=', '')
            ->latest()
            ->limit(self::MAX_BORRADORES)
            ->get(['id', 'modelo', 'respuesta', 'created_at']);

        if ($borradores->isEmpty()) {
            return null;
        }

        $l = ['### Borradores de IA generados antes para este caso (para mantener coherencia, no repetir literal)'];
        foreach ($borradores as $b) {
            $fecha = $b->created_at?->format('Y-m-d') ?? '';
            $l[] = '- Borrador del '.$fecha.': '.Str::limit(trim((string) $b->respuesta), self::MAX_TEXTO);
        }

        return implode("\n", $l);
    }
}
