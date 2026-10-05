<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiDraft;
use App\Models\AiGeneration;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Services\GmailService;
use App\Services\ProcessContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Responder, desde la ficha del proceso, los correos que llegaron referentes a él.
 * El borrador puede generarse con IA; el envío sale por la cuenta de Gmail conectada
 * del despacho, enhebrado al correo original.
 */
class ProcessEmailController extends Controller
{
    public function __construct(
        private readonly GmailService $gmail,
        private readonly ProcessContextBuilder $context,
    ) {}

    /**
     * POST /admin/processes/{process}/emails/{ingestion}/draft
     * Encola la redacción de la respuesta al correo (no envía nada) y devuelve 202
     * con el id de la fila. El texto se recoge sondeando `admin.processes.ai.show`.
     *
     * Hoy una respuesta a correo cabe (~23 s medidos en producción), pero el prompt
     * lleva el cuerpo entero del correo más el expediente: un hilo largo la habría
     * llevado al mismo 504 que tumbaba los borradores. Ver GenerateAiDraft.
     */
    public function draft(Request $request, Process $process, EmailIngestion $ingestion): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);
        abort_unless($ingestion->process_id === $process->id, 404);

        // Columnas completas para que el ProcessContextBuilder disponga del cliente sin restricción.
        $process->loadMissing(['client', 'serviceType']);

        $prompt = $this->buildDraftPrompt($process, $ingestion, $request->string('instrucciones')->toString());

        $generation = AiGeneration::create([
            'user_id' => Auth::id(),
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => config('anthropic.model'),
            'prompt' => $prompt,
            'estado' => 'pendiente',
        ]);

        GenerateAiDraft::dispatch($generation->id);

        return response()->json([
            'id' => $generation->id,
            'estado' => 'pendiente',
        ], 202);
    }

    /**
     * POST /admin/processes/{process}/emails/{ingestion}/reply
     * Envía la respuesta vía Gmail y la registra como comentario del proceso.
     * Los adjuntos (el Word, el PDF) salen en el correo y se guardan como
     * documentos del proceso colgados del comentario: si el comentario es
     * visible, el cliente los descarga en el portal junto al mensaje.
     */
    public function reply(Request $request, Process $process, EmailIngestion $ingestion): JsonResponse
    {
        abort_unless($request->user()?->can('processes.update'), 403);
        abort_unless($ingestion->process_id === $process->id, 404);

        $data = $request->validate([
            'to' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'visible_cliente' => ['sometimes', 'boolean'],
            'adjuntos' => ['sometimes', 'array', 'max:10'],
            'adjuntos.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,txt'],
        ]);

        $to = $this->extractEmail($data['to']);
        if (! $to) {
            return response()->json(['error' => 'El destinatario no es un correo válido.'], 422);
        }

        /** @var UploadedFile[] $archivos */
        $archivos = $data['adjuntos'] ?? [];
        if (array_sum(array_map(fn (UploadedFile $f) => $f->getSize(), $archivos)) > GmailService::ATTACHMENTS_MAX_BYTES) {
            return response()->json(['error' => 'Los adjuntos suman más de 18 MB: Gmail no admite un correo tan grande.'], 422);
        }

        $payload = $ingestion->raw_payload ?? [];

        // Se responde DESDE la cuenta que recibio el correo. Enviar siempre
        // desde la ultima conectada hacia salir la respuesta de una abogada
        // con el remitente de otra.
        $gmail = $ingestion->integrationToken
            ? $this->gmail->paraCuenta($ingestion->integrationToken)
            : $this->gmail;

        try {
            $sentId = $gmail->sendReply([
                'to' => $to,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'thread_id' => $payload['thread_id'] ?? null,
                'in_reply_to' => $payload['message_id_header'] ?? null,
                'attachments' => array_map(fn (UploadedFile $f) => [
                    'filename' => $f->getClientOriginalName(),
                    'mime' => $f->getClientMimeType(),
                    'content' => $f->get(),
                ], $archivos),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'No se pudo enviar el correo.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 502);
        }

        // Deja constancia en el historial del proceso.
        $visible = $data['visible_cliente'] ?? false;
        $comment = $process->comments()->create([
            'user_id' => Auth::id(),
            'body' => "📧 Respuesta enviada a {$to}\nAsunto: {$data['subject']}\n\n{$data['body']}",
            'visible_cliente' => $visible,
        ]);

        // Se guardan despues del envio: si Gmail falla no quedan documentos
        // de un correo que nunca salio.
        foreach ($archivos as $archivo) {
            Document::create([
                'process_id' => $process->id,
                'client_id' => $process->client_id,
                'comment_id' => $comment->id,
                'nombre' => $archivo->getClientOriginalName(),
                'ruta' => $archivo->store("documents/process_{$process->id}", 'local'),
                'disco' => 'local',
                'tipo' => 'comunicacion',
                'mime' => $archivo->getClientMimeType(),
                'tamano_bytes' => $archivo->getSize(),
                'generado_por_ia' => false,
                'subido_por' => Auth::id(),
                'visible_cliente' => $visible,
            ]);
        }

        // Marca el correo como respondido (para la bandeja del tablero Kanban).
        $ingestion->forceFill(['respondido_at' => now()])->save();

        // Marcar el original como leído y etiquetarlo (best-effort: no romper si falla).
        if ($ingestion->message_id) {
            try {
                $this->gmail->markAsRead($ingestion->message_id);
                $this->gmail->addLabel($ingestion->message_id, 'Respondido');
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'Respuesta enviada.',
            'gmail_message_id' => $sentId,
        ], 201);
    }

    /**
     * Construye el prompt para que la IA redacte la respuesta al correo.
     */
    private function buildDraftPrompt(Process $process, EmailIngestion $ingestion, string $instrucciones): string
    {
        $contexto = [
            'Eres un abogado del despacho Protección Laboral Soluciones Legales. Redacta una respuesta profesional, cordial y clara (en español) al siguiente correo de un cliente, en nombre del despacho.',
            '',
            "Proceso: {$process->codigo} — {$process->titulo}",
            'Cliente: '.($process->client?->razon_social ?? 'N/D'),
            'Servicio: '.($process->serviceType?->nombre ?? 'N/D'),
            '',
            '--- CORREO RECIBIDO ---',
            'De: '.$ingestion->from,
            'Asunto: '.$ingestion->subject,
            '',
            (string) $ingestion->body_text,
            '--- FIN DEL CORREO ---',
            '',
            $this->context->build($process),
            '',
        ];

        if (trim($instrucciones) !== '') {
            $contexto[] = 'Instrucciones adicionales del abogado: '.$instrucciones;
            $contexto[] = '';
        }

        $contexto[] = 'Devuelve únicamente el cuerpo de la respuesta (sin asunto, sin encabezados de correo). Cierra con una firma cordial a nombre del despacho.';

        return implode("\n", $contexto);
    }

    /**
     * Extrae la dirección de correo de un string que puede venir como
     * "Nombre <correo@dominio>" o simplemente "correo@dominio".
     */
    private function extractEmail(string $value): ?string
    {
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            $value = $m[1];
        }
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ?: null;
    }
}
