<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    client: Object,
    potentialAssignees: Array,
    estados: Array,
    documentTypes: { type: Array, default: () => [] },
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);

const tabs = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'contactos', label: 'Contactos' },
    { key: 'asignados', label: 'Equipo asignado' },
    { key: 'contratos', label: 'Contratos' },
    { key: 'procesos', label: 'Procesos' },
    { key: 'documentos', label: 'Documentos' },
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

// ===== Documentos del cliente =====
const docForm = useForm({ archivo: null, nombre: '', tipo: props.documentTypes[0] ?? 'contrato', visible_cliente: false });
const docFileInput = ref(null);

const onDocFileChange = (e) => {
    docForm.archivo = e.target.files?.[0] ?? null;
};

const submitDocument = () => {
    docForm.post(route('admin.clients.documents.store', props.client.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            docForm.reset();
            if (docFileInput.value) docFileInput.value.value = '';
        },
    });
};

const docToDelete = ref(null);
const deleteDocument = () => {
    if (!docToDelete.value) return;
    router.delete(route('admin.clients.documents.destroy', [props.client.id, docToDelete.value.id]), {
        preserveScroll: true,
        onFinish: () => { docToDelete.value = null; },
    });
};

// ===== Ficha de conocimiento (digest IA de los documentos) =====
const regeneratingFicha = ref(false);
const regenerateFicha = () => {
    regeneratingFicha.value = true;
    router.post(route('admin.clients.knowledge.regenerate', props.client.id), {}, {
        preserveScroll: true,
        onFinish: () => { regeneratingFicha.value = false; },
    });
};

const escapeHtml = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

// Render markdown mínimo de la ficha (encabezados, negritas, viñetas) — sin dependencias.
const fichaHtml = computed(() => {
    const md = props.client.resumen_documental;
    if (!md) return '';
    const lines = escapeHtml(md).split('\n');
    const out = [];
    let inList = false;
    const closeList = () => { if (inList) { out.push('</ul>'); inList = false; } };
    for (const raw of lines) {
        const line = raw.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        if (/^#{3,4}\s+/.test(line)) {
            closeList();
            out.push(`<h4 class="mt-3 mb-1 text-sm font-semibold text-brand-800">${line.replace(/^#{3,4}\s+/, '')}</h4>`);
        } else if (/^[-*]\s+/.test(line)) {
            if (!inList) { out.push('<ul class="ml-4 list-disc space-y-0.5">'); inList = true; }
            out.push(`<li>${line.replace(/^[-*]\s+/, '')}</li>`);
        } else if (line.trim() === '') {
            closeList();
        } else {
            closeList();
            out.push(`<p class="mt-1">${line}</p>`);
        }
    }
    closeList();
    return out.join('');
});

const formatFileSize = (bytes) => {
    if (!bytes && bytes !== 0) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const tipoVariants = {
    contrato: 'indigo', concepto: 'blue', informe: 'green',
    escrito: 'yellow', comunicacion: 'blue', soporte: 'gray', otro: 'gray',
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
                <Link :href="route('admin.clients.index')" class="text-brand-400 transition hover:text-brand-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <PageHeader :titulo="client.razon_social" :resumen="(client.nit ? 'NIT ' + client.nit + (client.dv ? '-' + client.dv : '') : 'Sin NIT registrado') + (client.ciudad ? ' · ' + client.ciudad : '')" help-key="clients" />
            </div>
        </template>

        <div class="space-y-5">
            <!-- Hero -->
            <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-brand-50 text-base font-semibold text-brand-900 ring-1 ring-inset ring-brand-100">
                            {{ initialsFor(client.razon_social) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xl font-semibold text-brand-900">{{ client.razon_social }}</h2>
                                <StatusBadge :variant="estadoVariants[client.estado] || 'gray'" :label="client.estado" />
                            </div>
                            <p class="mt-1 text-sm text-brand-500">
                                {{ client.sector || 'Sector no especificado' }}
                                <span v-if="client.fecha_alta"> · Alta {{ formatDate(client.fecha_alta) }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="can('clients.activate_portal')"
                            type="button"
                            @click="openPortalPanel"
                            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition"
                            :class="client.portal_activo
                                ? 'border-success-200 bg-success-50 text-success-700 hover:bg-success-100'
                                : 'border-accent-200 bg-accent-50 text-accent-700 hover:bg-accent-100'"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 0h10.5a2.25 2.25 0 012.25 2.25v6.75a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25v-6.75a2.25 2.25 0 012.25-2.25z" />
                            </svg>
                            {{ client.portal_activo ? 'Portal activo' : 'Activar portal' }}
                        </button>
                        <Link
                            v-if="can('clients.update')"
                            :href="route('admin.clients.edit', client.id)"
                            class="rounded-md border border-brand-200 bg-white px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50"
                        >
                            Editar
                        </Link>
                    </div>
                </div>

                <!-- Panel del portal del cliente -->
                <div v-if="showPortalPanel" class="mt-5 rounded-xl border border-accent-100 bg-accent-50/40 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-accent-900">Portal del cliente</p>
                            <p class="mt-0.5 text-xs text-accent-700/80">
                                El cliente ingresa en <code>/portal/login</code> con su NIT <strong>{{ client.nit }}</strong> y la contraseña que definas aquí.
                            </p>
                            <p v-if="!client.puede_acceder_portal && client.portal_activo" class="mt-2 rounded-md bg-warning-50 px-3 py-1.5 text-xs text-warning-800 ring-1 ring-inset ring-warning-200">
                                Aviso: este cliente no tiene aún un proceso con abogado asignado, así que no podrá entrar hasta que se le asigne uno.
                            </p>
                        </div>
                        <button type="button" class="text-accent-400 hover:text-accent-700" @click="showPortalPanel = false">✕</button>
                    </div>

                    <!-- Credenciales recién generadas (se muestran una sola vez) -->
                    <div v-if="portalCreds" class="mt-3 rounded-lg border border-success-200 bg-white p-3">
                        <p class="text-xs font-semibold text-success-700">✓ Credenciales del portal — cópialas y compártelas con el cliente (no se volverán a mostrar):</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="rounded-md bg-brand-50 px-3 py-2 text-sm ring-1 ring-inset ring-brand-200"><span class="text-brand-400">NIT:</span> <strong class="text-brand-800">{{ portalCreds.nit }}</strong></div>
                            <div class="rounded-md bg-brand-50 px-3 py-2 text-sm ring-1 ring-inset ring-brand-200"><span class="text-brand-400">Contraseña:</span> <strong class="font-mono text-brand-800">{{ portalCreds.password }}</strong></div>
                        </div>
                    </div>

                    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitPortal">
                        <div class="flex-1 min-w-[12rem]">
                            <label class="block text-xs font-medium text-brand-600">{{ client.portal_activo ? 'Cambiar contraseña (opcional)' : 'Contraseña (opcional, se genera si la dejas vacía)' }}</label>
                            <TextInput v-model="portalForm.password" type="text" class="mt-1 w-full" placeholder="Mínimo 6 caracteres" />
                            <p v-if="portalForm.errors.password" class="mt-1 text-xs text-danger-600">{{ portalForm.errors.password }}</p>
                        </div>
                        <button type="submit" :disabled="portalForm.processing" class="rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60">
                            {{ portalForm.processing ? 'Guardando…' : (client.portal_activo ? 'Actualizar contraseña' : 'Activar y generar acceso') }}
                        </button>
                        <button v-if="client.portal_activo" type="button" @click="deactivatePortal" class="rounded-md border border-danger-200 bg-danger-50 px-4 py-2 text-sm font-medium text-danger-700 hover:bg-danger-100">
                            Desactivar
                        </button>
                    </form>
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-brand-100 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-brand-500">Contacto principal</dt>
                        <dd class="mt-1 text-sm text-brand-900">{{ client.contacto_principal || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-brand-500">Email</dt>
                        <dd class="mt-1 truncate text-sm text-brand-900">{{ client.email || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-brand-500">Teléfono</dt>
                        <dd class="mt-1 text-sm text-brand-900">{{ client.telefono || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-brand-500">Equipo</dt>
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
                            <span v-else class="text-sm text-brand-400">Sin asignar</span>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Tabs -->
            <div class="border-b border-brand-200">
                <nav class="-mb-px flex gap-6 overflow-x-auto">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        @click="activeTab = tab.key"
                        :class="[
                            'whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition',
                            activeTab === tab.key
                                ? 'border-brand-900 text-brand-900'
                                : 'border-transparent text-brand-500 hover:border-brand-300 hover:text-brand-700',
                        ]"
                    >
                        {{ tab.label }}
                    </button>
                </nav>
            </div>

            <!-- Tab content -->
            <section v-if="activeTab === 'resumen'" class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-brand-500">Notas internas</h3>
                <p class="mt-3 whitespace-pre-line text-sm text-brand-700">
                    {{ client.notas || 'Sin notas registradas.' }}
                </p>
            </section>

            <section v-else-if="activeTab === 'contactos'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-brand-600">{{ client.contactos.length }} contactos registrados</p>
                    <button
                        v-if="can('clients.update')"
                        @click="showContactForm = !showContactForm"
                        class="rounded-md border border-brand-200 bg-white px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50"
                    >
                        {{ showContactForm ? 'Cancelar' : '+ Nuevo contacto' }}
                    </button>
                </div>

                <form
                    v-if="showContactForm && can('clients.update')"
                    @submit.prevent="submitContact"
                    class="rounded-xl border border-brand-200 bg-white p-5 shadow-sm"
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
                            class="rounded border-brand-300 text-brand-900 shadow-sm focus:ring-brand-900"
                        />
                        <span class="text-sm text-brand-700">Marcar como contacto principal</span>
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

                <div class="overflow-hidden rounded-xl border border-brand-200 bg-white shadow-sm">
                    <ul class="divide-y divide-brand-100">
                        <li v-if="!client.contactos.length" class="px-6 py-8 text-center text-sm text-brand-500">
                            Aún no hay contactos registrados para este cliente.
                        </li>
                        <li
                            v-for="c in client.contactos"
                            :key="c.id"
                            class="flex items-center justify-between gap-4 px-6 py-4"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                                    {{ initialsFor(c.nombre) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-medium text-brand-900">{{ c.nombre }}</p>
                                        <StatusBadge v-if="c.es_principal" variant="indigo" label="Principal" />
                                    </div>
                                    <p class="truncate text-xs text-brand-500">
                                        {{ c.cargo || 'Sin cargo' }}
                                        <span v-if="c.email"> · {{ c.email }}</span>
                                        <span v-if="c.telefono"> · {{ c.telefono }}</span>
                                    </p>
                                </div>
                            </div>
                            <button
                                v-if="can('clients.update')"
                                @click="contactToDelete = c"
                                class="rounded-md border border-danger-200 bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 hover:bg-danger-100"
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
                    class="grid grid-cols-1 gap-3 rounded-xl border border-brand-200 bg-white p-4 shadow-sm md:grid-cols-3"
                >
                    <select
                        v-model="assignForm.user_id"
                        class="rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="">— Selecciona profesional —</option>
                        <option v-for="u in availableAssignees" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.role }})
                        </option>
                    </select>
                    <select
                        v-model="assignForm.rol_asignacion"
                        class="rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
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

                <div class="overflow-hidden rounded-xl border border-brand-200 bg-white shadow-sm">
                    <ul class="divide-y divide-brand-100">
                        <li v-if="!client.asignados.length" class="px-6 py-8 text-center text-sm text-brand-500">
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
                                    <p class="truncate text-sm font-medium text-brand-900">{{ u.name }}</p>
                                    <p class="truncate text-xs text-brand-500">{{ u.email }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge variant="indigo" :label="u.rol_asignacion || 'sin rol'" />
                                <button
                                    v-if="can('clients.update')"
                                    @click="removeAssignment(u.id)"
                                    class="rounded-md border border-danger-200 bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 hover:bg-danger-100"
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
                <div class="overflow-hidden rounded-xl border border-brand-200 bg-white shadow-sm">
                    <ul class="divide-y divide-brand-100">
                        <li v-if="!client.contracts.length" class="px-6 py-8 text-center text-sm text-brand-500">
                            Este cliente aún no tiene contratos.
                        </li>
                        <li
                            v-for="c in client.contracts"
                            :key="c.id"
                            class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                        >
                            <Link :href="route('admin.contracts.show', c.id)" class="min-w-0 hover:opacity-80">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-brand-900">{{ c.codigo }}</p>
                                    <StatusBadge :variant="contractEstadoVariants[c.estado] || 'gray'" :label="c.estado" />
                                </div>
                                <p class="text-xs text-brand-500">
                                    {{ c.service?.nombre || 'Servicio sin definir' }}
                                    <span v-if="c.fecha_inicio"> · Inicio {{ formatDate(c.fecha_inicio) }}</span>
                                    <span v-if="c.fecha_fin"> · Fin {{ formatDate(c.fecha_fin) }}</span>
                                </p>
                            </Link>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-brand-900">{{ formatCurrency(c.valor) }}</p>
                                <p class="text-xs text-brand-500">{{ c.modalidad_pago }}</p>
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
                <div class="overflow-hidden rounded-xl border border-brand-200 bg-white shadow-sm">
                    <ul class="divide-y divide-brand-100">
                        <li v-if="!client.processes.length" class="px-6 py-8 text-center text-sm text-brand-500">
                            Aún no hay procesos abiertos para este cliente.
                        </li>
                        <li
                            v-for="p in client.processes"
                            :key="p.id"
                            class="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                        >
                            <Link :href="route('admin.processes.show', p.id)" class="min-w-0 hover:opacity-80">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-brand-900">{{ p.codigo }}</p>
                                    <StatusBadge :variant="processEstadoVariants[p.estado] || 'gray'" :label="p.estado" />
                                </div>
                                <p class="text-sm text-brand-700">{{ p.titulo }}</p>
                                <p class="text-xs text-brand-500">
                                    {{ p.service?.nombre || 'Servicio sin definir' }}
                                    <span v-if="p.lider"> · Líder: {{ p.lider }}</span>
                                    <span v-if="p.fecha_apertura"> · Abierto {{ formatDate(p.fecha_apertura) }}</span>
                                </p>
                            </Link>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="activeTab === 'documentos'" class="space-y-4">
                <!-- Ficha de conocimiento: digest IA de TODOS los documentos del cliente.
                     La IA la tiene "en mente" al redactar sobre este cliente en sus procesos. -->
                <div class="rounded-xl border border-success-200 bg-success-50/40 p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-success-900">
                                <span>🧠 Ficha de conocimiento del cliente (IA)</span>
                                <span
                                    v-if="client.ficha_desactualizada"
                                    class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800"
                                >Desactualizada</span>
                                <span
                                    v-else-if="client.resumen_documental"
                                    class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-medium text-success-800"
                                >Al día</span>
                            </h3>
                            <p class="mt-0.5 text-xs text-brand-500">
                                Resumen de todos los documentos, inyectado en el contexto de la IA al redactar sobre este cliente.
                                <template v-if="client.resumen_documental_at"> · Actualizada {{ formatDate(client.resumen_documental_at) }}</template>
                            </p>
                        </div>
                        <button
                            v-if="can('ai.use')"
                            type="button"
                            @click="regenerateFicha"
                            :disabled="regeneratingFicha"
                            class="rounded-md border border-success-300 bg-white px-3 py-1.5 text-xs font-semibold text-success-800 shadow-sm hover:bg-success-50 disabled:opacity-50"
                        >
                            {{ regeneratingFicha ? 'Generando…' : (client.resumen_documental ? 'Regenerar' : 'Generar ahora') }}
                        </button>
                    </div>

                    <div
                        v-if="client.resumen_documental"
                        class="mt-3 max-h-96 overflow-y-auto rounded-lg border border-success-100 bg-white p-4 text-sm leading-relaxed text-brand-700"
                        v-html="fichaHtml"
                    ></div>
                    <p v-else class="mt-3 text-sm text-brand-500">
                        Aún no hay ficha: se genera sola en cuanto adjuntes documentos al cliente. Es el resumen que la
                        IA usa para conocer a este cliente en todos sus procesos. Regenerarla consume servicio de IA.
                    </p>
                </div>

                <!-- Subir documento (PDF del contrato, diagnóstico pre-jurídico, etc.) -->
                <form
                    v-if="can('documents.upload')"
                    @submit.prevent="submitDocument"
                    class="rounded-xl border border-brand-200 bg-white p-5 shadow-sm"
                >
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <FormField label="Archivo" :error="docForm.errors.archivo" required class="md:col-span-2">
                            <input
                                ref="docFileInput"
                                type="file"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.txt"
                                @change="onDocFileChange"
                                class="block w-full text-sm text-brand-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-900 hover:file:bg-brand-100"
                            />
                            <p class="mt-1 text-xs text-brand-400">PDF, Word, Excel, imágenes o texto · máx. 20&nbsp;MB</p>
                        </FormField>
                        <FormField label="Tipo" :error="docForm.errors.tipo">
                            <select
                                v-model="docForm.tipo"
                                class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                            >
                                <option v-for="t in documentTypes" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </FormField>
                    </div>
                    <FormField label="Nombre (opcional)" :error="docForm.errors.nombre" class="mt-4">
                        <TextInput v-model="docForm.nombre" type="text" class="w-full" placeholder="Se usa el nombre del archivo si lo dejas vacío" />
                    </FormField>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <label class="inline-flex items-center gap-2">
                            <input
                                type="checkbox"
                                v-model="docForm.visible_cliente"
                                class="rounded border-brand-300 text-brand-900 shadow-sm focus:ring-brand-900"
                            />
                            <span class="text-sm text-brand-700">Visible para el cliente en el portal</span>
                        </label>
                        <button
                            type="submit"
                            :disabled="docForm.processing || !docForm.archivo"
                            class="rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 disabled:opacity-50"
                        >
                            {{ docForm.processing ? 'Subiendo…' : 'Adjuntar documento' }}
                        </button>
                    </div>
                </form>

                <div class="overflow-hidden rounded-xl border border-brand-200 bg-white shadow-sm">
                    <ul class="divide-y divide-brand-100">
                        <li v-if="!client.documentos.length" class="px-6 py-8 text-center text-sm text-brand-500">
                            Aún no hay documentos adjuntos a este cliente.
                        </li>
                        <li
                            v-for="d in client.documentos"
                            :key="d.id"
                            class="flex items-center justify-between gap-4 px-6 py-4"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-5 w-5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                    </svg>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-medium text-brand-900">{{ d.nombre }}</p>
                                        <StatusBadge :variant="tipoVariants[d.tipo] || 'gray'" :label="d.tipo" />
                                        <StatusBadge v-if="d.visible_cliente" variant="green" label="Visible al cliente" />
                                    </div>
                                    <p class="truncate text-xs text-brand-500">
                                        {{ formatFileSize(d.tamano_bytes) }}
                                        <span v-if="d.subido_por"> · {{ d.subido_por }}</span>
                                        <span v-if="d.created_at"> · {{ formatDate(d.created_at) }}</span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a
                                    :href="route('admin.documents.download', d.id)"
                                    target="_blank"
                                    class="rounded-md border border-brand-200 bg-white px-2.5 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50"
                                >
                                    Ver / descargar
                                </a>
                                <button
                                    v-if="can('documents.delete')"
                                    @click="docToDelete = d"
                                    class="rounded-md border border-danger-200 bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 hover:bg-danger-100"
                                >
                                    Eliminar
                                </button>
                            </div>
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

        <ConfirmModal
            :show="!!docToDelete"
            title="Eliminar documento"
            :message="docToDelete ? `¿Eliminar «${docToDelete.nombre}»? Esta acción no se puede deshacer.` : ''"
            confirm-label="Sí, eliminar"
            variant="danger"
            @close="docToDelete = null"
            @confirm="deleteDocument"
        />
    </AuthenticatedLayout>
</template>
