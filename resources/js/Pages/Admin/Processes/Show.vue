<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import AiDraftModal from '@/Components/AiDraftModal.vue';

const props = defineProps({
    process: Object,
    aiTemplates: { type: Array, default: () => [] },
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const tabs = [
    { key: 'tablero', label: 'Tablero de etapas' },
    { key: 'detalle', label: 'Detalle' },
    { key: 'tareas', label: 'Tareas' },
    { key: 'documentos', label: 'Documentos' },
    { key: 'comentarios', label: 'Comentarios' },
    { key: 'historial', label: 'Historial' },
];

// --- Historial: textos, iconos, filtro y agrupación por día ---
const eventoTexto = (h) => {
    const sujeto = h.tipo === 'tarea' ? 'la tarjeta' : 'el proceso';
    const labels = {
        created: `creó ${sujeto}`,
        updated: `actualizó ${sujeto}`,
        deleted: h.tipo === 'tarea' ? 'eliminó la tarjeta' : 'archivó el proceso',
        restored: `restauró ${sujeto}`,
    };
    return labels[h.evento] || h.descripcion;
};

const eventoTheme = {
    created: {
        circle: 'bg-emerald-100 text-emerald-700 ring-emerald-200/70',
        icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>',
    },
    updated: {
        circle: 'bg-blue-100 text-blue-700 ring-blue-200/70',
        icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487z"/></svg>',
    },
    deleted: {
        circle: 'bg-rose-100 text-rose-700 ring-rose-200/70',
        icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>',
    },
    restored: {
        circle: 'bg-indigo-100 text-indigo-700 ring-indigo-200/70',
        icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>',
    },
    default: {
        circle: 'bg-slate-100 text-slate-600 ring-slate-200/70',
        icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l3.5 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    },
};
const temaDe = (h) => eventoTheme[h.evento] || eventoTheme.default;

const historialFiltro = ref('todos');
const historialFiltros = computed(() => {
    const items = props.process.historial ?? [];
    return [
        { key: 'todos', label: 'Todo', count: items.length },
        { key: 'proceso', label: 'Proceso', count: items.filter((h) => h.tipo === 'proceso').length },
        { key: 'tarea', label: 'Kanban', count: items.filter((h) => h.tipo === 'tarea').length },
    ];
});
const historialVisible = computed(() => {
    const items = props.process.historial ?? [];
    return historialFiltro.value === 'todos' ? items : items.filter((h) => h.tipo === historialFiltro.value);
});

const dayLabel = (iso) => {
    const d = new Date(iso);
    d.setHours(0, 0, 0, 0);
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    const dias = Math.round((hoy - d) / 86400000);
    if (dias === 0) return 'Hoy';
    if (dias === 1) return 'Ayer';
    return new Date(iso).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};
// El historial viene ordenado del más reciente al más antiguo; lo partimos por día.
const historialPorDia = computed(() => {
    const grupos = [];
    let actual = null;
    for (const h of historialVisible.value) {
        const d = h.created_at ? new Date(h.created_at) : null;
        const key = d ? `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}` : 'sin-fecha';
        if (!actual || actual.key !== key) {
            actual = { key, label: d ? dayLabel(h.created_at) : 'Sin fecha', items: [] };
            grupos.push(actual);
        }
        actual.items.push(h);
    }
    return grupos;
});
const formatTime = (iso) => iso ? new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }) : '';

const activeTab = ref('tablero');

const showAiModal = ref(false);
const onAiSaved = ({ kind }) => {
    activeTab.value = kind === 'comment' ? 'comentarios' : 'documentos';
    router.reload({ only: ['process'] });
};

// --- Resumen ejecutivo del proceso (IA) ---
const resumenIa = ref(props.process.resumen_ia);
const resumenIaAt = ref(props.process.resumen_ia_generado_at);
const generandoResumen = ref(false);
const resumenError = ref(null);

const xsrf = () => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
};

async function generarResumen() {
    generandoResumen.value = true;
    resumenError.value = null;
    try {
        const res = await fetch(route('admin.processes.ai.summary', props.process.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error();
        const data = await res.json();
        resumenIa.value = data.resumen_ia;
        resumenIaAt.value = data.resumen_ia_generado_at;
    } catch (e) {
        resumenError.value = 'No se pudo generar el resumen. Intenta de nuevo.';
    } finally {
        generandoResumen.value = false;
    }
}

const estadoVariants = {
    abierto: 'blue',
    en_curso: 'indigo',
    en_revision: 'yellow',
    cerrado: 'green',
    archivado: 'gray',
};

const stageEstadoVariants = {
    pendiente: 'gray',
    en_curso: 'indigo',
    bloqueada: 'red',
    completada: 'green',
};

const taskEstadoVariants = {
    pendiente: 'gray',
    en_curso: 'indigo',
    bloqueada: 'red',
    completada: 'green',
    cancelada: 'gray',
};

const taskPriorityVariants = {
    baja: 'gray',
    media: 'blue',
    alta: 'yellow',
    urgente: 'red',
};

const modalidadVariants = {
    permanente: 'purple',
    por_evento: 'indigo',
    judicial: 'red',
    estrategico: 'blue',
    capacitacion: 'green',
    prediagnostico: 'yellow',
};

// --- Etiquetas legibles para los estados/modalidades (snake_case → "Título Bonito") ---
const estadoLabels = {
    abierto: 'Abierto',
    en_curso: 'En curso',
    en_revision: 'En revisión',
    cerrado: 'Cerrado',
    archivado: 'Archivado',
    pendiente: 'Pendiente',
    bloqueada: 'Bloqueada',
    completada: 'Completada',
    cancelada: 'Cancelada',
};
const prioridadLabels = {
    baja: 'Baja',
    media: 'Media',
    alta: 'Alta',
    urgente: 'Urgente',
};
const modalidadLabels = {
    permanente: 'Permanente',
    por_evento: 'Por evento',
    judicial: 'Judicial',
    estrategico: 'Estratégico',
    capacitacion: 'Capacitación',
    prediagnostico: 'Prediagnóstico',
};
// Fallback: cualquier valor sin etiqueta explícita se capitaliza y se reemplazan "_" por espacios.
const prettify = (v) => {
    if (!v) return '—';
    return v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, ' ');
};
const labelEstado = (v) => estadoLabels[v] ?? prettify(v);
const labelPrioridad = (v) => prioridadLabels[v] ?? prettify(v);
const labelModalidad = (v) => modalidadLabels[v] ?? prettify(v);

const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('es-CO') : '—';
const formatDateTime = (iso) => iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) : '—';

const initialsFor = (name) => name?.split(' ').map(n => n[0]).slice(0, 2).join('') ?? '?';

const toggleChecklist = (stage, item) => {
    router.patch(
        route('admin.processes.stages.checklist.toggle', [props.process.id, stage.id, item.id]),
        {},
        { preserveScroll: true }
    );
};

const completeStage = (stage) => {
    router.patch(
        route('admin.processes.stages.complete', [props.process.id, stage.id]),
        {},
        { preserveScroll: true }
    );
};

const reopenStage = (stage) => {
    router.patch(
        route('admin.processes.stages.reopen', [props.process.id, stage.id]),
        {},
        { preserveScroll: true }
    );
};

const showDelete = ref(false);
const performDelete = () => {
    router.delete(route('admin.processes.destroy', props.process.id), {
        onFinish: () => { showDelete.value = false; },
    });
};

const stageProgress = (stage) => {
    const total = stage.checklist.length;
    if (total === 0) return 0;
    const done = stage.checklist.filter((i) => i.completado).length;
    return Math.round((done / total) * 100);
};

const allRequiredDone = (stage) =>
    stage.checklist.filter((i) => i.es_obligatorio).every((i) => i.completado);

const isLate = (stage) => {
    if (!stage.fecha_limite || stage.estado === 'completada') return false;
    return new Date(stage.fecha_limite) < new Date();
};
</script>

<template>
    <Head :title="`Proceso ${process.codigo}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.processes.index')" class="text-slate-400 transition hover:text-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold text-slate-900 sm:text-xl">{{ process.codigo }}</h1>
                    <p class="truncate text-xs text-slate-500">{{ process.titulo }}</p>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Hero + Resumen IA del proceso -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-semibold text-slate-900">{{ process.titulo }}</h2>
                            <StatusBadge :variant="estadoVariants[process.estado] || 'gray'" :label="labelEstado(process.estado)" />
                            <StatusBadge
                                v-if="process.service"
                                :variant="modalidadVariants[process.service.modalidad] || 'gray'"
                                :label="labelModalidad(process.service.modalidad)"
                            />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            <Link v-if="process.client" :href="route('admin.clients.show', process.client.id)" class="font-medium text-brand-900 hover:underline">
                                {{ process.client.razon_social }}
                            </Link>
                            <span v-if="process.contract">
                                · Contrato
                                <Link :href="route('admin.contracts.show', process.contract.id)" class="font-medium text-brand-900 hover:underline">{{ process.contract.codigo }}</Link>
                            </span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="can('ai.use')"
                            @click="showAiModal = true"
                            class="inline-flex items-center gap-1.5 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 transition hover:bg-indigo-100"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                            Generar borrador IA
                        </button>
                        <Link
                            v-if="can('tasks.view')"
                            :href="route('admin.processes.board', process.id)"
                            class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15M5.25 4.5h13.5A1.5 1.5 0 0120.25 6v12a1.5 1.5 0 01-1.5 1.5H5.25A1.5 1.5 0 013.75 18V6a1.5 1.5 0 011.5-1.5z"/>
                            </svg>
                            Tablero Kanban
                        </Link>
                        <Link
                            v-if="can('processes.update')"
                            :href="route('admin.processes.edit', process.id)"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Editar
                        </Link>
                        <button
                            v-if="can('processes.update')"
                            @click="showDelete = true"
                            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-100"
                        >
                            Archivar
                        </button>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mt-6 border-t border-slate-100 pt-6">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-slate-600">Avance del checklist</span>
                        <span class="font-semibold text-slate-900">{{ process.progress.completed }}/{{ process.progress.total }} · {{ process.progress.percent }}%</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full bg-gradient-to-r from-brand-900 to-indigo-600 transition-all"
                            :style="{ width: `${process.progress.percent}%` }"
                        />
                    </div>
                </div>

                <!-- Equipo -->
                <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Servicio</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ process.service?.nombre || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Líder</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-slate-900">
                            <span v-if="process.lider" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-900 text-[10px] font-semibold text-white">{{ initialsFor(process.lider.name) }}</span>
                            {{ process.lider?.name || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Apoderado</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-slate-900">
                            <span v-if="process.apoderado" class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-[10px] font-semibold text-white">{{ initialsFor(process.apoderado.name) }}</span>
                            {{ process.apoderado?.name || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Apertura</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ formatDate(process.fecha_apertura) }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Resumen ejecutivo del proceso (IA) -->
            <aside class="flex flex-col overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-violet-50 shadow-sm">
                <div class="flex items-center justify-between gap-2 border-b border-indigo-100/70 px-5 py-4">
                    <div class="flex items-center gap-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-indigo-900">Resumen del proceso</p>
                            <p class="text-[11px] text-indigo-700/70">Generado por IA</p>
                        </div>
                    </div>
                    <button
                        v-if="can('ai.use')"
                        type="button"
                        :disabled="generandoResumen"
                        @click="generarResumen"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60"
                    >
                        <svg v-if="generandoResumen" class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        {{ generandoResumen ? 'Generando…' : (resumenIa ? 'Regenerar' : 'Generar') }}
                    </button>
                </div>

                <div class="flex-1 px-5 py-4">
                    <p v-if="resumenError" class="mb-3 rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700 ring-1 ring-inset ring-rose-200">
                        {{ resumenError }}
                    </p>

                    <div v-if="generandoResumen && !resumenIa" class="space-y-2">
                        <div class="h-3 w-3/4 animate-pulse rounded bg-indigo-100"></div>
                        <div class="h-3 w-full animate-pulse rounded bg-indigo-100"></div>
                        <div class="h-3 w-5/6 animate-pulse rounded bg-indigo-100"></div>
                    </div>

                    <p
                        v-else-if="resumenIa"
                        class="whitespace-pre-line text-sm leading-relaxed text-indigo-950/90"
                    >{{ resumenIa }}</p>

                    <div v-else class="py-6 text-center">
                        <p class="text-sm text-indigo-700/70">Este proceso aún no tiene un resumen de IA.</p>
                        <p v-if="can('ai.use')" class="mt-1 text-xs text-indigo-700/60">Usa “Generar” para crear uno a partir del expediente.</p>
                    </div>
                </div>

                <p v-if="resumenIaAt" class="border-t border-indigo-100/70 px-5 py-2.5 text-[11px] text-indigo-700/60">
                    Actualizado {{ formatDateTime(resumenIaAt) }}
                </p>
            </aside>
            </div>

            <!-- Tabs -->
            <div class="border-b border-slate-200">
                <nav class="-mb-px flex gap-6 overflow-x-auto">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        @click="activeTab = tab.key"
                        :class="[
                            'whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition',
                            activeTab === tab.key
                                ? 'border-brand-900 text-brand-900'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700',
                        ]"
                    >
                        {{ tab.label }}
                        <span
                            v-if="tab.key === 'tareas' && process.tasks.length"
                            class="ml-1 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-700"
                        >
                            {{ process.tasks.length }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- TABLERO DE ETAPAS -->
            <section v-if="activeTab === 'tablero'" class="space-y-4">
                <p v-if="!process.stages.length" class="rounded-xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                    Este proceso no tiene etapas. Verifica que el servicio tenga plantillas configuradas.
                </p>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="stage in process.stages"
                        :key="stage.id"
                        class="rounded-2xl border bg-white p-5 shadow-sm transition"
                        :class="[
                            stage.estado === 'completada' ? 'border-emerald-200 bg-emerald-50/40'
                            : stage.estado === 'en_curso' ? 'border-indigo-200'
                            : stage.estado === 'bloqueada' ? 'border-rose-200 bg-rose-50/40'
                            : 'border-slate-200',
                            isLate(stage) ? 'ring-1 ring-rose-200' : '',
                        ]"
                    >
                        <header class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Etapa {{ stage.orden }}</p>
                                <h3 class="text-sm font-semibold text-slate-900">{{ stage.nombre }}</h3>
                            </div>
                            <StatusBadge :variant="stageEstadoVariants[stage.estado] || 'gray'" :label="labelEstado(stage.estado)" />
                        </header>

                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
                            <span v-if="stage.responsable">👤 {{ stage.responsable }}</span>
                            <span v-if="stage.fecha_limite" :class="isLate(stage) ? 'font-medium text-rose-700' : ''">
                                ⏰ Límite {{ formatDate(stage.fecha_limite) }}
                            </span>
                            <span v-if="stage.fecha_completada" class="text-emerald-700">
                                ✓ Completada {{ formatDateTime(stage.fecha_completada) }}
                            </span>
                        </div>

                        <!-- Checklist -->
                        <div class="mt-4 space-y-1.5">
                            <div v-if="!stage.checklist.length" class="text-xs italic text-slate-400">Sin checklist asociado.</div>
                            <label
                                v-for="item in stage.checklist"
                                :key="item.id"
                                class="flex items-start gap-2 rounded-md p-1.5 text-sm transition hover:bg-slate-50"
                                :class="item.completado ? 'text-slate-400' : 'text-slate-700'"
                            >
                                <input
                                    type="checkbox"
                                    :checked="item.completado"
                                    :disabled="!can('stages.update') || stage.estado === 'completada'"
                                    @change="toggleChecklist(stage, item)"
                                    class="mt-0.5 rounded border-slate-300 text-brand-900 shadow-sm focus:ring-brand-900"
                                />
                                <span :class="item.completado ? 'line-through' : ''">
                                    {{ item.descripcion }}
                                    <span v-if="!item.es_obligatorio" class="ml-1 text-[10px] uppercase tracking-wider text-slate-400">opcional</span>
                                </span>
                            </label>
                        </div>

                        <!-- Stage progress -->
                        <div v-if="stage.checklist.length" class="mt-4">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="text-slate-500">{{ stage.checklist.filter(i => i.completado).length }}/{{ stage.checklist.length }} ítems</span>
                                <span class="font-semibold text-slate-700">{{ stageProgress(stage) }}%</span>
                            </div>
                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full bg-brand-900 transition-all" :style="{ width: `${stageProgress(stage)}%` }" />
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                            <button
                                v-if="can('stages.complete') && stage.estado !== 'completada'"
                                @click="completeStage(stage)"
                                :disabled="!allRequiredDone(stage)"
                                :title="!allRequiredDone(stage) ? 'Completa todos los ítems obligatorios primero' : ''"
                                class="rounded-md bg-brand-900 px-3 py-1 text-xs font-medium text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                            >
                                ✓ Completar etapa
                            </button>
                            <button
                                v-if="can('stages.update') && stage.estado === 'completada'"
                                @click="reopenStage(stage)"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                            >
                                ↺ Reabrir
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- DETALLE -->
            <section v-else-if="activeTab === 'detalle'" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Descripción del proceso</h3>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-700">
                        {{ process.descripcion || 'Sin descripción registrada.' }}
                    </p>
                    <h3 class="mt-6 text-sm font-semibold uppercase tracking-wider text-slate-500">Servicio</h3>
                    <p class="mt-3 text-sm text-slate-700">{{ process.service?.descripcion || 'Sin descripción del servicio.' }}</p>
                </div>

                <div class="space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Cliente</p>
                        <p class="mt-1 text-sm font-medium text-slate-900">{{ process.client?.razon_social || '—' }}</p>
                        <p v-if="process.client?.nit" class="text-xs text-slate-500">NIT {{ process.client.nit }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Coordinador</p>
                        <p class="mt-1 text-sm text-slate-900">{{ process.coordinador?.name || '—' }}</p>
                        <p class="text-xs text-slate-500">{{ process.coordinador?.email }}</p>
                    </div>
                </div>
            </section>

            <!-- TAREAS -->
            <section v-else-if="activeTab === 'tareas'" class="space-y-4">
                <!-- Acceso al Kanban propio del proceso -->
                <Link
                    v-if="can('tasks.view')"
                    :href="route('admin.processes.board', process.id)"
                    class="flex items-center justify-between gap-4 rounded-xl border border-indigo-200 bg-gradient-to-r from-indigo-50 to-violet-50 px-5 py-4 shadow-sm transition hover:border-indigo-300 hover:shadow"
                >
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15M5.25 4.5h13.5A1.5 1.5 0 0120.25 6v12a1.5 1.5 0 01-1.5 1.5H5.25A1.5 1.5 0 013.75 18V6a1.5 1.5 0 011.5-1.5z"/>
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-indigo-900">Tablero Kanban de {{ process.codigo }}</p>
                            <p class="text-xs text-indigo-700/80">Se abre con este proceso seleccionado · puedes cambiar a "Todos" o a otro proceso desde el selector.</p>
                        </div>
                    </div>
                    <span class="shrink-0 text-indigo-600">→</span>
                </Link>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-if="!process.tasks.length" class="px-6 py-8 text-center text-sm text-slate-500">
                        Aún no hay tareas creadas para este proceso.
                    </li>
                    <li
                        v-for="t in process.tasks"
                        :key="t.id"
                        class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-slate-900">{{ t.titulo }}</p>
                                <StatusBadge :variant="taskEstadoVariants[t.estado] || 'gray'" :label="labelEstado(t.estado)" />
                                <StatusBadge :variant="taskPriorityVariants[t.prioridad] || 'gray'" :label="labelPrioridad(t.prioridad)" />
                            </div>
                            <p class="text-xs text-slate-500">
                                <span v-if="t.asignado">Asignada a {{ t.asignado }}</span>
                                <span v-if="t.fecha_limite"> · Vence {{ formatDate(t.fecha_limite) }}</span>
                            </p>
                        </div>
                    </li>
                </ul>
                </div>
            </section>

            <!-- DOCUMENTOS -->
            <section v-else-if="activeTab === 'documentos'" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-if="!process.documents || !process.documents.length" class="px-6 py-8 text-center text-sm text-slate-500">
                        Aún no hay documentos. Usa “Generar borrador IA” para crear uno.
                    </li>
                    <li
                        v-for="d in process.documents"
                        :key="d.id"
                        class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-slate-900">{{ d.nombre }}</p>
                                <StatusBadge variant="gray" :label="d.tipo" />
                                <StatusBadge v-if="d.generado_por_ia" variant="indigo" label="IA" />
                                <StatusBadge v-if="d.visible_cliente" variant="green" label="visible cliente" />
                            </div>
                            <p class="text-xs text-slate-500">
                                <span v-if="d.subido_por">Por {{ d.subido_por }}</span>
                                <span v-if="d.created_at"> · {{ formatDateTime(d.created_at) }}</span>
                            </p>
                        </div>
                        <a
                            v-if="d.url"
                            :href="d.url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                            </svg>
                            Abrir
                        </a>
                    </li>
                </ul>
            </section>

            <!-- COMENTARIOS -->
            <section v-else-if="activeTab === 'comentarios'" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-if="!process.comments || !process.comments.length" class="px-6 py-8 text-center text-sm text-slate-500">
                        Aún no hay comentarios en este proceso.
                    </li>
                    <li
                        v-for="c in process.comments"
                        :key="c.id"
                        class="px-6 py-4"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ c.user || '—' }}</p>
                            <StatusBadge v-if="c.visible_cliente" variant="green" label="visible cliente" />
                            <span v-if="c.created_at" class="text-xs text-slate-500">{{ formatDateTime(c.created_at) }}</span>
                        </div>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ c.body }}</p>
                    </li>
                </ul>
            </section>

            <!-- HISTORIAL -->
            <section v-else-if="activeTab === 'historial'" class="space-y-6">
                <!-- Filtro por origen del evento -->
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="f in historialFiltros"
                        :key="f.key"
                        type="button"
                        @click="historialFiltro = f.key"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset transition"
                        :class="historialFiltro === f.key
                            ? 'bg-brand-900 text-white ring-brand-900'
                            : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 hover:text-slate-900'"
                    >
                        {{ f.label }}
                        <span
                            class="rounded-full px-1.5 text-[10px] tabular-nums"
                            :class="historialFiltro === f.key ? 'bg-white/20' : 'bg-slate-100 text-slate-500'"
                        >
                            {{ f.count }}
                        </span>
                    </button>
                </div>

                <p
                    v-if="!historialVisible.length"
                    class="rounded-xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-500"
                >
                    Aún no hay cambios registrados en este proceso.
                </p>

                <!-- Grupos por día -->
                <div v-for="grupo in historialPorDia" :key="grupo.key">
                    <div class="mb-3 flex items-center gap-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 first-letter:capitalize">{{ grupo.label }}</h3>
                        <div class="h-px flex-1 bg-slate-200/80"></div>
                    </div>

                    <ol class="relative ml-4 space-y-3 border-l-2 border-slate-100 pl-7">
                        <li v-for="h in grupo.items" :key="h.id" class="relative">
                            <!-- Icono del evento sobre la línea de tiempo -->
                            <span
                                class="absolute -left-[45px] top-3 flex h-8 w-8 items-center justify-center rounded-full ring-4 ring-white"
                                :class="temaDe(h).circle"
                                v-html="temaDe(h).icon"
                            />

                            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:shadow">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ring-2 ring-white"
                                        :class="h.causer ? 'bg-brand-900 text-white' : 'bg-slate-200 text-slate-500'"
                                        :title="h.causer || 'Sistema'"
                                    >
                                        {{ h.causer ? initialsFor(h.causer) : '⚙' }}
                                    </span>
                                    <p class="text-sm text-slate-600">
                                        <span class="font-semibold text-slate-900">{{ h.causer || 'Sistema' }}</span>
                                        {{ eventoTexto(h) }}
                                    </p>
                                    <span
                                        v-if="h.tipo === 'tarea'"
                                        class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-100"
                                    >
                                        🗂 Kanban
                                    </span>
                                    <span class="ml-auto shrink-0 text-xs tabular-nums text-slate-400" :title="formatDateTime(h.created_at)">
                                        {{ formatTime(h.created_at) }}
                                    </span>
                                </div>

                                <p v-if="h.objeto" class="mt-2 text-sm font-medium text-slate-800">
                                    “{{ h.objeto }}”
                                </p>

                                <!-- Para tarjetas recién creadas no listamos cada campo; el título ya se muestra arriba. -->
                                <div
                                    v-if="h.cambios.length && !(h.tipo === 'tarea' && h.evento === 'created')"
                                    class="mt-3 flex flex-wrap gap-1.5"
                                >
                                    <span
                                        v-for="(c, i) in h.cambios"
                                        :key="i"
                                        class="inline-flex max-w-full flex-wrap items-center gap-x-1.5 rounded-lg bg-slate-50 px-2 py-1 text-xs ring-1 ring-inset ring-slate-200"
                                    >
                                        <span class="font-medium text-slate-500">{{ c.campo }}</span>
                                        <template v-if="h.evento === 'updated' && c.antes">
                                            <span class="text-slate-400 line-through">{{ c.antes }}</span>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3 w-3 text-slate-400">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                                            </svg>
                                        </template>
                                        <span class="font-semibold text-slate-800">{{ c.despues ?? '—' }}</span>
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>
            </section>
        </div>

        <AiDraftModal
            :show="showAiModal"
            :process="process"
            :templates="aiTemplates"
            @close="showAiModal = false"
            @saved="onAiSaved"
        />

        <ConfirmModal
            :show="showDelete"
            title="Archivar proceso"
            :message="`¿Confirmas archivar el proceso ${process.codigo}? Podrás restaurarlo desde la base de datos si es necesario.`"
            confirm-label="Sí, archivar"
            variant="danger"
            @close="showDelete = false"
            @confirm="performDelete"
        />
    </AuthenticatedLayout>
</template>
