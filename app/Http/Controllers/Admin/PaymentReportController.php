<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporte global de pagos: tablero de finanzas con KPIs del mes/año,
 * serie mensual, desglose por método y top de procesos.
 *
 * Los agregados se calculan en PHP (no con DATE_FORMAT) para ser portables
 * entre MySQL (prod) y SQLite (tests), igual que AiGenerationController@index.
 */
class PaymentReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('payments.view'), 403);

        $user = $request->user();

        // Mes seleccionado (formato Y-m); por defecto el mes en curso.
        $mesParam = $request->query('mes');
        try {
            $base = $mesParam
                ? Carbon::createFromFormat('Y-m', $mesParam)->startOfMonth()
                : now()->startOfMonth();
        } catch (\Throwable $e) {
            $base = now()->startOfMonth();
        }

        $metodo = $request->query('metodo') ?: null;

        $startOfMonth = $base->copy()->startOfMonth();
        $endOfMonth = $base->copy()->endOfMonth();
        $windowStart = $base->copy()->subMonths(11)->startOfMonth(); // ventana de 12 meses

        // Query base con scoping: si el usuario solo ve procesos asignados,
        // limitamos los pagos a los de sus procesos.
        $scoped = function () use ($user) {
            $q = Payment::query();

            if (! $user->can('processes.view') && $user->can('processes.view_assigned')) {
                $q->whereHas('process', function ($p) use ($user) {
                    $p->where('abogado_lider_id', $user->id)
                        ->orWhere('apoderado_id', $user->id)
                        ->orWhere('coordinador_id', $user->id);
                });
            }

            return $q;
        };

        // Todos los pagos de la ventana de 12 meses (volumen pequeño: un despacho).
        // Con esto computamos serie mensual y KPIs sin tocar la BD por cada métrica.
        $windowPayments = $scoped()
            ->with([
                'process:id,codigo,titulo,client_id',
                'process.client:id,razon_social',
                'registradoPor:id,name',
            ])
            ->whereBetween('fecha_pago', [$windowStart, $endOfMonth])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id')
            ->get();

        // Pagos del mes seleccionado (ya filtrados en memoria desde la ventana).
        $mesPayments = $windowPayments->filter(
            fn (Payment $p) => $p->fecha_pago
                && $p->fecha_pago->betweenIncluded($startOfMonth, $endOfMonth)
        )->values();

        // Pagos del mes filtrados por método (para la tabla y el desglose visible).
        $mesFiltrados = $metodo
            ? $mesPayments->where('metodo', $metodo)->values()
            : $mesPayments;

        // ── Serie mensual (12 meses, del más viejo al más reciente) ──
        $serie = $this->serieMensual($windowPayments, $base);

        // ── Desglose por método del mes ──
        $porMetodo = collect(Payment::METODOS)
            ->map(function (string $m) use ($mesPayments) {
                $delMetodo = $mesPayments->where('metodo', $m);

                return [
                    'metodo' => $m,
                    'total' => (float) $delMetodo->sum('monto'),
                    'count' => $delMetodo->count(),
                ];
            })
            ->filter(fn ($row) => $row['count'] > 0)
            ->sortByDesc('total')
            ->values();

        // ── Top procesos del mes por monto ──
        $topProcesos = $mesPayments
            ->groupBy(fn (Payment $p) => $p->process_id)
            ->map(function (Collection $pagos) {
                $first = $pagos->first();

                return [
                    'process_id' => $first->process_id,
                    'codigo' => $first->process?->codigo,
                    'titulo' => $first->process?->titulo,
                    'client_name' => $first->process?->client?->razon_social,
                    'total' => (float) $pagos->sum('monto'),
                    'count' => $pagos->count(),
                ];
            })
            ->sortByDesc('total')
            ->take(5)
            ->values();

        // ── KPIs ──
        $totalMes = (float) $mesPayments->sum('monto');
        $countMes = $mesPayments->count();

        $mesAnteriorInicio = $base->copy()->subMonth()->startOfMonth();
        $mesAnteriorFin = $base->copy()->subMonth()->endOfMonth();
        $totalMesAnterior = (float) $windowPayments
            ->filter(fn (Payment $p) => $p->fecha_pago
                && $p->fecha_pago->betweenIncluded($mesAnteriorInicio, $mesAnteriorFin))
            ->sum('monto');

        $variacionMes = $totalMesAnterior > 0
            ? round((($totalMes - $totalMesAnterior) / $totalMesAnterior) * 100, 1)
            : null;

        // Total del año del mes seleccionado y total histórico (consultas aparte).
        $totalAnio = (float) $scoped()
            ->whereBetween('fecha_pago', [
                $base->copy()->startOfYear(),
                $base->copy()->endOfYear(),
            ])
            ->sum('monto');

        $totalHistorico = (float) $scoped()->sum('monto');

        return Inertia::render('Admin/Payments/Index', [
            'stats' => [
                'total_mes' => $totalMes,
                'count_mes' => $countMes,
                'ticket_promedio' => $countMes > 0 ? round($totalMes / $countMes, 2) : 0,
                'total_mes_anterior' => $totalMesAnterior,
                'variacion_mes' => $variacionMes,
                'total_anio' => $totalAnio,
                'total_historico' => $totalHistorico,
                'mes' => $base->format('Y-m'),
                'mes_nombre' => $base->locale('es')->isoFormat('MMMM [de] YYYY'),
                'anio' => $base->format('Y'),
            ],
            'serie' => $serie,
            'porMetodo' => $porMetodo,
            'topProcesos' => $topProcesos,
            'pagos' => $mesFiltrados->map(fn (Payment $p) => [
                'id' => $p->id,
                'monto' => (float) $p->monto,
                'fecha_pago' => $p->fecha_pago?->format('Y-m-d'),
                'concepto' => $p->concepto,
                'metodo' => $p->metodo,
                'referencia' => $p->referencia,
                'process_id' => $p->process_id,
                'process_codigo' => $p->process?->codigo,
                'process_titulo' => $p->process?->titulo,
                'client_name' => $p->process?->client?->razon_social,
                'registrado_por' => $p->registradoPor?->name,
            ]),
            'filters' => [
                'mes' => $base->format('Y-m'),
                'metodo' => $metodo,
            ],
            'filterOptions' => [
                'metodos' => Payment::METODOS,
                // Meses (Y-m) con datos, calculado en PHP para portabilidad.
                'meses' => $scoped()
                    ->orderByDesc('fecha_pago')
                    ->pluck('fecha_pago')
                    ->map(fn ($d) => $d ? Carbon::parse($d)->format('Y-m') : null)
                    ->filter()
                    ->unique()
                    ->values(),
            ],
        ]);
    }

    /**
     * Serie de los últimos 12 meses (incluido el seleccionado), del más
     * antiguo al más reciente, con total y conteo por mes.
     *
     * @param  Collection<int, Payment>  $windowPayments
     * @return array<int, array{mes: string, label: string, total: float, count: int}>
     */
    private function serieMensual(Collection $windowPayments, Carbon $base): array
    {
        // Agrupa los pagos por su clave Y-m una sola vez.
        $porMes = $windowPayments->groupBy(
            fn (Payment $p) => $p->fecha_pago?->format('Y-m')
        );

        $serie = [];
        for ($i = 11; $i >= 0; $i--) {
            $mes = $base->copy()->subMonths($i);
            $clave = $mes->format('Y-m');
            $pagos = $porMes->get($clave, collect());

            $serie[] = [
                'mes' => $clave,
                'label' => $mes->locale('es')->isoFormat('MMM'),
                'total' => (float) $pagos->sum('monto'),
                'count' => $pagos->count(),
            ];
        }

        return $serie;
    }
}
