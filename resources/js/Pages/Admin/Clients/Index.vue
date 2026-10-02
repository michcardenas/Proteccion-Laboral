<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    clients: Object,
    filters: Object,
    estados: Array,
    sectores: Array,
    portalCandidatos: { type: Array, default: () => [] },
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

// ===== Activar portal a varios =====
const showBulk = ref(false);
const seleccion = ref([]);
const activando = ref(false);
const bulkCreds = computed(() => page.props.flash?.portal_credentials_bulk ?? null);
const copiado = ref(false);

const abrirBulk = () => {
    seleccion.value = props.portalCandidatos.map((c) => c.id);
    copiado.value = false;
    showBulk.value = true;
};
const todosMarcados = computed(() => seleccion.value.length === props.portalCandidatos.length);
const alternarTodos = () => {
    seleccion.value = todosMarcados.value ? [] : props.portalCandidatos.map((c) => c.id);
};
const activarSeleccion = () => {
    activando.value = true;
    router.post(route('admin.clients.portal.activate-bulk'), { client_ids: seleccion.value }, {
        preserveScroll: true,
        onSuccess: () => (showBulk.value = false),
        onFinish: () => (activando.value = false),
    });
};

// Las contraseñas solo se ven esta vez: se copian o se descargan.
const credsComoTexto = () => (bulkCreds.value ?? [])
    .map((c) => `${c.razon_social}\tNIT: ${c.nit}\tContraseña: ${c.password}`)
    .join('\n');
const copiarCreds = async () => {
    await navigator.clipboard.writeText(credsComoTexto());
    copiado.value = true;
};
const descargarCreds = () => {
    const filas = [['Cliente', 'NIT', 'Contraseña'], ...(bulkCreds.value ?? []).map((c) => [c.razon_social, c.nit, c.password])];
    const csv = filas.map((f) => f.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `accesos-portal-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
};

const clearFilters = () => { search.value = ''; estado.value = ''; sector.value = ''; };
const hasActiveFilters = computed(() => !!search.value || !!estado.value || !!sector.value);
</script>

<template>
    <Head title="Clientes" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader titulo="Clientes" help-key="clients" />
        </template>

        <div class="space-y-5">
            <!-- Top bar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2 text-xs text-brand-600">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-white px-3 py-1">
                        Total: <strong class="ml-0.5 text-brand-900">{{ clients.total }}</strong>
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                <button
                    v-if="can('clients.activate_portal')"
                    type="button"
                    @click="abrirBulk"
                    class="inline-flex items-center gap-2 rounded-md border border-brand-200 bg-white px-4 py-2 text-sm font-medium text-brand-700 shadow-sm transition hover:border-accent-300 hover:text-accent-700"
                >
                    Activar portal a varios
                    <span v-if="portalCandidatos.length" class="rounded-full bg-accent-50 px-2 py-0.5 text-xs font-semibold text-accent-700">{{ portalCandidatos.length }}</span>
                </button>
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
            </div>

            <!-- Credenciales recien generadas en lote (se muestran una sola vez) -->
            <div v-if="bulkCreds && bulkCreds.length" class="rounded-xl border border-success-200 bg-success-50 p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-success-800">Accesos al portal generados</p>
                        <p class="mt-0.5 text-xs text-success-700">
                            Cada cliente entra en <code>/portal/login</code> con su NIT y esta contraseña.
                            <strong>Solo se muestran ahora:</strong> cópialas o descárgalas antes de salir de la página.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="copiarCreds" class="rounded-md border border-success-300 bg-white px-3 py-1.5 text-sm font-medium text-success-800 hover:bg-success-100">
                            {{ copiado ? 'Copiado ✓' : 'Copiar todo' }}
                        </button>
                        <button type="button" @click="descargarCreds" class="rounded-md bg-success-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-success-800">
                            Descargar (Excel)
                        </button>
                    </div>
                </div>
                <div class="mt-4 overflow-x-auto rounded-lg border border-success-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-success-50/60 text-left text-xs text-success-800">
                            <tr><th class="px-3 py-2">Cliente</th><th class="px-3 py-2">NIT</th><th class="px-3 py-2">Contraseña</th></tr>
                        </thead>
                        <tbody class="divide-y divide-success-100">
                            <tr v-for="c in bulkCreds" :key="c.nit">
                                <td class="px-3 py-2 text-brand-800">{{ c.razon_social }}</td>
                                <td class="px-3 py-2 font-mono text-brand-700">{{ c.nit }}</td>
                                <td class="px-3 py-2 font-mono font-semibold text-brand-900">{{ c.password }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Filters -->
            <div class="rounded-xl border border-brand-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-400">
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
                    <select v-model="estado" class="rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">Todos los estados</option>
                        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                    </select>
                    <div class="flex gap-2">
                        <select v-model="sector" class="flex-1 rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                            <option value="">Todos los sectores</option>
                            <option v-for="s in sectores" :key="s" :value="s">{{ s }}</option>
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
                            <p class="truncate font-medium text-brand-900 group-hover:text-brand-900">{{ row.razon_social }}</p>
                            <p class="truncate text-xs text-brand-500">{{ row.contacto_principal || row.email || '—' }}</p>
                        </div>
                    </Link>
                </template>

                <template #cell-nit="{ row }">
                    <span class="text-xs text-brand-700">
                        {{ row.nit || '—' }}<span v-if="row.dv" class="text-brand-400">-{{ row.dv }}</span>
                    </span>
                </template>

                <template #cell-ciudad="{ row }">
                    <p class="text-sm text-brand-700">{{ row.ciudad || '—' }}</p>
                    <p class="text-xs text-brand-500">{{ row.sector || 'Sin sector' }}</p>
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
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-100 text-[10px] font-semibold text-brand-600 ring-2 ring-white"
                        >
                            +{{ row.asignados.length - 3 }}
                        </span>
                    </div>
                    <span v-else class="text-xs text-brand-400">Sin asignar</span>
                </template>

                <template #cell-metricas="{ row }">
                    <div class="flex items-center justify-end gap-2 text-xs">
                        <span class="rounded-full bg-info-50 px-2 py-0.5 font-medium text-info-700">
                            {{ row.processes_count }} procesos
                        </span>
                        <span class="rounded-full bg-success-50 px-2 py-0.5 font-medium text-success-700">
                            {{ row.contracts_count }} contratos
                        </span>
                    </div>
                </template>
            </DataTable>
        </div>

        <Modal :show="showBulk" max-width="lg" @close="showBulk = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-brand-900">Activar portal a varios clientes</h3>
                <p class="mt-1 text-sm text-brand-500">
                    Solo aparecen los clientes que aún no tienen portal y que ya pueden entrar: tienen NIT y al menos un proceso con abogado asignado.
                    A cada uno se le genera una contraseña.
                </p>

                <p v-if="!portalCandidatos.length" class="mt-5 rounded-md bg-brand-50 px-4 py-3 text-sm text-brand-600">
                    No hay clientes pendientes. Para que un cliente aparezca aquí, asígnale un abogado líder a alguno de sus procesos.
                </p>

                <template v-else>
                    <label class="mt-5 flex items-center gap-2 border-b border-brand-100 pb-2 text-sm font-medium text-brand-700">
                        <input type="checkbox" :checked="todosMarcados" @change="alternarTodos" class="rounded border-brand-300 text-brand-900 focus:ring-brand-900" />
                        Seleccionar todos ({{ portalCandidatos.length }})
                    </label>
                    <ul class="mt-2 max-h-72 space-y-1 overflow-y-auto">
                        <li v-for="c in portalCandidatos" :key="c.id">
                            <label class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-brand-50">
                                <input type="checkbox" :value="c.id" v-model="seleccion" class="rounded border-brand-300 text-brand-900 focus:ring-brand-900" />
                                <span class="flex-1 text-brand-800">{{ c.razon_social }}</span>
                                <span class="font-mono text-xs text-brand-400">{{ c.nit }}</span>
                            </label>
                        </li>
                    </ul>
                </template>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="showBulk = false" class="rounded-md border border-brand-200 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50">
                        Cancelar
                    </button>
                    <button
                        v-if="portalCandidatos.length"
                        type="button"
                        :disabled="activando || !seleccion.length"
                        @click="activarSeleccion"
                        class="rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 disabled:opacity-50"
                    >
                        {{ activando ? 'Activando…' : `Activar ${seleccion.length}` }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
