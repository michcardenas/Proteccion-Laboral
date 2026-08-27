<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Listado de procesos del cliente logueado, con su avance.
     */
    public function index(): Response
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        $processes = $client->processes()
            ->with(['serviceType:id,nombre,modalidad', 'abogadoLider:id,name'])
            ->withCount(['stages', 'stages as completed_stages_count' => fn ($q) => $q->where('estado', 'completada')])
            ->latest('fecha_apertura')
            ->get()
            ->map(fn (Process $p) => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'titulo' => $p->titulo,
                'estado' => $p->estado,
                'fecha_apertura' => $p->fecha_apertura?->format('Y-m-d'),
                'service' => $p->serviceType?->nombre,
                'modalidad' => $p->serviceType?->modalidad,
                'lider' => $p->abogadoLider?->name,
                'stages_count' => $p->stages_count,
                'completed_stages_count' => $p->completed_stages_count,
                'percent' => $p->stages_count > 0
                    ? (int) round(($p->completed_stages_count / $p->stages_count) * 100)
                    : 0,
            ]);

        return Inertia::render('Portal/Dashboard', [
            'client' => [
                'razon_social' => $client->razon_social,
                'nit' => $client->nit,
            ],
            'processes' => $processes,
        ]);
    }

    /**
     * Detalle de UN proceso para el cliente: avance/etapas (plan de trabajo),
     * resumen IA y visitas visibles. Solo de lectura.
     */
    public function show(Process $process): Response
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        // Aislamiento: el proceso debe pertenecer a este cliente.
        abort_unless($process->client_id === $client->id, 403);

        $process->load([
            'serviceType:id,nombre,modalidad,descripcion',
            'abogadoLider:id,name',
            'stages' => fn ($q) => $q->with('checklistResponses')->orderBy('orden'),
            'visits' => fn ($q) => $q->where('visible_cliente', true)
                ->with(['registradaPor:id,name', 'asistentes:id,name', 'documents'])
                ->orderByDesc('fecha'),
            'payments' => fn ($q) => $q->orderByDesc('fecha_pago'),
        ]);

        // Avance global por checklist (mismo cálculo que el admin).
        $totalChecklist = $process->stages->sum(fn ($s) => $s->checklistResponses->count());
        $completedChecklist = $process->stages->sum(fn ($s) => $s->checklistResponses->where('completado', true)->count());

        return Inertia::render('Portal/Process', [
            'client' => [
                'razon_social' => $client->razon_social,
                'nit' => $client->nit,
            ],
            'process' => [
                'id' => $process->id,
                'codigo' => $process->codigo,
                'titulo' => $process->titulo,
                'estado' => $process->estado,
                'descripcion' => $process->descripcion,
                'resumen_ia' => $process->resumen_ia,
                'resumen_ia_generado_at' => $process->resumen_ia_generado_at?->toIso8601String(),
                'fecha_apertura' => $process->fecha_apertura?->format('Y-m-d'),
                'fecha_cierre' => $process->fecha_cierre?->format('Y-m-d'),
                'service' => $process->serviceType?->nombre,
                'modalidad' => $process->serviceType?->modalidad,
                'lider' => $process->abogadoLider?->name,
                'progress' => [
                    'total' => $totalChecklist,
                    'completed' => $completedChecklist,
                    'percent' => $totalChecklist > 0 ? (int) round(($completedChecklist / $totalChecklist) * 100) : 0,
                ],
                // Plan de trabajo = etapas del proceso, presentadas como barras de progreso.
                'plan' => $process->stages->map(function ($s) {
                    $total = $s->checklistResponses->count();
                    $done = $s->checklistResponses->where('completado', true)->count();

                    return [
                        'id' => $s->id,
                        'orden' => $s->orden,
                        'nombre' => $s->nombre,
                        'estado' => $s->estado,
                        'fecha_inicio' => $s->fecha_inicio?->format('Y-m-d'),
                        'fecha_limite' => $s->fecha_limite?->format('Y-m-d'),
                        'fecha_completada' => $s->fecha_completada?->toIso8601String(),
                        'percent' => $total > 0
                            ? (int) round(($done / $total) * 100)
                            : ($s->estado === 'completada' ? 100 : 0),
                        'items_total' => $total,
                        'items_done' => $done,
                    ];
                }),
                // Pagos = constancia para el cliente.
                'pagos' => $process->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'monto' => (float) $p->monto,
                    'fecha_pago' => $p->fecha_pago?->format('Y-m-d'),
                    'concepto' => $p->concepto,
                    'metodo' => $p->metodo,
                    'referencia' => $p->referencia,
                ]),
                'pagos_total' => (float) $process->payments->sum('monto'),
                'visits' => $process->visits->map(fn ($v) => [
                    'id' => $v->id,
                    'tipo' => $v->tipo,
                    'fecha' => $v->fecha?->format('Y-m-d'),
                    'titulo' => $v->titulo,
                    'descripcion' => $v->descripcion,
                    'registrada_por' => $v->registradaPor?->name,
                    'asistentes' => $v->asistentes->pluck('name'),
                    'documentos' => $v->documents->map(fn ($d) => [
                        'id' => $d->id,
                        'nombre' => $d->nombre,
                        'url' => route('portal.documents.download', $d->id),
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * Descarga de un documento desde el portal. El cliente solo puede abrir
     * documentos que pertenezcan a alguno de SUS procesos.
     */
    public function downloadDocument(Document $document)
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        // El documento debe estar ligado a un proceso de este cliente.
        $perteneceAlCliente = $document->process_id
            && Process::query()
                ->where('id', $document->process_id)
                ->where('client_id', $client->id)
                ->exists();

        abort_unless($perteneceAlCliente, 403);

        if ($document->disco === 'gdrive') {
            return redirect()->away($document->ruta);
        }

        $disk = Storage::disk($document->disco ?? 'local');
        abort_unless($document->ruta && $disk->exists($document->ruta), 404, 'El archivo ya no está disponible.');

        $inline = in_array($document->mime, [
            'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain', 'text/html',
        ], true);
        $filename = $document->nombre ?: basename($document->ruta);

        return $disk->response($document->ruta, $filename, [
            'Content-Type' => $document->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($filename).'"',
        ]);
    }
}
