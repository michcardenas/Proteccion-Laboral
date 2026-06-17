<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineProps({
    client: { type: Object, default: () => ({}) },
    process: { type: Object, required: true },
});

const estadoLabels = {
    abierto: 'Abierto', en_curso: 'En curso', en_revision: 'En revisión',
    cerrado: 'Cerrado', archivado: 'Archivado',
};
const stageLabels = {
    pendiente: 'Pendiente', en_curso: 'En curso', bloqueada: 'Bloqueada', completada: 'Completada',
};
const tipoVisita = {
    presencial: 'Presencial', virtual: 'Virtual', telefonica: 'Telefónica', otro: 'Otro',
};
const metodoPago = {
    efectivo: 'Efectivo', transferencia: 'Transferencia', consignacion: 'Consignación',
    tarjeta: 'Tarjeta', cheque: 'Cheque', otro: 'Otro',
};
const fmtMoneda = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n ?? 0);
const prettify = (v) => (!v ? '—' : v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, ' '));
const labelEstado = (v) => estadoLabels[v] ?? prettify(v);
const labelStage = (v) => stageLabels[v] ?? prettify(v);

const estadoTone = {
    abierto: 'bg-blue-50 text-blue-700 ring-blue-200',
    en_curso: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    en_revision: 'bg-amber-50 text-amber-800 ring-amber-200',
    cerrado: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    archivado: 'bg-slate-100 text-slate-600 ring-slate-300',
};

// Color de la barra según el estado de la etapa.
const stageBar = (estado) => ({
    completada: 'from-emerald-500 to-emerald-600',
    en_curso: 'from-brand-900 to-indigo-600',
    bloqueada: 'from-rose-400 to-rose-500',
    pendiente: 'from-slate-300 to-slate-400',
}[estado] || 'from-slate-300 to-slate-400');

const tipoVisitaTone = {
    presencial: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    virtual: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    telefonica: 'bg-amber-50 text-amber-800 ring-amber-200',
    otro: 'bg-slate-100 text-slate-600 ring-slate-300',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString('es-CO', { day: '2-digit', month: 'long', year: 'numeric' }) : '—');
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
</script>

<template>
    <Head :title="`Proceso ${process.codigo}`" />

    <PortalLayout>
        <!-- Volver -->
        <Link :href="route('portal.dashboard')" class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-brand-900">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
            </svg>
            Mis procesos
        </Link>

        <!-- Hero -->
        <section class="portal-in rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ process.codigo }}</p>
                    <h1 class="mt-0.5 text-lg font-semibold text-slate-900 sm:text-xl">{{ process.titulo }}</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        <span v-if="process.service">{{ process.service }}</span>
                        <span v-if="process.lider"> · A cargo de {{ process.lider }}</span>
                    </p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset" :class="estadoTone[process.estado] || estadoTone.archivado">
                    {{ labelEstado(process.estado) }}
                </span>
            </div>

            <!-- Avance global -->
            <div class="mt-6">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-medium text-slate-600">Avance general</span>
                    <span class="font-semibold text-slate-900">{{ process.progress.percent }}%</span>
                </div>
                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-brand-900 to-indigo-600 transition-all duration-700"
                        :style="{ width: `${process.progress.percent}%` }"
                    />
                </div>
            </div>
        </section>

        <!-- Resumen de la IA -->
        <section class="portal-in mt-5 overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-violet-50 shadow-sm" style="animation-delay: 80ms">
            <div class="flex items-center gap-2 border-b border-indigo-100/70 px-5 py-4">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                    </svg>
                </span>
                <div>
                    <p class="text-sm font-semibold text-indigo-900">¿Cómo va tu proceso?</p>
                    <p class="text-[11px] text-indigo-700/70">Resumen preparado con IA</p>
                </div>
            </div>
            <div class="px-5 py-4">
                <p v-if="process.resumen_ia" class="whitespace-pre-line text-sm leading-relaxed text-indigo-950/90">{{ process.resumen_ia }}</p>
                <p v-else class="text-sm italic text-indigo-700/70">
                    El resumen de tu proceso estará disponible pronto.
                </p>
            </div>
        </section>

        <!-- Plan de trabajo (etapas como barras) -->
        <section class="portal-in mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" style="animation-delay: 140ms">
            <div class="flex items-center gap-2">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-900 text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                <h2 class="text-sm font-semibold text-slate-900">Plan de trabajo</h2>
            </div>

            <p v-if="!process.plan.length" class="mt-4 text-sm italic text-slate-400">
                Aún no hay un plan de trabajo cargado para este proceso.
            </p>

            <ol v-else class="mt-5 space-y-5">
                <li v-for="(etapa, idx) in process.plan" :key="etapa.id" class="plan-row" :style="{ animationDelay: `${idx * 60}ms` }">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-bold text-slate-500">
                                {{ etapa.orden }}
                            </span>
                            <p class="truncate text-sm font-medium text-slate-800">{{ etapa.nombre }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset" :class="estadoTone[etapa.estado] || estadoTone.archivado">
                            {{ labelStage(etapa.estado) }}
                        </span>
                    </div>

                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full rounded-full bg-gradient-to-r transition-all duration-700"
                            :class="stageBar(etapa.estado)"
                            :style="{ width: `${etapa.percent}%` }"
                        />
                    </div>

                    <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 text-[11px] text-slate-400">
                        <span v-if="etapa.items_total > 0">{{ etapa.items_done }}/{{ etapa.items_total }} ítems · {{ etapa.percent }}%</span>
                        <span v-else>{{ etapa.percent }}%</span>
                        <span v-if="etapa.fecha_limite">Fecha límite {{ formatDate(etapa.fecha_limite) }}</span>
                    </div>
                </li>
            </ol>
        </section>

        <!-- Visitas -->
        <section class="portal-in mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" style="animation-delay: 200ms">
            <div class="flex items-center gap-2">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-900 text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </span>
                <h2 class="text-sm font-semibold text-slate-900">Visitas y reuniones</h2>
            </div>

            <p v-if="!process.visits.length" class="mt-4 text-sm italic text-slate-400">
                Todavía no hay visitas registradas para este proceso.
            </p>

            <ol v-else class="relative mt-5 space-y-4 border-l-2 border-slate-100 pl-6">
                <li v-for="(v, idx) in process.visits" :key="v.id" class="plan-row relative" :style="{ animationDelay: `${idx * 60}ms` }">
                    <span class="absolute -left-[31px] top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 ring-4 ring-white" />
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-800">{{ v.titulo }}</p>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset" :class="tipoVisitaTone[v.tipo] || tipoVisitaTone.otro">
                                {{ tipoVisita[v.tipo] || v.tipo }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-[11px] text-slate-400">{{ formatDate(v.fecha) }}<span v-if="v.registrada_por"> · {{ v.registrada_por }}</span></p>
                        <p v-if="v.descripcion" class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ v.descripcion }}</p>

                        <div v-if="v.asistentes && v.asistentes.length" class="mt-2 flex flex-wrap gap-1">
                            <span v-for="a in v.asistentes" :key="a" class="rounded-full bg-slate-50 px-2 py-0.5 text-[10px] text-slate-500 ring-1 ring-inset ring-slate-200">{{ a }}</span>
                        </div>

                        <div v-if="v.documentos && v.documentos.length" class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                            <a
                                v-for="d in v.documentos"
                                :key="d.id"
                                :href="d.url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 rounded-md bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200 transition hover:text-indigo-700 hover:ring-indigo-300"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-3.5 w-3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                                {{ d.nombre }}
                            </a>
                        </div>
                    </div>
                </li>
            </ol>
        </section>

        <!-- Pagos (constancia para el cliente) -->
        <section class="portal-in mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" style="animation-delay: 260ms">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 text-emerald-600"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M3.75 19.5h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"/></svg>
                    <h2 class="text-sm font-semibold text-slate-900">Constancia de pagos</h2>
                </div>
                <span v-if="process.pagos && process.pagos.length" class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-200">
                    Total: {{ fmtMoneda(process.pagos_total) }}
                </span>
            </div>

            <p v-if="!process.pagos || !process.pagos.length" class="mt-4 text-sm italic text-slate-400">
                Aún no hay pagos registrados.
            </p>

            <ul v-else class="mt-4 divide-y divide-slate-100">
                <li v-for="(p, idx) in process.pagos" :key="p.id" class="plan-row flex items-center justify-between gap-4 py-3" :style="{ animationDelay: `${idx * 60}ms` }">
                    <div>
                        <p class="text-sm font-medium text-slate-800">{{ p.concepto }}</p>
                        <p class="text-xs text-slate-400">
                            {{ p.fecha_pago }} · {{ metodoPago[p.metodo] || p.metodo }}<span v-if="p.referencia"> · Ref: {{ p.referencia }}</span>
                        </p>
                    </div>
                    <span class="whitespace-nowrap text-sm font-semibold text-emerald-700">{{ fmtMoneda(p.monto) }}</span>
                </li>
            </ul>
        </section>
    </PortalLayout>
</template>

<style scoped>
.portal-in {
    animation: portalIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes portalIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.plan-row {
    animation: rowIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes rowIn {
    from { opacity: 0; transform: translateX(-6px); }
    to { opacity: 1; transform: translateX(0); }
}
@media (prefers-reduced-motion: reduce) {
    .portal-in, .plan-row { animation: none; }
}
</style>
