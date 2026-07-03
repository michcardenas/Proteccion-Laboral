<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Process;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pagos del cliente dentro de un proceso. El abogado registra cada pago
 * ("constancia"); el cliente los ve en su portal.
 */
class PaymentController extends Controller
{
    public function store(Request $request, Process $process): RedirectResponse
    {
        abort_unless($request->user()?->can('payments.manage'), 403);

        $data = $this->validatePayment($request);

        $process->payments()->create([
            'client_id' => $process->client_id,
            'registrado_por' => $request->user()?->id,
            'monto' => $data['monto'],
            'fecha_pago' => $data['fecha_pago'],
            'concepto' => $data['concepto'],
            'metodo' => $data['metodo'],
            'referencia' => $data['referencia'] ?? null,
            'notas' => $data['notas'] ?? null,
        ]);

        return back()->with('success', 'Pago registrado.');
    }

    public function update(Request $request, Process $process, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()?->can('payments.manage'), 403);
        abort_unless($payment->process_id === $process->id, 404);

        $data = $this->validatePayment($request);

        $payment->update([
            'monto' => $data['monto'],
            'fecha_pago' => $data['fecha_pago'],
            'concepto' => $data['concepto'],
            'metodo' => $data['metodo'],
            'referencia' => $data['referencia'] ?? null,
            'notas' => $data['notas'] ?? null,
        ]);

        return back()->with('success', 'Pago actualizado.');
    }

    public function destroy(Request $request, Process $process, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()?->can('payments.manage'), 403);
        abort_unless($payment->process_id === $process->id, 404);

        $payment->delete();

        return back()->with('success', 'Pago eliminado.');
    }

    /**
     * POST /admin/processes/{process}/payments/{payment}/documents
     * Adjunta el soporte/factura (PDF u otro) a un pago. El documento queda
     * vinculado al pago Y al proceso (para reutilizar la autorización por proceso
     * en la descarga).
     */
    public function storeDocument(Request $request, Process $process, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()?->can('payments.manage'), 403);
        abort_unless($payment->process_id === $process->id, 404);

        $validated = $request->validate([
            'archivo' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,txt'],
            'nombre' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $validated['archivo'];
        $ruta = $file->store("payments/process_{$process->id}", 'local');

        Document::create([
            'process_id' => $process->id,
            'payment_id' => $payment->id,
            'client_id' => $process->client_id,
            'nombre' => $validated['nombre'] ?: $file->getClientOriginalName(),
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => 'soporte',
            'mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'generado_por_ia' => false,
            'subido_por' => $request->user()?->id,
            'visible_cliente' => false,
        ]);

        return back()->with('success', 'Soporte adjuntado al pago.');
    }

    /**
     * DELETE /admin/processes/{process}/payments/{payment}/documents/{document}
     */
    public function destroyDocument(Request $request, Process $process, Payment $payment, Document $document): RedirectResponse
    {
        abort_unless($request->user()?->can('payments.manage'), 403);
        abort_unless($payment->process_id === $process->id, 404);
        abort_unless($document->payment_id === $payment->id, 404);

        $document->delete();

        return back()->with('success', 'Soporte eliminado.');
    }

    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'monto' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'fecha_pago' => ['required', 'date'],
            'concepto' => ['required', 'string', 'max:200'],
            'metodo' => ['required', Rule::in(Payment::METODOS)],
            'referencia' => ['nullable', 'string', 'max:120'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
