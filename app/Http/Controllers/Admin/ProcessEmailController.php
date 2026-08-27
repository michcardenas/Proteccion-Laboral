<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Services\AiService;
use App\Services\GmailService;
use App\Services\ProcessContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private readonly AiService $ai,
        private readonly GmailService $gmail,
        private readonly ProcessContextBuilder $context,
    ) {}

    /**
     * POST /admin/processes/{process}/emails/{ingestion}/draft
     * Genera con IA un borrador de respuesta al correo (no envía nada).
     */
    public function draft(Request $request, Process $process, EmailIngestion $ingestion): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);
        abort_unless($ingestion->process_id === $process->id, 404);

        set_time_limit(180);

        // Columnas completas para que el ProcessContextBuilder disponga del cliente sin restricción.
        $process->loadMissing(['client', 'serviceType']);

        $prompt = $this->buildDraftPrompt($process, $ingestion, $request->string('instrucciones')->toString());

        try {
            $result = $this->ai->generateDraft($prompt);

            AiGeneration::create([
                'user_id' => Auth::id(),
                'contexto_tipo' => Process::class,
                'contexto_id' => $process->id,
                'proveedor' => 'anthropic',
                'modelo' => $result['model'],
                'request_hash' => $result['request_hash'],
                'prompt' => $prompt,
                'respuesta' => $result['text'],
                'tokens_in' => $result['usage']['input_tokens'],
                'tokens_out' => $result['usage']['output_tokens'],
                'latencia_ms' => $result['latencia_ms'],
                'costo_usd' => $this->ai->estimateCost(
                    $result['usage']['input_tokens'],
                    $result['usage']['output_tokens'],
                    $result['model'],
                ),
                'estado' => 'ok',
            ]);

            return response()->json(['borrador' => trim($result['text'])]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'No se pudo generar el borrador.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 502);
        }
    }

    /**
     * POST /admin/processes/{process}/emails/{ingestion}/reply
     * Envía la respuesta vía Gmail y la registra como comentario del proceso.
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
        ]);

        $to = $this->extractEmail($data['to']);
        if (! $to) {
            return response()->json(['error' => 'El destinatario no es un correo válido.'], 422);
        }

        $payload = $ingestion->raw_payload ?? [];

        try {
            $sentId = $this->gmail->sendReply([
                'to' => $to,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'thread_id' => $payload['thread_id'] ?? null,
                'in_reply_to' => $payload['message_id_header'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'No se pudo enviar el correo.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 502);
        }

        // Deja constancia en el historial del proceso.
        $process->comments()->create([
            'user_id' => Auth::id(),
            'body' => "📧 Respuesta enviada a {$to}\nAsunto: {$data['subject']}\n\n{$data['body']}",
            'visible_cliente' => $data['visible_cliente'] ?? false,
        ]);

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
