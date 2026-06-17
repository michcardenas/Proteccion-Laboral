<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    correos: { type: Object, required: true },
    procesos: { type: Array, default: () => [] },
    acciones: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ buscar: '', accion: '' }) },
});

const page = usePage();

const buscar = ref(props.filters.buscar ?? '');
const accion = ref(props.filters.accion ?? '');

// Proceso elegido por correo (id). Se preselecciona si la IA sugirió un process_code
// que coincide con un proceso existente.
const seleccion = reactive({});
const procesando = reactive({});

function procesoPorCodigo(codigo) {
    if (!codigo) return null;
    const match = props.procesos.find((p) => p.codigo === codigo);
    return match ? match.id : null;
}

for (const c of props.correos.data) {
    seleccion[c.id] = procesoPorCodigo(c.sugerencia?.process_code) ?? '';
}

function applyFilters() {
    router.get(
        route('admin.emails.review.index'),
        {
            buscar: buscar.value || undefined,
            accion: accion.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetFilters() {
    buscar.value = '';
    accion.value = '';
    applyFilters();
}

function asignar(correo) {
    const processId = seleccion[correo.id];
    if (!processId) return;
    procesando[correo.id] = true;
    router.post(
        route('admin.emails.review.assign', correo.id),
        { process_id: processId },
        {
            preserveScroll: true,
            onFinish: () => {
                procesando[correo.id] = false;
            },
        },
    );
}

function descartar(correo) {
    if (!window.confirm('¿Descartar este correo como irrelevante? No se enlazará a ningún proceso.')) return;
    procesando[correo.id] = true;
    router.post(
        route('admin.emails.review.discard', correo.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesando[correo.id] = false;
            },
        },
    );
}

function formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
}

// Estética del chip según la confianza de la IA.
function confidenceClass(conf) {
    if (conf === null || conf === undefined) return 'bg-slate-100 text-slate-600';
    if (conf >= 0.7) return 'bg-amber-100 text-amber-800';
    if (conf >= 0.5) return 'bg-orange-100 text-orange-800';
    return 'bg-rose-100 text-rose-700';
}

const accionLabels = {
    nuevo_caso: 'Nuevo caso',
    seguimiento_proceso: 'Seguimiento',
    documento_recibido: 'Documento',
    comunicacion_cliente: 'Comunicación',
    spam_o_irrelevante: 'Spam / irrelevante',
    requiere_revision_humana: 'Requiere revisión',
};

function accionLabel(a) {
    return accionLabels[a] ?? (a || 'Sin clasificar');
}

const total = computed(() => props.correos.total ?? props.correos.data.length);
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <Head title="Revisión de correos" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Revisión de correos
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">

                <!-- Hero -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-600 via-orange-600 to-rose-600 px-8 py-9 text-white shadow-lg">
                    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="relative">
                        <p class="text-xs uppercase tracking-[0.2em] text-amber-100">Bandeja de revisión humana</p>
                        <h1 class="mt-2 text-3xl font-bold">{{ total }} {{ total === 1 ? 'correo pendiente' : 'correos pendientes' }}</h1>
                        <p class="mt-2 max-w-2xl text-sm text-amber-50">
                            Correos que la IA no pudo enrutar automáticamente (baja confianza, sin coincidencia de
                            cliente/proceso, o marcados para revisión). Asígnalos a un proceso o descártalos.
                        </p>
                    </div>
                </div>

                <!-- Flash -->
                <div v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </div>

                <!-- Filtros -->
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-500">Buscar</label>
                            <input
                                v-model="buscar"
                                type="text"
                                placeholder="Remitente o asunto…"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"
                                @keyup.enter="applyFilters"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Clasificación IA</label>
                            <select
                                v-model="accion"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"
                            >
                                <option value="">Todas</option>
                                <option v-for="a in acciones" :key="a" :value="a">{{ accionLabel(a) }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button
                            @click="applyFilters"
                            class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700"
                        >
                            Aplicar
                        </button>
                        <button
                            @click="resetFilters"
                            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                        >
                            Limpiar
                        </button>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!correos.data.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>
                    <p class="mt-4 text-sm font-medium text-slate-700">Bandeja al día</p>
                    <p class="mt-1 text-sm text-slate-500">No hay correos pendientes de revisión.</p>
                </div>

                <!-- Lista -->
                <div v-else class="space-y-4">
                    <article
                        v-for="correo in correos.data"
                        :key="correo.id"
                        class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ correo.subject || '(sin asunto)' }}</p>
                                <p class="mt-0.5 truncate text-xs text-slate-500">De: {{ correo.from || '—' }}</p>
                                <p class="text-xs text-slate-400">{{ formatDate(correo.received_at) }}</p>
                            </div>
                            <div class="flex flex-shrink-0 items-center gap-2">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="confidenceClass(correo.sugerencia.confidence)"
                                >
                                    {{ accionLabel(correo.sugerencia.action) }}
                                    <span v-if="correo.sugerencia.confidence !== null" class="ml-1 opacity-70">
                                        {{ Math.round(correo.sugerencia.confidence * 100) }}%
                                    </span>
                                </span>
                            </div>
                        </div>

                        <!-- Sugerencia de la IA -->
                        <div v-if="correo.sugerencia.summary || correo.sugerencia.client_name || correo.sugerencia.process_code" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            <p v-if="correo.sugerencia.summary"><span class="font-medium text-slate-500">Resumen IA:</span> {{ correo.sugerencia.summary }}</p>
                            <p v-if="correo.sugerencia.client_name" class="mt-1">
                                <span class="font-medium text-slate-500">Cliente sugerido:</span> {{ correo.sugerencia.client_name }}
                            </p>
                            <p v-if="correo.sugerencia.process_code" class="mt-1">
                                <span class="font-medium text-slate-500">Proceso sugerido:</span> {{ correo.sugerencia.process_code }}
                            </p>
                        </div>

                        <!-- Cuerpo -->
                        <p v-if="correo.body_preview" class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ correo.body_preview }}</p>

                        <!-- Acciones -->
                        <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4">
                            <div class="min-w-[16rem] flex-1">
                                <label class="block text-xs font-medium text-slate-500">Asignar al proceso</label>
                                <select
                                    v-model="seleccion[correo.id]"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"
                                >
                                    <option value="">— Selecciona un proceso —</option>
                                    <option v-for="p in procesos" :key="p.id" :value="p.id">
                                        {{ p.codigo }} — {{ p.titulo }}<template v-if="p.cliente"> ({{ p.cliente }})</template>
                                    </option>
                                </select>
                            </div>
                            <button
                                @click="asignar(correo)"
                                :disabled="!seleccion[correo.id] || procesando[correo.id]"
                                class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Asignar
                            </button>
                            <button
                                @click="descartar(correo)"
                                :disabled="procesando[correo.id]"
                                class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                            >
                                Descartar
                            </button>
                        </div>
                    </article>
                </div>

                <!-- Paginación -->
                <div v-if="correos.links && correos.links.length > 3" class="flex flex-wrap justify-center gap-1">
                    <template v-for="(link, i) in correos.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-md px-3 py-1.5 text-sm"
                            :class="link.active ? 'bg-amber-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-md px-3 py-1.5 text-sm text-slate-300"
                            v-html="link.label"
                        />
                    </template>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
