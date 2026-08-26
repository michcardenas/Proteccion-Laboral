<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    contracts: Object,
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
        route('admin.contracts.index'),
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
    { key: 'client', label: 'Cliente' },
    { key: 'service', label: 'Servicio' },
    { key: 'estado', label: 'Estado' },
    { key: 'fechas', label: 'Vigencia' },
    { key: 'valor', label: 'Valor / Modalidad', thClass: 'text-right', tdClass: 'text-right' },
];

const estadoVariants = {
    borrador: 'gray',
    activo: 'green',
    pausado: 'yellow',
    finalizado: 'blue',
    cancelado: 'red',
};

const modalidadServicioVariants = {
    permanente: 'purple',
    por_evento: 'indigo',
    judicial: 'red',
    estrategico: 'blue',
    capacitacion: 'green',
    prediagnostico: 'yellow',
    diagnostico_implementacion: 'teal',
};

const formatCurrency = (v) => {
    if (v === null || v === undefined) return '—';
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(v);
};

const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('es-CO') : null;

const clearFilters = () => { search.value = ''; estado.value = ''; clientId.value = ''; serviceTypeId.value = ''; };
const hasActiveFilters = computed(() => !!search.value || !!estado.value || !!clientId.value || !!serviceTypeId.value);
</script>

<template>
    <Head title="Contratos" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader titulo="Contratos" help-key="contracts" />
        </template>

        <div class="space-y-5">
            <!-- Stats summary -->
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-brand-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-brand-500">Total contratos</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-brand-900">{{ totals.count }}</p>
                </div>
                <div class="rounded-xl border border-brand-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-brand-500">Activos</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-success-700">{{ totals.activos }}</p>
                </div>
                <div class="rounded-xl border border-brand-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wider text-brand-500">Valor en activos</p>
                    <p class="mt-2 text-2xl font-semibold tracking-tight text-brand-900">{{ formatCurrency(totals.valor_activos) }}</p>
                </div>
            </section>

            <!-- Top bar -->
            <div class="flex flex-wrap items-center justify-end gap-3">
                <Link
                    v-if="can('contracts.create')"
                    :href="route('admin.contracts.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-brand-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-800"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Nuevo contrato
                </Link>
            </div>

            <!-- Filters -->
            <div class="rounded-xl border border-brand-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-1">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.34-4.34m0 0A7.5 7.5 0 1116.66 5.66a7.5 7.5 0 010 11"/>
                            </svg>
                            <TextInput
                                v-model="search"
                                type="text"
                                placeholder="Buscar por código o empresa…"
                                class="w-full pl-9"
                            />
                        </div>
                    </div>
                    <select v-model="clientId" class="rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">Todos los clientes</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.razon_social }}</option>
                    </select>
                    <select v-model="serviceTypeId" class="rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">Todos los servicios</option>
                        <option v-for="s in serviceTypes" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                    <div class="flex gap-2">
                        <select v-model="estado" class="flex-1 rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                            <option value="">Todos los estados</option>
                            <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                        </select>
                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            @click="clearFilters"
                            class="rounded-md border border-brand-200 bg-white px-3 text-sm text-brand-600 hover:bg-brand-50"
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
                :rows="contracts.data"
                :paginator="contracts"
                empty-message="Aún no hay contratos registrados."
            >
                <template #cell-codigo="{ row }">
                    <Link
                        :href="route('admin.contracts.show', row.id)"
                        class="font-medium text-brand-900 hover:text-brand-900"
                    >
                        {{ row.codigo }}
                    </Link>
                    <p v-if="row.processes_count" class="text-[10px] text-brand-500">
                        {{ row.processes_count }} proceso(s) asociados
                    </p>
                </template>

                <template #cell-client="{ row }">
                    <Link
                        v-if="row.client"
                        :href="route('admin.clients.show', row.client.id)"
                        class="text-sm text-brand-700 hover:text-brand-900"
                    >
                        {{ row.client.razon_social }}
                    </Link>
                    <span v-else class="text-xs text-brand-400">—</span>
                    <p v-if="row.client?.nit" class="text-xs text-brand-500">NIT {{ row.client.nit }}</p>
                </template>

                <template #cell-service="{ row }">
                    <p class="text-sm text-brand-700">{{ row.service?.nombre || '—' }}</p>
                    <StatusBadge
                        v-if="row.service"
                        :variant="modalidadServicioVariants[row.service.modalidad] || 'gray'"
                        :label="row.service.modalidad"
                    />
                </template>

                <template #cell-estado="{ row }">
                    <StatusBadge :variant="estadoVariants[row.estado] || 'gray'" :label="row.estado" />
                </template>

                <template #cell-fechas="{ row }">
                    <p class="text-xs text-brand-700">{{ formatDate(row.fecha_inicio) || '—' }}</p>
                    <p class="text-xs text-brand-500">→ {{ formatDate(row.fecha_fin) || 'Indefinido' }}</p>
                </template>

                <template #cell-valor="{ row }">
                    <p class="font-medium text-brand-900">{{ formatCurrency(row.valor) }}</p>
                    <p class="text-xs text-brand-500">{{ row.modalidad_pago }}</p>
                </template>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
