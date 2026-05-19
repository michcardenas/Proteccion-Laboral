<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    contract: Object,
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const tabs = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'procesos', label: 'Procesos' },
    { key: 'facturas', label: 'Facturas' },
];
const activeTab = ref('resumen');

const estadoVariants = {
    borrador: 'gray',
    activo: 'green',
    pausado: 'yellow',
    finalizado: 'blue',
    cancelado: 'red',
};
const processEstadoVariants = {
    abierto: 'blue',
    en_curso: 'indigo',
    en_revision: 'yellow',
    cerrado: 'green',
    archivado: 'gray',
};
const invoiceEstadoVariants = {
    borrador: 'gray',
    emitida: 'blue',
    pagada: 'green',
    vencida: 'red',
    anulada: 'red',
};
const modalidadServicioVariants = {
    permanente: 'purple',
    por_evento: 'indigo',
    judicial: 'red',
    estrategico: 'blue',
    capacitacion: 'green',
    prediagnostico: 'yellow',
};

const formatCurrency = (v) => {
    if (v === null || v === undefined) return '—';
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(v);
};
const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('es-CO') : '—';

const totalInvoiced = computed(() =>
    props.contract.invoices.reduce((acc, inv) => acc + Number(inv.total || 0), 0)
);
const totalPaid = computed(() =>
    props.contract.invoices
        .filter(i => i.estado === 'pagada')
        .reduce((acc, inv) => acc + Number(inv.total || 0), 0)
);

const showDelete = ref(false);
const performDelete = () => {
    router.delete(route('admin.contracts.destroy', props.contract.id), {
        onFinish: () => { showDelete.value = false; },
    });
};
</script>

<template>
    <Head :title="`Contrato ${contract.codigo}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.contracts.index')" class="text-slate-400 transition hover:text-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold text-slate-900 sm:text-xl">Contrato {{ contract.codigo }}</h1>
                    <p class="truncate text-xs text-slate-500">
                        {{ contract.client?.razon_social || 'Sin cliente' }}
                        <span v-if="contract.service"> · {{ contract.service.nombre }}</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Hero -->
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-semibold text-slate-900">{{ contract.codigo }}</h2>
                            <StatusBadge :variant="estadoVariants[contract.estado] || 'gray'" :label="contract.estado" />
                            <StatusBadge
                                v-if="contract.service"
                                :variant="modalidadServicioVariants[contract.service.modalidad] || 'gray'"
                                :label="contract.service.modalidad"
                            />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            <Link :href="route('admin.clients.show', contract.client.id)" class="font-medium text-brand-900 hover:underline" v-if="contract.client">
                                {{ contract.client.razon_social }}
                            </Link>
                            <span v-if="contract.client?.nit"> · NIT {{ contract.client.nit }}<span v-if="contract.client.dv">-{{ contract.client.dv }}</span></span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            v-if="can('contracts.update')"
                            :href="route('admin.contracts.edit', contract.id)"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Editar
                        </Link>
                        <button
                            v-if="can('contracts.delete')"
                            @click="showDelete = true"
                            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-100"
                        >
                            Eliminar
                        </button>
                    </div>
                </div>

                <dl class="mt-6 grid grid-cols-2 gap-4 border-t border-slate-100 pt-6 lg:grid-cols-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Servicio</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ contract.service?.nombre || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Inicio</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ formatDate(contract.fecha_inicio) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Fin</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ contract.fecha_fin ? formatDate(contract.fecha_fin) : 'Indefinido' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Valor</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ formatCurrency(contract.valor) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Modalidad pago</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ contract.modalidad_pago }}</dd>
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
                            v-if="tab.key === 'procesos' && contract.processes.length"
                            class="ml-1 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-700"
                        >
                            {{ contract.processes.length }}
                        </span>
                        <span
                            v-if="tab.key === 'facturas' && contract.invoices.length"
                            class="ml-1 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-700"
                        >
                            {{ contract.invoices.length }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- Resumen -->
            <section v-if="activeTab === 'resumen'" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Detalle del servicio</h3>
                    <p class="mt-3 text-sm text-slate-700">
                        {{ contract.service?.descripcion || 'Sin descripción del servicio.' }}
                    </p>
                    <h3 class="mt-6 text-sm font-semibold uppercase tracking-wider text-slate-500">Notas internas</h3>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-700">
                        {{ contract.notas || 'Sin notas registradas.' }}
                    </p>
                </div>

                <div class="space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Total facturado</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ formatCurrency(totalInvoiced) }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Pagado</p>
                        <p class="mt-2 text-2xl font-semibold text-emerald-700">{{ formatCurrency(totalPaid) }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Cliente</p>
                        <p class="mt-1 text-sm font-medium text-slate-900">{{ contract.client?.razon_social || '—' }}</p>
                        <p class="text-xs text-slate-500">{{ contract.client?.email || 'Sin email' }}</p>
                        <p class="text-xs text-slate-500">{{ contract.client?.telefono || 'Sin teléfono' }}</p>
                    </div>
                </div>
            </section>

            <!-- Procesos -->
            <section v-else-if="activeTab === 'procesos'" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-if="!contract.processes.length" class="px-6 py-8 text-center text-sm text-slate-500">
                        Aún no hay procesos asociados a este contrato.
                    </li>
                    <li
                        v-for="p in contract.processes"
                        :key="p.id"
                        class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-slate-900">{{ p.codigo }}</p>
                                <StatusBadge :variant="processEstadoVariants[p.estado] || 'gray'" :label="p.estado" />
                            </div>
                            <p class="text-sm text-slate-700">{{ p.titulo }}</p>
                            <p class="text-xs text-slate-500">
                                <span v-if="p.lider">Líder: {{ p.lider }}</span>
                                <span v-if="p.fecha_apertura"> · Abierto {{ formatDate(p.fecha_apertura) }}</span>
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Facturas -->
            <section v-else-if="activeTab === 'facturas'" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-if="!contract.invoices.length" class="px-6 py-8 text-center text-sm text-slate-500">
                        Aún no se han emitido facturas para este contrato.
                    </li>
                    <li
                        v-for="i in contract.invoices"
                        :key="i.id"
                        class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-slate-900">{{ i.numero }}</p>
                                <StatusBadge :variant="invoiceEstadoVariants[i.estado] || 'gray'" :label="i.estado" />
                            </div>
                            <p class="text-xs text-slate-500">
                                Emisión {{ formatDate(i.fecha_emision) }}
                                <span v-if="i.fecha_pago"> · Pago {{ formatDate(i.fecha_pago) }}</span>
                            </p>
                        </div>
                        <p class="text-sm font-semibold text-slate-900">{{ formatCurrency(i.total) }}</p>
                    </li>
                </ul>
            </section>
        </div>

        <ConfirmModal
            :show="showDelete"
            title="Eliminar contrato"
            :message="`¿Confirmas eliminar el contrato ${contract.codigo}? Esta acción no se puede deshacer.`"
            confirm-label="Sí, eliminar"
            variant="danger"
            @close="showDelete = false"
            @confirm="performDelete"
        />
    </AuthenticatedLayout>
</template>
