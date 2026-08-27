<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Services\EmailRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja de revisión humana de correos que la IA no pudo enrutar.
 *
 * Son las ingestas que quedaron en `needs_review` (sin `process_id`): nuevo_caso
 * con baja confianza o sin match de cliente/servicio, seguimientos cuyo código de
 * proceso no existe, o correos marcados como `requiere_revision_humana`. Desde aquí
 * el director/coordinador los asigna manualmente a un proceso (reusando la misma
 * lógica del EmailRouter) o los descarta como irrelevantes.
 */
class EmailReviewController extends Controller
{
    public function __construct(private readonly EmailRouter $router) {}

    /**
     * GET /admin/emails/review
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('emails.review'), 403);

        $buscar = trim((string) $request->string('buscar'));
        $accion = trim((string) $request->string('accion'));

        $query = EmailIngestion::query()
            ->visiblePara($request->user())
            ->where('status', EmailIngestion::STATUS_NEEDS_REVIEW)
            ->orderByDesc('received_at');

        if ($buscar !== '') {
            $query->where(function ($q) use ($buscar) {
                $q->where('from', 'like', "%{$buscar}%")
                    ->orWhere('subject', 'like', "%{$buscar}%");
            });
        }

        if ($accion !== '') {
            $query->where('ai_classification->action', $accion);
        }

        $correos = $query->paginate(15)->withQueryString()
            ->through(fn (EmailIngestion $e) => $this->mapEmail($e));

        // Acciones presentes entre los pendientes, para el filtro.
        $acciones = EmailIngestion::query()
            ->visiblePara($request->user())
            ->where('status', EmailIngestion::STATUS_NEEDS_REVIEW)
            ->get(['ai_classification'])
            ->map(fn (EmailIngestion $e) => $e->ai_classification['action'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        // Procesos disponibles para asignar (el <select> del front).
        $procesos = Process::query()
            ->with('client:id,razon_social')
            ->orderByDesc('id')
            ->get(['id', 'codigo', 'titulo', 'client_id'])
            ->map(fn (Process $p) => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'titulo' => $p->titulo,
                'cliente' => $p->client?->razon_social,
            ]);

        return Inertia::render('Admin/Emails/Review', [
            'correos' => $correos,
            'procesos' => $procesos,
            'acciones' => $acciones,
            'filters' => ['buscar' => $buscar, 'accion' => $accion],
        ]);
    }

    /**
     * POST /admin/emails/{ingestion}/assign
     * Asigna el correo a un proceso y lo marca como procesado.
     */
    public function assign(Request $request, EmailIngestion $ingestion): RedirectResponse
    {
        abort_unless($request->user()?->can('emails.review'), 403);
        abort_unless($ingestion->loPuedeVer($request->user()), 403, 'Ese correo no es de tu bandeja.');
        abort_unless($ingestion->status === EmailIngestion::STATUS_NEEDS_REVIEW, 422, 'El correo ya no está en revisión.');

        $data = $request->validate([
            'process_id' => ['required', 'integer', 'exists:processes,id'],
        ]);

        $process = Process::findOrFail($data['process_id']);

        $this->router->assignToProcess($ingestion, $process);

        $ingestion->forceFill([
            'status' => EmailIngestion::STATUS_PROCESSED,
            'processed_at' => now(),
        ])->save();

        return back()->with('success', "Correo asignado al proceso {$process->codigo}.");
    }

    /**
     * POST /admin/emails/{ingestion}/discard
     * Marca el correo como descartado (irrelevante), sin enlazarlo a un proceso.
     */
    public function discard(Request $request, EmailIngestion $ingestion): RedirectResponse
    {
        abort_unless($request->user()?->can('emails.review'), 403);
        abort_unless($ingestion->loPuedeVer($request->user()), 403, 'Ese correo no es de tu bandeja.');
        abort_unless($ingestion->status === EmailIngestion::STATUS_NEEDS_REVIEW, 422, 'El correo ya no está en revisión.');

        $ingestion->forceFill([
            'status' => EmailIngestion::STATUS_DISCARDED,
            'processed_at' => now(),
        ])->save();

        return back()->with('success', 'Correo descartado.');
    }

    /**
     * Aplana una ingesta para la vista, exponiendo la sugerencia de la IA.
     */
    private function mapEmail(EmailIngestion $e): array
    {
        $c = $e->ai_classification ?? [];

        return [
            'id' => $e->id,
            'from' => $e->from,
            'to' => $e->to,
            'subject' => $e->subject,
            'received_at' => $e->received_at?->toIso8601String(),
            'body_preview' => Str::limit((string) $e->body_text, 280),
            'sugerencia' => [
                'action' => $c['action'] ?? null,
                'confidence' => isset($c['confidence']) ? (float) $c['confidence'] : null,
                'summary' => $c['summary'] ?? null,
                'process_code' => $c['process_code'] ?? null,
                'client_name' => $c['client_name'] ?? null,
                'service_type' => $c['service_type'] ?? null,
            ],
        ];
    }
}
