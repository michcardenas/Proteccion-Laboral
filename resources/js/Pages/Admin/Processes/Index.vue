<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    processes: Object,
    filters: Object,
    estados: Array,
    clients: Array,
    serviceTypes: Array,
    totals: Object,
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const search = ref(props.filters.search ?? '');
const estado = ref(props.filters.estado ?? '');
const clientId = ref(props.filters.client_id ?? '');
const serviceTypeId = ref(props.filters.service_type_id ?? '');

function debounce(fn, wait) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
}

const apply = debounce(() => {
    router.get(
        route('admin.processes.index'),
        {
            search: search.value || undefined,
            estado: estado.value || undefined,
            client_id: clientId.value || undefined,
            service_type_id: serviceTypeId.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}, 300);

watch([search, estado, clientId, serviceTypeId], apply);

const columns = [
    { key: 'codigo', label: 'Código' },
    { key: 'titulo', label: 'Título' },
    { key: 'client', label: 'Cliente' },
    { key: 'service', label: 'Servicio' },
    { key: 'estado', label: 'Estado' },
    { key: 'lider', label: 'Líder / Apoderado' },
    { key: 'progress', label: 'Avance', thClass: 'text-right', tdClass: 'text-right' },
];

const estadoVariants = {
    abierto: 'blue',
    en_curso: 'indigo',
    en_revision: 'yellow',
    cerrado: 'green',
    archivado: 'gray',
};

const modalidadVariants = {
    permanente: 'purple',
    por_evento: 'indigo',
    judicial: 'red',
    estrategico: 'blue',
    capacitacion: 'green',
    prediagnostico: 'yellow',
    diagnostico_implementacion: 'teal',
};

const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('es-CO') : '—';

const clearFilters = () => { search.value = ''; estado.value = ''; clientId.value = ''; serviceTypeId.value = ''; };
const hasActiveFilters = computed(() => !!search.value || !!estado.value || !!clientId.value || !!serviceTypeId.value);
</script>

<template>
    <Head title="Procesos" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Procesos</h1>
                <p class="text-xs text-slate-500">Casos abiertos y su estado actual.</p>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Stats -->
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Total procesos</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{{ totals.count }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Abiertos / en curso</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-indigo-700">{{ totals.abiertos }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Cerrados / archivados</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-emerald-700">{{ totals.cerrados }}</p>
                </div>
            </section>

            <!-- Top bar -->
            <div class="flex justify-end">
                <Link
                    v-if="can('processes.create')"
                    :href="route('admin.processes.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-brand-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-800"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Nuevo proceso
                </Link>
            </div>

            <!-- Filters -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-1">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.34-4.34m0 0A7.5 7.5 0 1116.66 5.66a7.5 7.5 0 010 11"/>
                            </svg>
                            <TextInput
                                v-model="search"
                                type="text"
                                placeholder="Buscar por código, título o cliente…"
                                class="w-full pl-9"
                            />
                        </div>
                    </div>
                    <select v-model="clientId" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">Todos los clientes</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.razon_social }}</option>
                    </select>
                    <select v-model="serviceTypeId" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">Todos los servicios</option>
                        <option v-for="s in serviceTypes" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                    <div class="flex gap-2">
                        <select v-model="estado" class="flex-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                            <option value="">Todos los estados</option>
                            <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                        </select>
                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            @click="clearFilters"
                            class="rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-600 hover:bg-slate-50"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <DataTable
                :columns="columns"
                :rows="processes.data"
                :paginator="processes"
                empty-message="Aún no hay procesos abiertos."
            >
                <template #cell-codigo="{ row }">
                    <Link
                        :href="route('admin.processes.show', row.id)"
                        class="font-medium text-slate-900 hover:text-brand-900"
                    >
                        {{ row.codigo }}
                    </Link>
                    <p class="text-[10px] text-slate-500">Abierto {{ formatDate(row.fecha_apertura) }}</p>
                </template>

                <template #cell-titulo="{ row }">
                    <p class="text-sm text-slate-700 line-clamp-2">{{ row.titulo }}</p>
                </template>

                <template #cell-client="{ row }">
                    <Link v-if="row.client" :href="route('admin.clients.show', row.client.id)" class="text-xs text-slate-700 hover:text-brand-900">
                        {{ row.client.razon_social }}
                    </Link>
                    <span v-else class="text-xs text-slate-400">—</span>
                </template>

                <template #cell-service="{ row }">
                    <p class="text-xs text-slate-700">{{ row.service?.nombre || '—' }}</p>
                    <StatusBadge
                        v-if="row.service"
                        :variant="modalidadVariants[row.service.modalidad] || 'gray'"
                        :label="row.service.modalidad"
                    />
                </template>

                <template #cell-estado="{ row }">
                    <StatusBadge :variant="estadoVariants[row.estado] || 'gray'" :label="row.estado" />
                </template>

                <template #cell-lider="{ row }">
                    <p class="text-xs text-slate-700">{{ row.lider || '—' }}</p>
                    <p class="text-[10px] text-slate-500">Apoderado: {{ row.apoderado || '—' }}</p>
                </template>

                <template #cell-progress="{ row }">
                    <div class="flex flex-col items-end gap-1">
                        <p class="text-xs font-medium text-slate-700">
                            {{ row.completed_stages_count }}/{{ row.stages_count }} etapas
                        </p>
                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full bg-brand-900 transition-all"
                                :style="{ width: row.stages_count > 0 ? `${Math.round((row.completed_stages_count / row.stages_count) * 100)}%` : '0%' }"
                            />
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
