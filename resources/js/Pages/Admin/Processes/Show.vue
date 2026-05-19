<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    process: Object,
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const tabs = [
    { key: 'tablero', label: 'Tablero de etapas' },
    { key: 'detalle', label: 'Detalle' },
    { key: 'tareas', label: 'Tareas' },
];
const activeTab = ref('tablero');

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
            <!-- Hero -->
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-semibold text-slate-900">{{ process.titulo }}</h2>
                            <StatusBadge :variant="estadoVariants[process.estado] || 'gray'" :label="process.estado" />
                            <StatusBadge
                                v-if="process.service"
                                :variant="modalidadVariants[process.service.modalidad] || 'gray'"
                                :label="process.service.modalidad"
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
                            <StatusBadge :variant="stageEstadoVariants[stage.estado] || 'gray'" :label="stage.estado" />
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
            <section v-else-if="activeTab === 'tareas'" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
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
                                <StatusBadge :variant="taskEstadoVariants[t.estado] || 'gray'" :label="t.estado" />
                                <StatusBadge :variant="taskPriorityVariants[t.prioridad] || 'gray'" :label="t.prioridad" />
                            </div>
                            <p class="text-xs text-slate-500">
                                <span v-if="t.asignado">Asignada a {{ t.asignado }}</span>
                                <span v-if="t.fecha_limite"> · Vence {{ formatDate(t.fecha_limite) }}</span>
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

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
