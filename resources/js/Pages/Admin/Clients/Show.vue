<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    client: Object,
    potentialAssignees: Array,
    estados: Array,
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const tabs = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'contactos', label: 'Contactos' },
    { key: 'asignados', label: 'Equipo asignado' },
    { key: 'contratos', label: 'Contratos' },
    { key: 'procesos', label: 'Procesos' },
];
const activeTab = ref('resumen');

const estadoVariants = { activo: 'green', pausado: 'yellow', inactivo: 'red', prospecto: 'blue' };
const initialsFor = (name) => name?.split(' ').map(n => n[0]).slice(0, 2).join('') ?? '?';

const formatCurrency = (value) => {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
};

const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('es-CO') : '—';

// ===== Contactos =====
const contactForm = useForm({ nombre: '', cargo: '', email: '', telefono: '', es_principal: false });
const showContactForm = ref(false);

const submitContact = () => {
    contactForm.post(route('admin.clients.contacts.store', props.client.id), {
        preserveScroll: true,
        onSuccess: () => {
            contactForm.reset();
            showContactForm.value = false;
        },
    });
};

const contactToDelete = ref(null);
const deleteContact = () => {
    if (!contactToDelete.value) return;
    router.delete(route('admin.clients.contacts.destroy', [props.client.id, contactToDelete.value.id]), {
        preserveScroll: true,
        onFinish: () => { contactToDelete.value = null; },
    });
};

// ===== Asignaciones =====
const assignForm = useForm({ user_id: '', rol_asignacion: 'lider' });

const submitAssign = () => {
    assignForm.post(route('admin.clients.assignments.store', props.client.id), {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
};

const removeAssignment = (userId) => {
    router.delete(route('admin.clients.assignments.destroy', [props.client.id, userId]), {
        preserveScroll: true,
    });
};

const assignedIds = computed(() => props.client.asignados.map((a) => a.id));
const availableAssignees = computed(() =>
    props.potentialAssignees.filter((u) => !assignedIds.value.includes(u.id))
);

// ===== Portal del cliente =====
const showPortalPanel = ref(false);
const portalForm = useForm({ password: '' });
// Credenciales recién generadas (vienen por flash, se muestran una sola vez).
const portalCreds = computed(() => page.props.flash?.portal_credentials ?? null);

const openPortalPanel = () => { showPortalPanel.value = true; };

const submitPortal = () => {
    portalForm.post(route('admin.clients.portal.activate', props.client.id), {
        preserveScroll: true,
        onSuccess: () => portalForm.reset(),
    });
};

const deactivatePortal = () => {
    router.post(route('admin.clients.portal.deactivate', props.client.id), {}, { preserveScroll: true });
};

const processEstadoVariants = {
    abierto: 'blue',
    en_curso: 'indigo',
    en_revision: 'yellow',
    cerrado: 'green',
    archivado: 'gray',
};

const contractEstadoVariants = {
    borrador: 'gray',
    activo: 'green',
    pausado: 'yellow',
    finalizado: 'blue',
    cancelado: 'red',
};
</script>

<template>
    <Head :title="client.razon_social" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.clients.index')" class="text-slate-400 transition hover:text-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold text-slate-900 sm:text-xl">{{ client.razon_social }}</h1>
                    <p class="truncate text-xs text-slate-500">
                        {{ client.nit ? 'NIT ' + client.nit + (client.dv ? '-' + client.dv : '') : 'Sin NIT registrado' }}
                        <span v-if="client.ciudad"> · {{ client.ciudad }}</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Hero -->
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-brand-50 text-base font-semibold text-brand-900 ring-1 ring-inset ring-brand-100">
                            {{ initialsFor(client.razon_social) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xl font-semibold text-slate-900">{{ client.razon_social }}</h2>
                                <StatusBadge :variant="estadoVariants[client.estado] || 'gray'" :label="client.estado" />
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ client.sector || 'Sector no especificado' }}
                                <span v-if="client.fecha_alta"> · Alta {{ formatDate(client.fecha_alta) }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="can('clients.update')"
                            type="button"
                            @click="openPortalPanel"
                            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition"
                            :class="client.portal_activo
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                                : 'border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 0h10.5a2.25 2.25 0 012.25 2.25v6.75a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25v-6.75a2.25 2.25 0 012.25-2.25z" />
                            </svg>
                            {{ client.portal_activo ? 'Portal activo' : 'Activar portal' }}
                        </button>
                        <Link
                            v-if="can('clients.update')"
                            :href="route('admin.clients.edit', client.id)"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Editar
                        </Link>
                    </div>
                </div>

                <!-- Panel del portal del cliente -->
                <div v-if="showPortalPanel" class="mt-5 rounded-xl border border-indigo-100 bg-indigo-50/40 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-indigo-900">Portal del cliente</p>
                            <p class="mt-0.5 text-xs text-indigo-700/80">
                                El cliente ingresa en <code>/portal/login</code> con su NIT <strong>{{ client.nit }}</strong> y la contraseña que definas aquí.
                            </p>
                            <p v-if="!client.puede_acceder_portal && client.portal_activo" class="mt-2 rounded-md bg-amber-50 px-3 py-1.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-200">
                                Aviso: este cliente no tiene aún un proceso con abogado asignado, así que no podrá entrar hasta que se le asigne uno.
                            </p>
                        </div>
                        <button type="button" class="text-indigo-400 hover:text-indigo-700" @click="showPortalPanel = false">✕</button>
                    </div>

                    <!-- Credenciales recién generadas (se muestran una sola vez) -->
                    <div v-if="portalCreds" class="mt-3 rounded-lg border border-emerald-200 bg-white p-3">
                        <p class="text-xs font-semibold text-emerald-700">✓ Credenciales del portal — cópialas y compártelas con el cliente (no se volverán a mostrar):</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><span class="text-slate-400">NIT:</span> <strong class="text-slate-800">{{ portalCreds.nit }}</strong></div>
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><span class="text-slate-400">Contraseña:</span> <strong class="font-mono text-slate-800">{{ portalCreds.password }}</strong></div>
                        </div>
                    </div>

                    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitPortal">
                        <div class="flex-1 min-w-[12rem]">
                            <label class="block text-xs font-medium text-slate-600">{{ client.portal_activo ? 'Cambiar contraseña (opcional)' : 'Contraseña (opcional, se genera si la dejas vacía)' }}</label>
                            <TextInput v-model="portalForm.password" type="text" class="mt-1 w-full" placeholder="Mínimo 6 caracteres" />
                            <p v-if="portalForm.errors.password" class="mt-1 text-xs text-rose-600">{{ portalForm.errors.password }}</p>
                        </div>
                        <button type="submit" :disabled="portalForm.processing" class="rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60">
                            {{ portalForm.processing ? 'Guardando…' : (client.portal_activo ? 'Actualizar contraseña' : 'Activar y generar acceso') }}
                        </button>
                        <button v-if="client.portal_activo" type="button" @click="deactivatePortal" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100">
                            Desactivar
                        </button>
                    </form>
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Contacto principal</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ client.contacto_principal || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Email</dt>
                        <dd class="mt-1 truncate text-sm text-slate-900">{{ client.email || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Teléfono</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ client.telefono || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500">Equipo</dt>
                        <dd class="mt-1">
                            <div v-if="client.asignados.length" class="flex -space-x-1.5">
                                <span
                                    v-for="u in client.asignados.slice(0, 4)"
                                    :key="u.id"
                                    class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-900 text-[11px] font-semibold text-white ring-2 ring-white"
                                    :title="u.name"
                                >
                                    {{ initialsFor(u.name) }}
                                </span>
                            </div>
                            <span v-else class="text-sm text-slate-400">Sin asignar</span>
                        </dd>
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
                    </button>
                </nav>
            </div>

            <!-- Tab content -->
            <section v-if="activeTab === 'resumen'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Notas internas</h3>
                <p class="mt-3 whitespace-pre-line text-sm text-slate-700">
                    {{ client.notas || 'Sin notas registradas.' }}
                </p>
            </section>

            <section v-else-if="activeTab === 'contactos'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-600">{{ client.contactos.length }} contactos registrados</p>
                    <button
                        v-if="can('clients.update')"
                        @click="showContactForm = !showContactForm"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        {{ showContactForm ? 'Cancelar' : '+ Nuevo contacto' }}
                    </button>
                </div>

                <form
                    v-if="showContactForm && can('clients.update')"
                    @submit.prevent="submitContact"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label="Nombre" :error="contactForm.errors.nombre" required>
                            <TextInput v-model="contactForm.nombre" type="text" class="w-full" />
                        </FormField>
                        <FormField label="Cargo" :error="contactForm.errors.cargo">
                            <TextInput v-model="contactForm.cargo" type="text" class="w-full" />
                        </FormField>
                        <FormField label="Email" :error="contactForm.errors.email">
                            <TextInput v-model="contactForm.email" type="email" class="w-full" />
                        </FormField>
                        <FormField label="Teléfono" :error="contactForm.errors.telefono">
                            <TextInput v-model="contactForm.telefono" type="text" class="w-full" />
                        </FormField>
                    </div>
                    <label class="mt-4 inline-flex items-center gap-2">
                        <input
                            type="checkbox"
                            v-model="contactForm.es_principal"
                            class="rounded border-slate-300 text-brand-900 shadow-sm focus:ring-brand-900"
                        />
                        <span class="text-sm text-slate-700">Marcar como contacto principal</span>
                    </label>
                    <div class="mt-4 flex justify-end">
                        <button
                            type="submit"
                            :disabled="contactForm.processing"
                            class="rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 disabled:opacity-50"
                        >
                            Guardar contacto
                        </button>
                    </div>
                </form>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <ul class="divide-y divide-slate-100">
                        <li v-if="!client.contactos.length" class="px-6 py-8 text-center text-sm text-slate-500">
                            Aún no hay contactos registrados para este cliente.
                        </li>
                        <li
                            v-for="c in client.contactos"
                            :key="c.id"
                            class="flex items-center justify-between gap-4 px-6 py-4"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">
                                    {{ initialsFor(c.nombre) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-medium text-slate-900">{{ c.nombre }}</p>
                                        <StatusBadge v-if="c.es_principal" variant="indigo" label="Principal" />
                                    </div>
                                    <p class="truncate text-xs text-slate-500">
                                        {{ c.cargo || 'Sin cargo' }}
                                        <span v-if="c.email"> · {{ c.email }}</span>
                                        <span v-if="c.telefono"> · {{ c.telefono }}</span>
                                    </p>
                                </div>
                            </div>
                            <button
                                v-if="can('clients.update')"
                                @click="contactToDelete = c"
                                class="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                            >
                                Eliminar
                            </button>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="activeTab === 'asignados'" class="space-y-4">
                <form
                    v-if="can('clients.update') && availableAssignees.length"
                    @submit.prevent="submitAssign"
                    class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3"
                >
                    <select
                        v-model="assignForm.user_id"
                        class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="">— Selecciona profesional —</option>
                        <option v-for="u in availableAssignees" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.role }})
                        </option>
                    </select>
                    <select
                        v-model="assignForm.rol_asignacion"
                        class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="lider">Líder</option>
                        <option value="apoyo">Apoyo</option>
                        <option value="apoderado">Apoderado</option>
                        <option value="observador">Observador</option>
                    </select>
                    <button
                        type="submit"
                        :disabled="!assignForm.user_id || assignForm.processing"
                        class="rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 disabled:opacity-50"
                    >
                        Asignar
                    </button>
                </form>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <ul class="divide-y divide-slate-100">
                        <li v-if="!client.asignados.length" class="px-6 py-8 text-center text-sm text-slate-500">
                            Sin profesionales asignados todavía.
                        </li>
                        <li
                            v-for="u in client.asignados"
                            :key="u.id"
                            class="flex items-center justify-between gap-4 px-6 py-4"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-900 text-xs font-semibold text-white">
                                    {{ initialsFor(u.name) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900">{{ u.name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ u.email }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge variant="indigo" :label="u.rol_asignacion || 'sin rol'" />
                                <button
                                    v-if="can('clients.update')"
                                    @click="removeAssignment(u.id)"
                                    class="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                >
                                    Quitar
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="activeTab === 'contratos'" class="space-y-3">
                <div class="flex justify-end">
                    <Link
                        v-if="can('contracts.create')"
                        :href="route('admin.contracts.create', { client_id: client.id })"
                        class="rounded-md bg-brand-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-800"
                    >
                        + Nuevo contrato
                    </Link>
                </div>
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <ul class="divide-y divide-slate-100">
                        <li v-if="!client.contracts.length" class="px-6 py-8 text-center text-sm text-slate-500">
                            Este cliente aún no tiene contratos.
                        </li>
                        <li
                            v-for="c in client.contracts"
                            :key="c.id"
                            class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                        >
                            <Link :href="route('admin.contracts.show', c.id)" class="min-w-0 hover:opacity-80">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-slate-900">{{ c.codigo }}</p>
                                    <StatusBadge :variant="contractEstadoVariants[c.estado] || 'gray'" :label="c.estado" />
                                </div>
                                <p class="text-xs text-slate-500">
                                    {{ c.service?.nombre || 'Servicio sin definir' }}
                                    <span v-if="c.fecha_inicio"> · Inicio {{ formatDate(c.fecha_inicio) }}</span>
                                    <span v-if="c.fecha_fin"> · Fin {{ formatDate(c.fecha_fin) }}</span>
                                </p>
                            </Link>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-slate-900">{{ formatCurrency(c.valor) }}</p>
                                <p class="text-xs text-slate-500">{{ c.modalidad_pago }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="activeTab === 'procesos'" class="space-y-3">
                <div class="flex justify-end">
                    <Link
                        v-if="can('processes.create')"
                        :href="route('admin.processes.create', { client_id: client.id })"
                        class="rounded-md bg-brand-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-800"
                    >
                        + Nuevo proceso
                    </Link>
                </div>
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <ul class="divide-y divide-slate-100">
                        <li v-if="!client.processes.length" class="px-6 py-8 text-center text-sm text-slate-500">
                            Aún no hay procesos abiertos para este cliente.
                        </li>
                        <li
                            v-for="p in client.processes"
                            :key="p.id"
                            class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                        >
                            <Link :href="route('admin.processes.show', p.id)" class="min-w-0 hover:opacity-80">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-slate-900">{{ p.codigo }}</p>
                                    <StatusBadge :variant="processEstadoVariants[p.estado] || 'gray'" :label="p.estado" />
                                </div>
                                <p class="text-sm text-slate-700">{{ p.titulo }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ p.service?.nombre || 'Servicio sin definir' }}
                                    <span v-if="p.lider"> · Líder: {{ p.lider }}</span>
                                    <span v-if="p.fecha_apertura"> · Abierto {{ formatDate(p.fecha_apertura) }}</span>
                                </p>
                            </Link>
                        </li>
                    </ul>
                </div>
            </section>
        </div>

        <ConfirmModal
            :show="!!contactToDelete"
            title="Eliminar contacto"
            :message="contactToDelete ? `¿Eliminar a ${contactToDelete.nombre}? Esta acción no se puede deshacer.` : ''"
            confirm-label="Sí, eliminar"
            variant="danger"
            @close="contactToDelete = null"
            @confirm="deleteContact"
        />
    </AuthenticatedLayout>
</template>
