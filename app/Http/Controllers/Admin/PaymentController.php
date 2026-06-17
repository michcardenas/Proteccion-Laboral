<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
