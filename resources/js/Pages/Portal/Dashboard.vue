<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineProps({
    client: { type: Object, default: () => ({}) },
    processes: { type: Array, default: () => [] },
});

const estadoLabels = {
    abierto: 'Abierto',
    en_curso: 'En curso',
    en_revision: 'En revisión',
    cerrado: 'Cerrado',
    archivado: 'Archivado',
};
const prettify = (v) => (!v ? '—' : v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, ' '));
const labelEstado = (v) => estadoLabels[v] ?? prettify(v);

const estadoTone = {
    abierto: 'bg-blue-50 text-blue-700 ring-blue-200',
    en_curso: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    en_revision: 'bg-amber-50 text-amber-800 ring-amber-200',
    cerrado: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    archivado: 'bg-slate-100 text-slate-600 ring-slate-300',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
</script>

<template>
    <Head title="Mis procesos" />

    <PortalLayout>
        <div class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">
                Hola, {{ client.razon_social }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Este es el estado de {{ processes.length === 1 ? 'tu proceso' : 'tus procesos' }} con Protección Laboral.
            </p>
        </div>

        <div v-if="!processes.length" class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="text-sm text-slate-500">Aún no tienes procesos activos para mostrar.</p>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Link
                v-for="(p, idx) in processes"
                :key="p.id"
                :href="route('portal.process', p.id)"
                class="portal-card group flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md"
                :style="{ animationDelay: `${Math.min(idx, 8) * 60}ms` }"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ p.codigo }}</p>
                        <h2 class="mt-0.5 truncate text-sm font-semibold text-slate-900">{{ p.titulo }}</h2>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset" :class="estadoTone[p.estado] || estadoTone.archivado">
                        {{ labelEstado(p.estado) }}
                    </span>
                </div>

                <p class="mt-2 text-xs text-slate-500">
                    <span v-if="p.service">{{ p.service }}</span>
                    <span v-if="p.lider"> · A cargo de {{ p.lider }}</span>
                </p>

                <!-- Avance -->
                <div class="mt-4">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-slate-500">Avance</span>
                        <span class="font-semibold text-slate-800">{{ p.percent }}%</span>
                    </div>
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-brand-900 to-indigo-600 transition-all duration-700"
                            :style="{ width: `${p.percent}%` }"
                        />
                    </div>
                    <p class="mt-1.5 text-[11px] text-slate-400">
                        {{ p.completed_stages_count }} de {{ p.stages_count }} etapas completadas
                    </p>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                    <span class="text-[11px] text-slate-400">Apertura {{ formatDate(p.fecha_apertura) }}</span>
                    <span class="text-xs font-semibold text-indigo-600 transition group-hover:translate-x-0.5">Ver detalle →</span>
                </div>
            </Link>
        </div>
    </PortalLayout>
</template>

<style scoped>
.portal-card {
    animation: cardIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
    .portal-card { animation: none; }
}
</style>
