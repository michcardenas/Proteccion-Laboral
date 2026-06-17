<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    stats: { type: Object, required: true },
    serie: { type: Array, default: () => [] },
    porMetodo: { type: Array, default: () => [] },
    topProcesos: { type: Array, default: () => [] },
    pagos: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ mes: '', metodo: null }) },
    filterOptions: { type: Object, default: () => ({ metodos: [], meses: [] }) },
});

const filterMes = ref(props.filters.mes ?? '');
const filterMetodo = ref(props.filters.metodo ?? '');

// ── Presentación de métodos de pago ──
const METODO_META = {
    efectivo: { label: 'Efectivo', color: '#f59e0b', soft: 'bg-amber-100 text-amber-700 ring-amber-200' },
    transferencia: { label: 'Transferencia', color: '#10b981', soft: 'bg-emerald-100 text-emerald-700 ring-emerald-200' },
    consignacion: { label: 'Consignación', color: '#0ea5e9', soft: 'bg-sky-100 text-sky-700 ring-sky-200' },
    tarjeta: { label: 'Tarjeta', color: '#8b5cf6', soft: 'bg-violet-100 text-violet-700 ring-violet-200' },
    cheque: { label: 'Cheque', color: '#f43f5e', soft: 'bg-rose-100 text-rose-700 ring-rose-200' },
    otro: { label: 'Otro', color: '#64748b', soft: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

const metodoMeta = (m) => METODO_META[m] ?? { label: m, color: '#64748b', soft: 'bg-slate-100 text-slate-600 ring-slate-200' };

// ── Formateo de moneda COP ──
function formatCOP(v) {
    const n = Number(v) || 0;
    return n.toLocaleString('es-CO', {
        style: 'currency',
        currency: 'COP',
        maximumFractionDigits: 0,
    });
}

// Versión compacta para ejes y chips ($1,2 M / $850 k).
function formatCompact(v) {
    const n = Number(v) || 0;
    if (n >= 1_000_000) return '$' + (n / 1_000_000).toFixed(n >= 10_000_000 ? 0 : 1).replace('.', ',') + ' M';
    if (n >= 1_000) return '$' + Math.round(n / 1_000) + ' k';
    return '$' + Math.round(n);
}

function capitalize(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
}

function mesLabel(mes) {
    if (!mes) return '';
    const [y, m] = mes.split('-');
    const d = new Date(Number(y), Number(m) - 1, 1);
    return d.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });
}

function formatDate(iso) {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return new Date(Number(y), Number(m) - 1, Number(d)).toLocaleDateString('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
}

function applyFilters() {
    router.get(
        route('admin.payments.index'),
        {
            mes: filterMes.value || undefined,
            metodo: filterMetodo.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetFilters() {
    filterMes.value = '';
    filterMetodo.value = '';
    applyFilters();
}

function goToMonth(mes) {
    if (!mes) return;
    filterMes.value = mes;
    applyFilters();
}

const maxSerie = computed(() => Math.max(0, ...props.serie.map((s) => s.total)));
const totalMetodos = computed(() => props.porMetodo.reduce((a, b) => a + b.total, 0));
const variacionPositiva = computed(() => (props.stats.variacion_mes ?? 0) >= 0);

// ════════════════════════════════════════════════
//  Gráfica de área: recaudo de los últimos 12 meses
// ════════════════════════════════════════════════
const areaSeries = computed(() => [{
    name: 'Recaudo',
    data: props.serie.map((s) => Math.round(s.total)),
}]);

const areaOptions = computed(() => ({
    chart: {
        type: 'area',
        fontFamily: 'inherit',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: true, easing: 'easeinout', speed: 650 },
        events: {
            markerClick: (event, ctx, { dataPointIndex }) => goToMonth(props.serie[dataPointIndex]?.mes),
            dataPointSelection: (event, ctx, { dataPointIndex }) => goToMonth(props.serie[dataPointIndex]?.mes),
        },
    },
    colors: ['#10b981'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 3, lineCap: 'round' },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            type: 'vertical',
            colorStops: [
                { offset: 0, color: '#10b981', opacity: 0.45 },
                { offset: 100, color: '#10b981', opacity: 0.02 },
            ],
        },
    },
    grid: {
        borderColor: '#eef2f6',
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
        padding: { left: 12, right: 12, top: 8 },
    },
    markers: {
        size: 4,
        colors: ['#ffffff'],
        strokeColors: '#10b981',
        strokeWidth: 2,
        hover: { size: 7 },
    },
    xaxis: {
        categories: props.serie.map((s) => capitalize(s.label)),
        axisBorder: { show: false },
        axisTicks: { show: false },
        crosshairs: { stroke: { color: '#cbd5e1', dashArray: 4 } },
        tooltip: { enabled: false },
        labels: { style: { colors: '#94a3b8', fontSize: '11px', fontWeight: 500 } },
    },
    yaxis: {
        labels: {
            formatter: (v) => formatCompact(v),
            style: { colors: '#94a3b8', fontSize: '11px' },
        },
    },
    tooltip: {
        theme: 'light',
        x: { show: true },
        y: { formatter: (v) => formatCOP(v) },
        marker: { show: true },
    },
}));

// ════════════════════════════════════════════════
//  Dona: desglose por método del mes
// ════════════════════════════════════════════════
const donutSeries = computed(() => props.porMetodo.map((r) => Math.round(r.total)));

const donutOptions = computed(() => ({
    chart: {
        type: 'donut',
        fontFamily: 'inherit',
        animations: { enabled: true, easing: 'easeinout', speed: 650 },
    },
    labels: props.porMetodo.map((r) => metodoMeta(r.metodo).label),
    colors: props.porMetodo.map((r) => metodoMeta(r.metodo).color),
    stroke: { width: 3, colors: ['#ffffff'] },
    dataLabels: {
        enabled: true,
        formatter: (val) => Math.round(val) + '%',
        style: { fontSize: '11px', fontWeight: 700, colors: ['#ffffff'] },
        dropShadow: { enabled: false },
    },
    legend: { show: false },
    plotOptions: {
        pie: {
            expandOnClick: false,
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    name: { fontSize: '12px', color: '#94a3b8' },
                    value: {
                        fontSize: '20px',
                        fontWeight: 700,
                        color: '#0f172a',
                        formatter: (v) => formatCompact(v),
                    },
                    total: {
                        show: true,
                        label: 'Total',
                        fontSize: '12px',
                        color: '#94a3b8',
                        formatter: () => formatCompact(totalMetodos.value),
                    },
                },
            },
        },
    },
    tooltip: { y: { formatter: (v) => formatCOP(v) } },
}));

const metodoLegend = computed(() => props.porMetodo.map((r) => ({
    metodo: r.metodo,
    label: metodoMeta(r.metodo).label,
    color: metodoMeta(r.metodo).color,
    total: r.total,
    pct: totalMetodos.value > 0 ? Math.round((r.total / totalMetodos.value) * 100) : 0,
})));
</script>

<template>
    <Head title="Facturación" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Facturación</h2>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

                <!-- Hero -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-700 via-teal-700 to-cyan-700 px-8 py-10 text-white shadow-lg">
                    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="absolute -bottom-16 -left-16 h-56 w-56 rounded-full bg-white/5 blur-3xl"></div>
                    <div class="relative flex flex-wrap items-end justify-between gap-6">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-emerald-200">Reporte de finanzas</p>
                            <h1 class="mt-2 text-3xl font-bold capitalize">{{ stats.mes_nombre }}</h1>
                            <p class="mt-2 max-w-2xl text-sm text-emerald-100">
                                Constancia de pagos recibidos de los procesos: cuánto se ha cobrado en el mes,
                                cómo se distribuye por método y en qué procesos se concentra.
                            </p>
                        </div>
                        <div class="rounded-xl bg-white/10 px-6 py-4 backdrop-blur-sm">
                            <p class="text-xs uppercase tracking-wider text-emerald-100">Recaudado este mes</p>
                            <p class="mt-1 text-3xl font-bold">{{ formatCOP(stats.total_mes) }}</p>
                            <p v-if="stats.variacion_mes !== null" class="mt-1 text-xs"
                               :class="variacionPositiva ? 'text-emerald-100' : 'text-rose-200'">
                                <span>{{ variacionPositiva ? '▲' : '▼' }}</span>
                                {{ Math.abs(stats.variacion_mes) }}% vs. mes anterior
                                ({{ formatCompact(stats.total_mes_anterior) }})
                            </p>
                            <p v-else class="mt-1 text-xs text-emerald-100">Sin datos del mes anterior</p>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Mes</label>
                            <select v-model="filterMes" @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm capitalize shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">Mes actual</option>
                                <option v-for="m in filterOptions.meses" :key="m" :value="m" class="capitalize">{{ mesLabel(m) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Método</label>
                            <select v-model="filterMetodo" @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">Todos</option>
                                <option v-for="m in filterOptions.metodos" :key="m" :value="m">{{ metodoMeta(m).label }}</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button @click="resetFilters"
                                class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                                Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>

                <!-- KPI cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Total del mes (destacado) -->
                    <div class="relative overflow-hidden rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 p-5 text-white shadow-lg">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-emerald-100">Total del mes</p>
                                <p class="mt-2 text-3xl font-bold">{{ formatCompact(stats.total_mes) }}</p>
                                <p class="mt-1 text-xs text-emerald-100">{{ formatCOP(stats.total_mes) }}</p>
                            </div>
                            <div class="rounded-lg bg-white/20 p-2 backdrop-blur-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- N° de pagos -->
                    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pagos registrados</p>
                                <p class="mt-2 text-4xl font-bold text-slate-900">{{ stats.count_mes }}</p>
                                <p class="mt-1 text-xs text-slate-500">en {{ stats.mes_nombre }}</p>
                            </div>
                            <div class="rounded-lg bg-sky-50 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-sky-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Ticket promedio -->
                    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pago promedio</p>
                                <p class="mt-2 text-3xl font-bold text-slate-900">{{ formatCompact(stats.ticket_promedio) }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ formatCOP(stats.ticket_promedio) }}</p>
                            </div>
                            <div class="rounded-lg bg-violet-50 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-violet-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 01-2.031.352 5.988 5.988 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 01-2.031.352 5.989 5.989 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971z"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Total del año -->
                    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Acumulado {{ stats.anio }}</p>
                                <p class="mt-2 text-3xl font-bold text-slate-900">{{ formatCompact(stats.total_anio) }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ formatCOP(stats.total_anio) }}</p>
                            </div>
                            <div class="rounded-lg bg-emerald-50 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-emerald-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficas -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Área: serie mensual -->
                    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900">Recaudo por mes</h2>
                                <p class="text-xs text-slate-500">Últimos 12 meses · clic en un punto para ver ese mes</p>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">Pico {{ formatCompact(maxSerie) }}</span>
                        </div>

                        <VueApexCharts type="area" height="290" :options="areaOptions" :series="areaSeries" class="mt-2" />
                    </div>

                    <!-- Dona: por método -->
                    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-slate-900">Por método</h2>
                        <p class="text-xs text-slate-500">Distribución del mes</p>

                        <div v-if="porMetodo.length" class="mt-2">
                            <VueApexCharts type="donut" height="230" :options="donutOptions" :series="donutSeries" />

                            <ul class="mt-4 space-y-2">
                                <li v-for="seg in metodoLegend" :key="seg.metodo" class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: seg.color }"></span>
                                        <span class="text-slate-700">{{ seg.label }}</span>
                                    </span>
                                    <span class="text-slate-500">
                                        <span class="font-medium text-slate-700">{{ formatCompact(seg.total) }}</span>
                                        <span class="ml-1 text-xs text-slate-400">{{ seg.pct }}%</span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div v-else class="mt-8 flex flex-col items-center justify-center py-10 text-center text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-10 w-10 text-slate-300">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"/>
                            </svg>
                            <p class="mt-2 text-sm font-medium">Sin pagos este mes</p>
                        </div>
                    </div>
                </div>

                <!-- Top procesos + tabla -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Top procesos -->
                    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-slate-900">Top procesos del mes</h2>
                        <p class="text-xs text-slate-500">Mayor recaudo</p>

                        <ul v-if="topProcesos.length" class="mt-4 space-y-3">
                            <li v-for="(p, i) in topProcesos" :key="p.process_id">
                                <Link :href="route('admin.processes.show', p.process_id)"
                                    class="group flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2.5 transition hover:border-emerald-200 hover:bg-emerald-50/40">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500 group-hover:bg-emerald-100 group-hover:text-emerald-700">{{ i + 1 }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-slate-800">{{ p.codigo || ('#' + p.process_id) }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ p.client_name || p.titulo || '—' }}</span>
                                    </span>
                                    <span class="text-right">
                                        <span class="block text-sm font-semibold text-emerald-700">{{ formatCompact(p.total) }}</span>
                                        <span class="block text-[11px] text-slate-400">{{ p.count }} pago{{ p.count === 1 ? '' : 's' }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="mt-8 py-6 text-center text-sm text-slate-400">Sin pagos este mes</p>
                    </div>

                    <!-- Tabla de pagos del mes -->
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900">Detalle de pagos</h2>
                                <p class="text-xs text-slate-500 capitalize">{{ stats.mes_nombre }}</p>
                            </div>
                            <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-medium text-slate-700">{{ pagos.length }} registros</span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-100">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Fecha</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Proceso / Cliente</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Concepto</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Método</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Monto</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="p in pagos" :key="p.id" class="transition-colors hover:bg-emerald-50/30">
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600">{{ formatDate(p.fecha_pago) }}</td>
                                        <td class="px-4 py-3">
                                            <Link :href="route('admin.processes.show', p.process_id)" class="text-sm font-medium text-slate-800 hover:text-emerald-700">
                                                {{ p.process_codigo || ('#' + p.process_id) }}
                                            </Link>
                                            <p class="truncate text-xs text-slate-500">{{ p.client_name || p.process_titulo || '—' }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-600">
                                            <p class="max-w-[16rem] truncate">{{ p.concepto }}</p>
                                            <p v-if="p.referencia" class="text-xs text-slate-400">Ref. {{ p.referencia }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                                :class="metodoMeta(p.metodo).soft">
                                                <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: metodoMeta(p.metodo).color }"></span>
                                                {{ metodoMeta(p.metodo).label }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-slate-900">{{ formatCOP(p.monto) }}</td>
                                    </tr>
                                    <tr v-if="pagos.length === 0">
                                        <td colspan="5" class="px-6 py-10 text-center text-sm text-slate-400">
                                            <p class="font-medium">No hay pagos en este periodo</p>
                                            <p class="mt-1 text-xs">Los pagos se registran dentro de cada proceso, en su pestaña “Pagos”.</p>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="pagos.length" class="border-t border-slate-200 bg-slate-50">
                                    <tr>
                                        <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Total mostrado</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-bold text-emerald-700">
                                            {{ formatCOP(pagos.reduce((a, b) => a + b.monto, 0)) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
