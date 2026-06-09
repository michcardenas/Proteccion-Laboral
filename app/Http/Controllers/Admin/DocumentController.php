<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Process;
use App\Models\User;
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
        $this->authorizeProcessAccess($request, $document->process);

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
     * Aborta con 403 si el usuario solo tiene visibilidad restringida
     * (`processes.view_assigned` sin `processes.view`) y no está asignado al
     * proceso al que pertenece el documento. Mismo criterio que el resto del módulo.
     */
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
}
