<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    clients: Object,
    filters: Object,
    estados: Array,
    sectores: Array,
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const search = ref(props.filters.search ?? '');
const estado = ref(props.filters.estado ?? '');
const sector = ref(props.filters.sector ?? '');

function debounce(fn, wait) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
}

const apply = debounce(() => {
    router.get(
        route('admin.clients.index'),
        {
            search: search.value || undefined,
            estado: estado.value || undefined,
            sector: sector.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}, 300);

watch([search, estado, sector], apply);

const columns = [
    { key: 'razon_social', label: 'Empresa' },
    { key: 'nit', label: 'NIT' },
    { key: 'ciudad', label: 'Ciudad / Sector' },
    { key: 'estado', label: 'Estado' },
    { key: 'asignados', label: 'Asignados' },
    { key: 'metricas', label: 'Procesos / Contratos', thClass: 'text-right', tdClass: 'text-right' },
];

const estadoVariants = {
    activo: 'green',
    pausado: 'yellow',
    inactivo: 'red',
    prospecto: 'blue',
};

const initialsFor = (name) => name?.split(' ').map(n => n[0]).slice(0, 2).join('') ?? '?';

const clearFilters = () => { search.value = ''; estado.value = ''; sector.value = ''; };
const hasActiveFilters = computed(() => !!search.value || !!estado.value || !!sector.value);
</script>

<template>
    <Head title="Clientes" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Clientes</h1>
                <p class="text-xs text-slate-500">Empresas atendidas por la firma.</p>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Top bar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1">
                        Total: <strong class="ml-0.5 text-slate-900">{{ clients.total }}</strong>
                    </span>
                </div>
                <Link
                    v-if="can('clients.create')"
                    :href="route('admin.clients.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-brand-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-800"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Nuevo cliente
                </Link>
            </div>

            <!-- Filters -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.34-4.34m0 0A7.5 7.5 0 1116.66 5.66a7.5 7.5 0 010 11"/>
                            </svg>
                            <TextInput
                                v-model="search"
                                type="text"
                                placeholder="Buscar por razón social, NIT, contacto…"
                                class="w-full pl-9"
                            />
                        </div>
                    </div>
                    <select v-model="estado" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">Todos los estados</option>
                        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                    </select>
                    <div class="flex gap-2">
                        <select v-model="sector" class="flex-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                            <option value="">Todos los sectores</option>
                            <option v-for="s in sectores" :key="s" :value="s">{{ s }}</option>
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
                :rows="clients.data"
                :paginator="clients"
                empty-message="Aún no hay clientes registrados. Crea el primero."
            >
                <template #cell-razon_social="{ row }">
                    <Link
                        :href="route('admin.clients.show', row.id)"
                        class="group flex items-center gap-3"
                    >
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-900 ring-1 ring-inset ring-brand-100">
                            {{ initialsFor(row.razon_social) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900 group-hover:text-brand-900">{{ row.razon_social }}</p>
                            <p class="truncate text-xs text-slate-500">{{ row.contacto_principal || row.email || '—' }}</p>
                        </div>
                    </Link>
                </template>

                <template #cell-nit="{ row }">
                    <span class="text-xs text-slate-700">
                        {{ row.nit || '—' }}<span v-if="row.dv" class="text-slate-400">-{{ row.dv }}</span>
                    </span>
                </template>

                <template #cell-ciudad="{ row }">
                    <p class="text-sm text-slate-700">{{ row.ciudad || '—' }}</p>
                    <p class="text-xs text-slate-500">{{ row.sector || 'Sin sector' }}</p>
                </template>

                <template #cell-estado="{ row }">
                    <StatusBadge :variant="estadoVariants[row.estado] || 'gray'" :label="row.estado" />
                </template>

                <template #cell-asignados="{ row }">
                    <div v-if="row.asignados.length" class="flex -space-x-1.5">
                        <span
                            v-for="u in row.asignados.slice(0, 3)"
                            :key="u.id"
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-900 text-[10px] font-semibold text-white ring-2 ring-white"
                            :title="u.name"
                        >
                            {{ initialsFor(u.name) }}
                        </span>
                        <span
                            v-if="row.asignados.length > 3"
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-2 ring-white"
                        >
                            +{{ row.asignados.length - 3 }}
                        </span>
                    </div>
                    <span v-else class="text-xs text-slate-400">Sin asignar</span>
                </template>

                <template #cell-metricas="{ row }">
                    <div class="flex items-center justify-end gap-2 text-xs">
                        <span class="rounded-full bg-blue-50 px-2 py-0.5 font-medium text-blue-700">
                            {{ row.processes_count }} procesos
                        </span>
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">
                            {{ row.contracts_count }} contratos
                        </span>
                    </div>
                </template>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
