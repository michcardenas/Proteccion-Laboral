<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Process;
use App\Models\User;
use App\Services\MensajeWord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Mime types que es seguro mostrar inline en el navegador. El resto se
     * descarga como adjunto (Word, Excel, zip, etc.).
     */
    private const INLINE_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'text/plain',
        'text/html',
    ];

    /**
     * GET /admin/documents/{document}/download
     *
     * Sirve un documento del proceso. Los adjuntos de correo y los borradores IA
     * viven en el disco privado `local`; este endpoint es la única vía para
     * abrirlos (PDF/imágenes inline, lo demás como descarga). Los documentos de
     * Google Drive (`disco = gdrive`) guardan una URL externa: se redirige a ella.
     */
    public function download(Request $request, Document $document): StreamedResponse|RedirectResponse
    {
        // Documentos de proceso: se autorizan por el proceso. Documentos a nivel
        // cliente (sin proceso, p.ej. PDF de contrato o diagnóstico pre-jurídico):
        // se autorizan por la visibilidad del cliente.
        if ($document->process_id !== null) {
            $this->authorizeProcessAccess($request, $document->process);
        } else {
            $this->authorizeClientAccess($request, $document);
        }

        // Documentos enlazados de Drive: la "ruta" es una URL → redirigir.
        if ($document->disco === 'gdrive') {
            return redirect()->away($document->ruta);
        }

        $disk = Storage::disk($document->disco ?? 'local');

        abort_unless($document->ruta && $disk->exists($document->ruta), 404, 'El archivo ya no está disponible.');

        $inline = in_array($document->mime, self::INLINE_MIMES, true);
        $filename = $document->nombre ?: basename($document->ruta);

        return $disk->response(
            $document->ruta,
            $filename,
            [
                'Content-Type' => $document->mime ?: 'application/octet-stream',
                'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($filename).'"',
            ],
        );
    }

    /**
     * PATCH /admin/documents/{document}/visibility
     *
     * La visibilidad se elegia al subir el documento y no habia forma de
     * cambiarla: para compartir algo ya subido habia que subirlo otra vez.
     * Quien puede abrir el documento y tiene `documents.share_with_client`
     * decide si el cliente lo ve.
     */
    public function visibility(Request $request, Document $document): RedirectResponse
    {
        if ($document->process_id !== null) {
            $this->authorizeProcessAccess($request, $document->process);
        } else {
            $this->authorizeClientAccess($request, $document);
        }

        $data = $request->validate(['visible_cliente' => ['required', 'boolean']]);

        $document->update(['visible_cliente' => $data['visible_cliente']]);

        return back()->with('success', $document->visible_cliente
            ? "«{$document->nombre}» ahora es visible para el cliente."
            : "«{$document->nombre}» ya no es visible para el cliente.");
    }

    /**
     * Aborta con 403 si el usuario solo tiene visibilidad restringida
     * (`processes.view_assigned` sin `processes.view`) y no está asignado al
     * proceso al que pertenece el documento. Mismo criterio que el resto del módulo.
     */
    /**
     * Compartir o dejar de compartir un comentario del proceso (respuesta de
     * correo, borrador IA) con el cliente.
     */
    public function commentVisibility(Request $request, Comment $comment): RedirectResponse
    {
        abort_unless($comment->commentable instanceof Process, 404);
        $this->authorizeProcessAccess($request, $comment->commentable);

        $data = $request->validate(['visible_cliente' => ['required', 'boolean']]);

        $comment->update(['visible_cliente' => $data['visible_cliente']]);
        // Sus adjuntos van con el: compartir el mensaje sin el Word que lo
        // acompaña es justo lo que el cliente echaba en falta.
        $comment->documents()->update(['visible_cliente' => $data['visible_cliente']]);

        return back()->with('success', $comment->visible_cliente
            ? 'El comentario ahora es visible para el cliente.'
            : 'El comentario ya no es visible para el cliente.');
    }

    /**
     * Adjunta archivos a un mensaje ya registrado. Los Word que el despacho
     * mando a mano por Gmail nunca pasaron por la plataforma: asi aparecen
     * bajo su mensaje en el portal. Heredan la visibilidad del mensaje.
     */
    public function attachToComment(Request $request, Comment $comment): RedirectResponse
    {
        $process = $comment->commentable;
        abort_unless($process instanceof Process, 404);
        $this->authorizeProcessAccess($request, $process);

        $data = $request->validate([
            'archivos' => ['required', 'array', 'min:1', 'max:10'],
            'archivos.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,txt'],
        ], [
            // La app no tiene traducciones: sin esto sale el mensaje en inglés.
            'archivos.*.mimes' => 'Solo se admiten Word, PDF, Excel, imágenes o texto (:attribute no lo es o está dañado).',
            'archivos.*.max' => 'Cada archivo puede pesar como mucho 20 MB.',
            'archivos.max' => 'Como mucho 10 archivos a la vez.',
        ], [
            'archivos.*' => 'el archivo',
        ]);

        foreach ($data['archivos'] as $archivo) {
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
                'subido_por' => $request->user()->id,
                'visible_cliente' => $comment->visible_cliente,
            ]);
        }

        $n = count($data['archivos']);

        return back()->with('success', ($n === 1 ? 'Archivo adjuntado' : "{$n} archivos adjuntados").' al mensaje'
            .($comment->visible_cliente ? '; el cliente ya los ve en el portal.' : '.'));
    }

    /**
     * El mensaje en Word, tal como lo descarga el cliente desde el portal.
     */
    public function commentWord(Request $request, Comment $comment, MensajeWord $word)
    {
        $process = $comment->commentable;
        abort_unless($process instanceof Process, 404);
        $this->authorizeProcessAccess($request, $process);

        $docx = $word->generar($comment, $process);

        return response($docx['contenido'], 200, [
            'Content-Type' => MensajeWord::MIME,
            'Content-Disposition' => 'attachment; filename="'.addslashes($docx['nombre']).'"',
        ]);
    }

    private function authorizeProcessAccess(Request $request, ?Process $process): void
    {
        abort_unless($process !== null, 404);

        /** @var User $user */
        $user = $request->user();

        $restringido = ! $user->can('processes.view') && $user->can('processes.view_assigned');
        if (! $restringido) {
            return;
        }

        $esMio = $process->abogado_lider_id === $user->id
            || $process->apoderado_id === $user->id
            || $process->coordinador_id === $user->id;

        abort_unless($esMio, 403);
    }

    /**
     * Autoriza la descarga de un documento a nivel cliente (sin proceso). Aborta
     * 404 si no tiene cliente, o 403 si el usuario tiene visibilidad restringida
     * (`clients.view_assigned` sin `clients.view`) y no está asignado al cliente.
     */
    private function authorizeClientAccess(Request $request, Document $document): void
    {
        $client = $document->client;
        abort_unless($client !== null, 404);

        /** @var User $user */
        $user = $request->user();

        $restringido = ! $user->can('clients.view') && $user->can('clients.view_assigned');
        if (! $restringido) {
            return;
        }

        abort_unless($client->asignados()->where('users.id', $user->id)->exists(), 403);
    }
}
