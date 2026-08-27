<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmailReplyModal from '@/Components/EmailReplyModal.vue';
import { useGoogleDrivePicker } from '@/Composables/useGoogleDrivePicker';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    emails: { type: Array, default: () => [] },
    estados: { type: Array, default: () => [] },
    prioridades: { type: Array, default: () => [] },
    processes: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
    googlePicker: { type: Object, default: () => ({ enabled: false }) },
    // Proceso preseleccionado en el selector (al entrar desde la página de un proceso).
    initialProcessId: { type: Number, default: null },
});

const page = usePage();
const can = (p) => (page.props.auth.user?.permissions ?? []).includes(p);
const canUpdate = can('tasks.update');
const canCreate = can('tasks.create');
const canUpload = can('documents.upload');
const canDeleteDoc = can('documents.delete');
const canReply = can('processes.update');

const drivePicker = useGoogleDrivePicker(props.googlePicker);

// --- Iconos de columna ---
const icons = {
    clock: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l3.5 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    bolt: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>',
    lock: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 0h10.5a2.25 2.25 0 012.25 2.25v6.75a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25v-6.75a2.25 2.25 0 012.25-2.25z"/></svg>',
    check: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    ban: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>',
    calendar: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3 w-3"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>',
};

// --- Tema por estado ---
const estadoTheme = {
    pendiente: { label: 'Pendiente', text: 'text-brand-600', dot: 'bg-brand-400', col: 'bg-brand-100/70', icon: icons.clock },
    en_curso: { label: 'En curso', text: 'text-info-700', dot: 'bg-info-500', col: 'bg-info-50/60', icon: icons.bolt },
    bloqueada: { label: 'Bloqueada', text: 'text-danger-700', dot: 'bg-danger-500', col: 'bg-danger-50/50', icon: icons.lock },
    completada: { label: 'Completada', text: 'text-success-700', dot: 'bg-success-500', col: 'bg-success-50/50', icon: icons.check },
    cancelada: { label: 'Cancelada', text: 'text-brand-500', dot: 'bg-brand-400', col: 'bg-brand-100/60', icon: icons.ban },
};
const labelEstado = (e) => estadoTheme[e]?.label ?? e;

// --- Tema por prioridad ---
const prioridadTheme = {
    urgente: { label: 'Urgente', text: 'text-danger-700', dot: 'bg-danger-500', accent: 'border-l-danger-500' },
    alta: { label: 'Alta', text: 'text-warning-700', dot: 'bg-warning-500', accent: 'border-l-warning-400' },
    media: { label: 'Media', text: 'text-info-700', dot: 'bg-info-500', accent: 'border-l-info-400' },
    baja: { label: 'Baja', text: 'text-brand-500', dot: 'bg-brand-400', accent: 'border-l-brand-300' },
};

// --- Avatares de colores por persona ---
const avatarPalette = [
    'bg-danger-100 text-danger-700', 'bg-warning-100 text-warning-700', 'bg-success-100 text-success-700',
    'bg-info-100 text-info-700', 'bg-accent-100 text-accent-700', 'bg-accent-100 text-accent-700',
    'bg-success-100 text-success-700', 'bg-accent-100 text-accent-700',
];
const initials = (name) => (name || '?').trim().split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase();
const avatarColor = (name) => {
    if (!name) return 'bg-brand-100 text-brand-400';
    let h = 0;
    for (const c of name) h = (h + c.charCodeAt(0)) % avatarPalette.length;
    return avatarPalette[h];
};

// --- Chip de fecha límite (color por urgencia) ---
const dueMeta = (iso, estado) => {
    if (!iso) return null;
    const d = new Date(iso);
    const label = d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
    if (estado === 'completada' || estado === 'cancelada') {
        return { tone: 'bg-brand-50 text-brand-400 ring-brand-200', label };
    }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((d - today) / 86400000);
    if (days < 0) return { tone: 'bg-danger-50 text-danger-700 ring-danger-200', label: `Vencida · ${label}` };
    if (days <= 3) return { tone: 'bg-warning-50 text-warning-800 ring-warning-200', label };
    return { tone: 'bg-brand-50 text-brand-600 ring-brand-200', label };
};

// --- Estado del tablero: columnas keyed por estado ---
const columns = reactive({});
function rebuildColumns() {
    props.estados.forEach((e) => { columns[e] = []; });
    props.tasks.forEach((t) => {
        (columns[t.estado] ?? columns[props.estados[0]]).push(t);
    });
}
rebuildColumns();

// --- Crear nueva tarjeta ---
const showCreate = ref(false);
const createForm = useForm({
    titulo: '',
    process_id: '',
    asignado_a: '',
    prioridad: 'media',
    estado: 'pendiente',
    fecha_limite: '',
    descripcion: '',
});
function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    // Si hay un proceso seleccionado, la tarjeta nace en él.
    if (processFilter.value) createForm.process_id = Number(processFilter.value);
    showCreate.value = true;
}
function submitCreate() {
    createForm.post(route('admin.tasks.store'), {
        preserveScroll: true,
        onSuccess: () => {
            rebuildColumns();
            showCreate.value = false;
            createForm.reset();
        },
    });
}

// --- Filtros (cliente) ---
const search = ref('');
const processFilter = ref(props.initialProcessId ? String(props.initialProcessId) : '');
// Selector: procesos que tienen tareas (+ el preseleccionado, aunque venga vacío).
const processOptions = computed(() => {
    const map = new Map();
    props.tasks.forEach((t) => { if (t.process) map.set(t.process.id, t.process); });
    if (props.initialProcessId && !map.has(props.initialProcessId)) {
        const p = props.processes.find((x) => x.id === props.initialProcessId);
        if (p) map.set(p.id, p);
    }
    return [...map.values()].sort((a, b) => (a.codigo ?? '').localeCompare(b.codigo ?? ''));
});
const processTaskCount = (pid) => props.tasks.filter((t) => t.process?.id === pid).length;
const selectedProcess = computed(() =>
    processFilter.value ? processOptions.value.find((p) => p.id === Number(processFilter.value)) : null
);
const matches = (task) => {
    if (processFilter.value && task.process?.id !== Number(processFilter.value)) return false;
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = `${task.titulo} ${task.process?.codigo ?? ''} ${task.process?.titulo ?? ''} ${task.asignado ?? ''}`.toLowerCase();
        if (!hay.includes(q)) return false;
    }
    return true;
};
const visibleCount = (estado) => columns[estado].filter(matches).length;
const totalVisible = computed(() => props.estados.reduce((acc, e) => acc + visibleCount(e), 0));
const clearFilters = () => { search.value = ''; processFilter.value = ''; };

// --- Bandeja de correos (columna del tablero) ---
const emailCards = ref(props.emails.slice());
const inboxEmails = computed(() => emailCards.value.filter((em) => {
    if (processFilter.value && em.process?.id !== Number(processFilter.value)) return false;
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = `${em.subject} ${em.from} ${em.process?.codigo ?? ''}`.toLowerCase();
        if (!hay.includes(q)) return false;
    }
    return true;
}));

// --- Responder un correo desde la bandeja (reutiliza EmailReplyModal) ---
const replyModal = ref(false);
const replyEmail = ref(null);
const replyProcess = ref(null);
function openReply(em) {
    if (!em.process) {
        flash.value = 'Este correo no tiene un proceso asociado.';
        setTimeout(() => { flash.value = null; }, 4000);
        return;
    }
    replyEmail.value = em;
    replyProcess.value = em.process;
    replyModal.value = true;
}
function onReplySent() {
    const card = emailCards.value.find((x) => x.id === replyEmail.value?.id);
    if (card) card.respondido = true;
    replyModal.value = false;
}

// --- Animación al cambiar de proceso ---
// `filterKey` cambia cada vez que se selecciona otro proceso; lo usamos como :key
// del contenedor del tablero para re-montar las columnas y re-disparar su
// animación de entrada escalonada (las tarjetas "vuelven a entrar" en cascada).
const filterKey = ref(0);
watch(processFilter, () => { filterKey.value++; });

// --- Persistencia del arrastre ---
const flash = ref(null);
function onChange(evt, estado) {
    if (evt.added) persist(evt.added.element, estado);
}
function persist(task, nuevoEstado) {
    const anterior = task.estado;
    if (anterior === nuevoEstado) return;
    task.estado = nuevoEstado;
    router.patch(route('admin.tasks.update', task.id), { estado: nuevoEstado }, {
        preserveState: true,
        preserveScroll: true,
        onError: () => {
            const arr = columns[nuevoEstado];
            const i = arr.findIndex((t) => t.id === task.id);
            if (i !== -1) arr.splice(i, 1);
            task.estado = anterior;
            columns[anterior].push(task);
            flash.value = 'No se pudo mover la tarea (revisa tus permisos).';
            setTimeout(() => { flash.value = null; }, 4000);
        },
    });
}

// --- Panel de detalle ---
const selected = ref(null);
const loadingDetail = ref(false);
async function openTask(task) {
    loadingDetail.value = true;
    showProcessDocs.value = false;
    showProcessEmails.value = false;
    selected.value = { id: task.id, titulo: task.titulo, estado: task.estado };
    try {
        const res = await fetch(route('admin.tasks.show', task.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        selected.value = await res.json();
    } catch (e) {
        flash.value = 'No se pudo cargar el detalle de la tarea.';
    } finally {
        loadingDetail.value = false;
    }
}
const closeDetail = () => { selected.value = null; };

// --- Adjuntos de Google Drive ---
const attaching = ref(false);

const xsrf = () => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
};

function attachFromDrive() {
    if (!props.googlePicker?.enabled || !selected.value) return;
    drivePicker.open(
        async (docs) => {
            attaching.value = true;
            for (const d of docs) {
                await saveAttachment(d);
            }
            attaching.value = false;
        },
        () => {
            flash.value = 'No se pudo abrir Google Drive.';
            setTimeout(() => { flash.value = null; }, 4000);
        }
    );
}

async function saveAttachment(d) {
    try {
        const res = await fetch(route('admin.tasks.attachments.store', selected.value.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ nombre: d.name, url: d.url, mime: d.mimeType }),
        });
        if (!res.ok) throw new Error();
        const doc = await res.json();
        if (!Array.isArray(selected.value.documents)) selected.value.documents = [];
        selected.value.documents.unshift(doc);
    } catch (e) {
        flash.value = 'No se pudo adjuntar el documento.';
        setTimeout(() => { flash.value = null; }, 4000);
    }
}

async function removeAttachment(doc) {
    try {
        const res = await fetch(route('admin.tasks.attachments.destroy', { task: selected.value.id, document: doc.id }), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error();
        selected.value.documents = selected.value.documents.filter((x) => x.id !== doc.id);
    } catch (e) {
        flash.value = 'No se pudo quitar el documento.';
        setTimeout(() => { flash.value = null; }, 4000);
    }
}

// --- Adjuntar un documento que ya pertenece al proceso (importado por la IA, etc.) ---
const showProcessDocs = ref(false);
const attachingProcessDoc = ref(null); // id del documento que se está adjuntando

async function attachFromProcess(doc) {
    attachingProcessDoc.value = doc.id;
    try {
        const res = await fetch(route('admin.tasks.attachments.from_process', selected.value.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ document_id: doc.id }),
        });
        if (!res.ok) throw new Error();
        const saved = await res.json();
        if (!Array.isArray(selected.value.documents)) selected.value.documents = [];
        selected.value.documents.unshift(saved);
        // Quitarlo de la lista de disponibles (ya está vinculado a la tarjeta).
        selected.value.processDocuments = (selected.value.processDocuments || []).filter((x) => x.id !== doc.id);
        if (!selected.value.processDocuments.length) showProcessDocs.value = false;
    } catch (e) {
        flash.value = 'No se pudo adjuntar el documento del proceso.';
        setTimeout(() => { flash.value = null; }, 4000);
    } finally {
        attachingProcessDoc.value = null;
    }
}

// --- Reasignar el responsable de la tarjeta desde el panel ---
const savingAssignee = ref(false);

// Busca la tarjeta dentro de las columnas del tablero (para reflejar el cambio sin recargar).
function findCard(id) {
    for (const e of props.estados) {
        const card = (columns[e] || []).find((t) => t.id === id);
        if (card) return card;
    }
    return null;
}

async function updateAssignee(event) {
    const val = event.target.value === '' ? null : Number(event.target.value);
    savingAssignee.value = true;
    try {
        const res = await fetch(route('admin.tasks.update', selected.value.id), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ asignado_a: val }),
        });
        if (!res.ok) throw new Error();
        const updated = await res.json();
        selected.value.asignado = updated.asignado;
        selected.value.asignado_a = updated.asignado_a;
        // Reflejar el avatar/nombre en la tarjeta del tablero.
        const card = findCard(selected.value.id);
        if (card) card.asignado = updated.asignado;
    } catch (e) {
        flash.value = 'No se pudo asignar la tarea (revisa tus permisos).';
        setTimeout(() => { flash.value = null; }, 4000);
    } finally {
        savingAssignee.value = false;
    }
}

// --- Adjuntar correos del proceso a la tarjeta (contexto para quien la ejecuta) ---
const showProcessEmails = ref(false);
const attachingEmail = ref(null); // id del correo que se está adjuntando

async function attachEmail(em) {
    attachingEmail.value = em.id;
    try {
        const res = await fetch(route('admin.tasks.emails.attach', selected.value.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ email_ingestion_id: em.id }),
        });
        if (!res.ok) throw new Error();
        const saved = await res.json();
        if (!Array.isArray(selected.value.emails)) selected.value.emails = [];
        selected.value.emails.unshift(saved);
        // Quitarlo de la lista de disponibles (ya está adjunto a la tarjeta).
        selected.value.processEmails = (selected.value.processEmails || []).filter((x) => x.id !== em.id);
        if (!selected.value.processEmails.length) showProcessEmails.value = false;
    } catch (e) {
        flash.value = 'No se pudo adjuntar el correo.';
        setTimeout(() => { flash.value = null; }, 4000);
    } finally {
        attachingEmail.value = null;
    }
}

async function removeEmail(em) {
    try {
        const res = await fetch(route('admin.tasks.emails.detach', { task: selected.value.id, ingestion: em.id }), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error();
        selected.value.emails = selected.value.emails.filter((x) => x.id !== em.id);
        // Devolverlo a la lista de disponibles del proceso.
        if (Array.isArray(selected.value.processEmails)) selected.value.processEmails.unshift(em);
    } catch (e) {
        flash.value = 'No se pudo quitar el correo.';
        setTimeout(() => { flash.value = null; }, 4000);
    }
}

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString('es-CO') : '—');
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString('es-CO') : '—');
</script>

<template>
    <Head :title="selectedProcess ? `Tablero · ${selectedProcess.codigo}` : 'Tablero de tareas'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <PageHeader titulo="Tablero de tareas" help-key="board" />
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-brand-400">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                        </span>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Buscar…"
                            class="w-56 rounded-lg border-brand-300 pl-8 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500"
                        />
                    </div>
                    <button
                        v-if="search || processFilter"
                        type="button"
                        class="rounded-lg px-2 py-1.5 text-sm text-brand-500 hover:text-brand-800"
                        @click="clearFilters"
                    >
                        Limpiar
                    </button>
                    <button
                        v-if="canCreate"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-accent-700"
                        @click="openCreate"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        Nueva tarjeta
                    </button>
                </div>
            </div>
        </template>

        <div class="board-canvas -mx-4 -my-6 min-h-[calc(100vh-4rem)] px-4 py-6 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <p v-if="flash" class="mb-4 rounded-lg bg-danger-50 px-4 py-2 text-sm text-danger-700 ring-1 ring-inset ring-danger-200">
                {{ flash }}
            </p>
            <p v-if="!canUpdate" class="mb-4 rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-800 ring-1 ring-inset ring-warning-200">
                Solo lectura: no tienes permiso para mover tareas (<code>tasks.update</code>).
            </p>

            <!-- Selector de proceso -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="processFilter = ''"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold shadow-sm ring-1 ring-inset transition"
                    :class="!processFilter
                        ? 'bg-accent-600 text-white ring-accent-600'
                        : 'bg-white text-brand-600 ring-brand-200 hover:bg-brand-50 hover:text-brand-900'"
                >
                    Todos
                    <span
                        class="rounded-full px-1.5 text-[10px] tabular-nums"
                        :class="!processFilter ? 'bg-white/20' : 'bg-brand-100 text-brand-500'"
                    >
                        {{ tasks.length }}
                    </span>
                </button>
                <button
                    v-for="p in processOptions"
                    :key="p.id"
                    type="button"
                    :title="p.titulo"
                    @click="processFilter = String(p.id)"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold shadow-sm ring-1 ring-inset transition"
                    :class="Number(processFilter) === p.id
                        ? 'bg-accent-600 text-white ring-accent-600'
                        : 'bg-white text-brand-600 ring-brand-200 hover:bg-brand-50 hover:text-brand-900'"
                >
                    {{ p.codigo }}
                    <span
                        class="rounded-full px-1.5 text-[10px] tabular-nums"
                        :class="Number(processFilter) === p.id ? 'bg-white/20' : 'bg-brand-100 text-brand-500'"
                    >
                        {{ processTaskCount(p.id) }}
                    </span>
                </button>
            </div>

            <!-- Resumen -->
            <div class="mb-5 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-700 shadow-sm ring-1 ring-brand-200">
                    {{ totalVisible }} tareas
                    <template v-if="selectedProcess">
                        <span class="text-brand-300">·</span>
                        <span class="max-w-56 truncate font-medium text-accent-700">{{ selectedProcess.codigo }} — {{ selectedProcess.titulo }}</span>
                    </template>
                </span>
                <span
                    v-for="estado in estados"
                    :key="`stat-${estado}`"
                    class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-2.5 py-1 text-xs font-medium ring-1 ring-brand-200/80"
                    :class="estadoTheme[estado]?.text"
                >
                    <span class="h-2 w-2 rounded-full" :class="estadoTheme[estado]?.dot"></span>
                    {{ labelEstado(estado) }}
                    <span class="text-brand-400">·</span>
                    <span class="tabular-nums">{{ visibleCount(estado) }}</span>
                </span>
            </div>

            <!-- Tablero -->
            <!-- :key=filterKey re-monta el tablero al cambiar de proceso, re-disparando
                 la animación de entrada en cascada de las columnas/tarjetas. -->
            <div :key="filterKey" class="flex gap-4 overflow-x-auto pb-4">
                <!-- Columna Bandeja: correos del proceso (no se arrastran; clic → responder) -->
                <section class="board-col flex w-72 shrink-0 flex-col rounded-2xl bg-warning-50/40 ring-1 ring-warning-200/70 backdrop-blur-sm">
                    <header class="flex items-center justify-between px-3 py-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white text-warning-600 shadow-sm ring-1 ring-warning-200/80">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                            </span>
                            <h3 class="text-sm font-semibold text-warning-700">Bandeja</h3>
                        </div>
                        <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-white px-1.5 text-xs font-semibold text-warning-600 shadow-sm ring-1 ring-inset ring-warning-200">
                            {{ inboxEmails.length }}
                        </span>
                    </header>

                    <div class="flex min-h-24 flex-1 flex-col gap-2.5 overflow-y-auto px-2.5 pb-3">
                        <article
                            v-for="em in inboxEmails"
                            :key="em.id"
                            class="kanban-card group relative cursor-pointer rounded-xl border-l-4 border-l-warning-400 bg-white p-3 shadow-sm ring-1 ring-warning-200/60 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md hover:ring-warning-300"
                            @click="openReply(em)"
                        >
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-warning-50 text-warning-600 ring-1 ring-warning-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                </span>
                                <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-snug text-brand-800">{{ em.subject }}</p>
                            </div>
                            <p class="mt-1.5 truncate text-xs text-brand-500">{{ em.from }}</p>

                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span
                                    v-if="em.respondido"
                                    class="inline-flex items-center gap-1 rounded-full bg-success-50 px-2 py-0.5 text-[11px] font-medium text-success-700 ring-1 ring-inset ring-success-200"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                    Respondido
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-0.5 text-[11px] font-medium text-warning-700 ring-1 ring-inset ring-warning-200"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-warning-500"></span>
                                    Pendiente
                                </span>
                                <span
                                    v-if="em.process && !processFilter"
                                    class="inline-flex items-center rounded-full bg-accent-50 px-2 py-0.5 text-[11px] font-semibold text-accent-700 ring-1 ring-inset ring-accent-100"
                                >
                                    {{ em.process.codigo }}
                                </span>
                                <span class="ml-auto text-[11px] text-brand-400">{{ formatDate(em.received_at) }}</span>
                            </div>
                        </article>

                        <p
                            v-if="inboxEmails.length === 0"
                            class="rounded-xl border border-dashed border-warning-300/70 py-8 text-center text-xs text-warning-500/80"
                        >
                            Sin correos
                        </p>
                    </div>
                </section>

                <section
                    v-for="(estado, idx) in estados"
                    :key="estado"
                    class="board-col flex w-72 shrink-0 flex-col rounded-2xl ring-1 ring-brand-200/70 backdrop-blur-sm"
                    :class="estadoTheme[estado]?.col"
                    :style="{ animationDelay: `${idx * 70}ms` }"
                >
                    <header class="flex items-center justify-between px-3 py-3">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-brand-200/80"
                                :class="estadoTheme[estado]?.text"
                                v-html="estadoTheme[estado]?.icon"
                            />
                            <h3 class="text-sm font-semibold" :class="estadoTheme[estado]?.text">{{ labelEstado(estado) }}</h3>
                        </div>
                        <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-white px-1.5 text-xs font-semibold text-brand-500 shadow-sm ring-1 ring-inset ring-brand-200">
                            {{ visibleCount(estado) }}
                        </span>
                    </header>

                    <draggable
                        v-model="columns[estado]"
                        :group="{ name: 'tasks' }"
                        item-key="id"
                        :disabled="!canUpdate"
                        :animation="180"
                        ghost-class="kanban-ghost"
                        drag-class="kanban-drag"
                        class="flex min-h-24 flex-1 flex-col gap-2.5 px-2.5 pb-3"
                        @change="(e) => onChange(e, estado)"
                    >
                        <template #item="{ element, index }">
                            <article
                                v-show="matches(element)"
                                class="kanban-card group relative cursor-pointer rounded-xl border-l-4 bg-white p-3 shadow-sm ring-1 ring-brand-200/70 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md hover:ring-accent-300"
                                :class="prioridadTheme[element.prioridad]?.accent || 'border-l-brand-300'"
                                :style="{ animationDelay: `${Math.min(index, 8) * 45}ms` }"
                                @click="openTask(element)"
                            >
                                <p class="text-sm font-semibold leading-snug text-brand-800">{{ element.titulo }}</p>

                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ring-brand-200"
                                        :class="prioridadTheme[element.prioridad]?.text"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :class="prioridadTheme[element.prioridad]?.dot"></span>
                                        {{ prioridadTheme[element.prioridad]?.label || element.prioridad }}
                                    </span>
                                    <!-- Con un proceso seleccionado el código sería redundante en cada tarjeta. -->
                                    <span
                                        v-if="element.process && !processFilter"
                                        class="inline-flex items-center rounded-full bg-accent-50 px-2 py-0.5 text-[11px] font-semibold text-accent-700 ring-1 ring-inset ring-accent-100"
                                    >
                                        {{ element.process.codigo }}
                                    </span>
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-1.5">
                                        <span
                                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ring-2 ring-white"
                                            :class="avatarColor(element.asignado)"
                                            :title="element.asignado || 'Sin asignar'"
                                        >
                                            {{ element.asignado ? initials(element.asignado) : '—' }}
                                        </span>
                                        <span class="truncate text-xs text-brand-500">{{ element.asignado || 'Sin asignar' }}</span>
                                    </div>
                                    <span
                                        v-if="dueMeta(element.fecha_limite, element.estado)"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                        :class="dueMeta(element.fecha_limite, element.estado).tone"
                                    >
                                        <span v-html="icons.calendar" />
                                        {{ dueMeta(element.fecha_limite, element.estado).label }}
                                    </span>
                                </div>
                            </article>
                        </template>

                        <template #footer>
                            <p
                                v-if="visibleCount(estado) === 0"
                                class="rounded-xl border border-dashed border-brand-300/80 py-8 text-center text-xs text-brand-400"
                            >
                                Sin tareas
                            </p>
                        </template>
                    </draggable>
                </section>
            </div>
        </div>

        <!-- Modal: nueva tarjeta -->
        <transition name="drawer">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-brand-900/40 backdrop-blur-sm" @click="showCreate = false"></div>

                <form
                    class="relative z-10 flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
                    @submit.prevent="submitCreate"
                >
                    <header class="flex items-center justify-between border-b border-brand-200 px-5 py-4">
                        <h3 class="text-base font-semibold text-brand-900">Nueva tarjeta</h3>
                        <button type="button" class="rounded-md p-1 text-brand-400 hover:bg-brand-100 hover:text-brand-700" @click="showCreate = false">✕</button>
                    </header>

                    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-5">
                        <div>
                            <label class="block text-xs font-medium text-brand-600">Título *</label>
                            <input v-model="createForm.titulo" type="text" maxlength="200" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" placeholder="Ej: Radicar contestación de demanda" />
                            <p v-if="createForm.errors.titulo" class="mt-1 text-xs text-danger-600">{{ createForm.errors.titulo }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-brand-600">Proceso *</label>
                            <select v-model="createForm.process_id" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                <option value="">Selecciona un proceso…</option>
                                <option v-for="p in processes" :key="p.id" :value="p.id">{{ p.codigo }} — {{ p.titulo }}</option>
                            </select>
                            <p v-if="createForm.errors.process_id" class="mt-1 text-xs text-danger-600">{{ createForm.errors.process_id }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-brand-600">Prioridad</label>
                                <select v-model="createForm.prioridad" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                    <option v-for="pr in prioridades" :key="pr" :value="pr">{{ prioridadTheme[pr]?.label || pr }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-brand-600">Estado inicial</label>
                                <select v-model="createForm.estado" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                    <option v-for="e in estados" :key="e" :value="e">{{ labelEstado(e) }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-brand-600">Asignar a</label>
                                <select v-model="createForm.asignado_a" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                    <option value="">Sin asignar</option>
                                    <option v-for="u in assignees" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-brand-600">Fecha límite</label>
                                <input v-model="createForm.fecha_limite" type="date" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-brand-600">Descripción</label>
                            <textarea v-model="createForm.descripcion" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" placeholder="Detalle opcional de la tarea…"></textarea>
                        </div>
                    </div>

                    <footer class="flex justify-end gap-2 border-t border-brand-200 px-5 py-4">
                        <button type="button" class="rounded-lg border border-brand-200 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50" @click="showCreate = false">Cancelar</button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-accent-700 disabled:opacity-60"
                        >
                            {{ createForm.processing ? 'Creando…' : 'Crear tarjeta' }}
                        </button>
                    </footer>
                </form>
            </div>
        </transition>

        <!-- Panel de detalle (drawer) -->
        <transition name="drawer">
            <div v-if="selected" class="fixed inset-0 z-40 flex justify-end">
                <div class="absolute inset-0 bg-brand-900/40 backdrop-blur-sm" @click="closeDetail"></div>

                <aside class="relative z-10 flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-2xl">
                    <div class="h-1.5 w-full" :class="estadoTheme[selected.estado]?.dot"></div>
                    <header class="flex items-start justify-between gap-4 border-b border-brand-200 px-5 py-4">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold leading-snug text-brand-900">{{ selected.titulo }}</h3>
                            <p v-if="selected.process" class="mt-0.5 truncate text-xs text-brand-500">
                                {{ selected.process.codigo }} — {{ selected.process.titulo }}
                            </p>
                        </div>
                        <button type="button" class="shrink-0 rounded-md p-1 text-brand-400 hover:bg-brand-100 hover:text-brand-700" @click="closeDetail">✕</button>
                    </header>

                    <div v-if="loadingDetail" class="px-5 py-10 text-center text-sm text-brand-500">Cargando…</div>

                    <div v-else class="flex-1 space-y-6 px-5 py-5">
                        <!-- Meta chips -->
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium ring-1 ring-inset ring-brand-200" :class="estadoTheme[selected.estado]?.text">
                                <span class="h-1.5 w-1.5 rounded-full" :class="estadoTheme[selected.estado]?.dot"></span>
                                {{ labelEstado(selected.estado) }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium ring-1 ring-inset ring-brand-200" :class="prioridadTheme[selected.prioridad]?.text">
                                <span class="h-1.5 w-1.5 rounded-full" :class="prioridadTheme[selected.prioridad]?.dot"></span>
                                {{ prioridadTheme[selected.prioridad]?.label || selected.prioridad }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-brand-400">Asignado</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold" :class="avatarColor(selected.asignado)">
                                        {{ selected.asignado ? initials(selected.asignado) : '—' }}
                                    </span>
                                    <select
                                        v-if="canUpdate"
                                        :value="selected.asignado_a ?? ''"
                                        :disabled="savingAssignee"
                                        class="w-full rounded-lg border-brand-300 py-1 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500 disabled:opacity-60"
                                        @change="updateAssignee"
                                    >
                                        <option value="">Sin asignar</option>
                                        <option v-for="u in assignees" :key="u.id" :value="u.id">{{ u.name }}</option>
                                    </select>
                                    <span v-else class="text-brand-700">{{ selected.asignado || 'Sin asignar' }}</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-brand-400">Fecha límite</p>
                                <p class="mt-1 text-brand-700">{{ formatDate(selected.fecha_limite) }}</p>
                            </div>
                        </div>

                        <div v-if="selected.descripcion">
                            <p class="text-xs uppercase tracking-wide text-brand-400">Descripción</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-brand-700">{{ selected.descripcion }}</p>
                        </div>

                        <!-- Resumen IA del proceso -->
                        <div class="overflow-hidden rounded-xl bg-gradient-to-br from-accent-50 to-accent-100 p-4 ring-1 ring-inset ring-accent-100">
                            <div class="flex items-center gap-2">
                                <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-accent-600 text-white shadow-sm">✨</span>
                                <p class="text-sm font-semibold text-accent-900">Resumen del proceso</p>
                            </div>
                            <template v-if="selected.ai && selected.ai.summary">
                                <p class="mt-2.5 whitespace-pre-line text-sm leading-relaxed text-accent-900/90">{{ selected.ai.summary }}</p>
                                <p v-if="selected.ai.generado_at" class="mt-3 border-t border-accent-100 pt-3 text-xs text-accent-700/70">
                                    Generado por IA · {{ formatDateTime(selected.ai.generado_at) }}
                                </p>
                            </template>
                            <p v-else class="mt-2 text-sm italic text-accent-700/70">
                                Este proceso aún no tiene resumen de IA. Genéralo desde la ficha del proceso.
                            </p>
                        </div>

                        <!-- Documentos -->
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs uppercase tracking-wide text-brand-400">Documentos</p>
                                <div class="flex items-center gap-1.5">
                                    <!-- Adjuntar un documento que ya está en el proceso (importado por la IA, etc.) -->
                                    <button
                                        v-if="canUpload && selected.processDocuments && selected.processDocuments.length"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-accent-700 shadow-sm ring-1 ring-inset ring-accent-200 transition hover:bg-accent-50"
                                        @click="showProcessDocs = !showProcessDocs"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776"/></svg>
                                        Del proceso
                                        <span class="rounded-full bg-accent-100 px-1.5 text-[10px] tabular-nums">{{ selected.processDocuments.length }}</span>
                                    </button>
                                    <button
                                        v-if="canUpload && googlePicker.enabled"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md bg-accent-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition hover:bg-accent-700 disabled:opacity-60"
                                        :disabled="attaching"
                                        @click="attachFromDrive"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                                        {{ attaching ? 'Adjuntando…' : 'Adjuntar de Drive' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Selector de documentos del proceso disponibles -->
                            <div
                                v-if="showProcessDocs && selected.processDocuments && selected.processDocuments.length"
                                class="mt-2 rounded-lg border border-accent-100 bg-accent-50/40 p-2"
                            >
                                <p class="px-1 pb-1 text-[11px] font-medium text-accent-700/80">Documentos del proceso · toca para adjuntar</p>
                                <ul class="space-y-1">
                                    <li v-for="pd in selected.processDocuments" :key="pd.id">
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 rounded-md bg-white px-2.5 py-2 text-left text-sm ring-1 ring-inset ring-brand-200 transition hover:ring-accent-300 disabled:opacity-60"
                                            :disabled="attachingProcessDoc === pd.id"
                                            @click="attachFromProcess(pd)"
                                        >
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-500 ring-1 ring-brand-200">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1 truncate text-brand-700">{{ pd.nombre }}</span>
                                            <span v-if="pd.generado_por_ia" class="shrink-0 rounded-full bg-accent-50 px-1.5 py-0.5 text-[10px] font-semibold text-accent-700 ring-1 ring-inset ring-accent-100">IA</span>
                                            <span class="shrink-0 text-xs font-medium text-accent-600">
                                                {{ attachingProcessDoc === pd.id ? '…' : '+ Adjuntar' }}
                                            </span>
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <p
                                v-if="canUpload && !googlePicker.enabled"
                                class="mt-2 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800 ring-1 ring-inset ring-warning-200"
                            >
                                Google Drive aún no está configurado (faltan credenciales en el <code>.env</code>).
                            </p>

                            <ul v-if="selected.documents && selected.documents.length" class="mt-2 space-y-2">
                                <li
                                    v-for="d in selected.documents"
                                    :key="d.id"
                                    class="flex items-center gap-2 rounded-lg bg-brand-50 p-2.5 ring-1 ring-inset ring-brand-200"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white text-success-600 ring-1 ring-brand-200">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                    </span>
                                    <a :href="d.url" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-sm font-medium text-brand-700 hover:text-accent-700">
                                        {{ d.nombre }}
                                    </a>
                                    <a :href="d.url" target="_blank" rel="noopener" class="shrink-0 text-brand-400 hover:text-accent-600" title="Abrir en Drive">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                    </a>
                                    <button
                                        v-if="canDeleteDoc"
                                        type="button"
                                        class="shrink-0 text-brand-400 hover:text-danger-600"
                                        title="Quitar"
                                        @click="removeAttachment(d)"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm italic text-brand-400">Sin documentos adjuntos.</p>
                        </div>

                        <!-- Correos del proceso (contexto para quien ejecuta la tarjeta) -->
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs uppercase tracking-wide text-brand-400">Correos</p>
                                <button
                                    v-if="canUpdate && selected.processEmails && selected.processEmails.length"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-info-700 shadow-sm ring-1 ring-inset ring-info-200 transition hover:bg-info-50"
                                    @click="showProcessEmails = !showProcessEmails"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                    Del proceso
                                    <span class="rounded-full bg-info-100 px-1.5 text-[10px] tabular-nums">{{ selected.processEmails.length }}</span>
                                </button>
                            </div>

                            <!-- Selector de correos del proceso disponibles -->
                            <div
                                v-if="showProcessEmails && selected.processEmails && selected.processEmails.length"
                                class="mt-2 rounded-lg border border-info-100 bg-info-50/40 p-2"
                            >
                                <p class="px-1 pb-1 text-[11px] font-medium text-info-700/80">Correos del proceso · toca para adjuntar</p>
                                <ul class="space-y-1">
                                    <li v-for="pe in selected.processEmails" :key="pe.id">
                                        <button
                                            type="button"
                                            class="flex w-full items-start gap-2 rounded-md bg-white px-2.5 py-2 text-left text-sm ring-1 ring-inset ring-brand-200 transition hover:ring-info-300 disabled:opacity-60"
                                            :disabled="attachingEmail === pe.id"
                                            @click="attachEmail(pe)"
                                        >
                                            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-brand-50 text-info-500 ring-1 ring-brand-200">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-medium text-brand-700">{{ pe.subject }}</span>
                                                <span class="block truncate text-xs text-brand-400">{{ pe.from }} · {{ formatDate(pe.received_at) }}</span>
                                            </span>
                                            <span class="mt-0.5 shrink-0 text-xs font-medium text-info-600">
                                                {{ attachingEmail === pe.id ? '…' : '+ Adjuntar' }}
                                            </span>
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <ul v-if="selected.emails && selected.emails.length" class="mt-2 space-y-2">
                                <li
                                    v-for="em in selected.emails"
                                    :key="em.id"
                                    class="rounded-lg bg-brand-50 p-2.5 ring-1 ring-inset ring-brand-200"
                                >
                                    <div class="flex items-start gap-2">
                                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white text-info-600 ring-1 ring-brand-200">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-brand-700">{{ em.subject }}</p>
                                            <p class="truncate text-xs text-brand-400">{{ em.from }} · {{ formatDate(em.received_at) }}</p>
                                        </div>
                                        <button
                                            v-if="canUpdate"
                                            type="button"
                                            class="shrink-0 text-brand-400 hover:text-danger-600"
                                            title="Quitar"
                                            @click="removeEmail(em)"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </div>
                                    <p v-if="em.preview" class="mt-2 whitespace-pre-line text-xs text-brand-500">{{ em.preview }}</p>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm italic text-brand-400">Sin correos adjuntos.</p>
                        </div>

                        <!-- Comentarios -->
                        <div>
                            <p class="text-xs uppercase tracking-wide text-brand-400">Comentarios</p>
                            <ul v-if="selected.comments && selected.comments.length" class="mt-2 space-y-3">
                                <li v-for="c in selected.comments" :key="c.id" class="rounded-lg bg-brand-50 p-3 ring-1 ring-inset ring-brand-200">
                                    <p class="whitespace-pre-line text-sm text-brand-700">{{ c.body }}</p>
                                    <p class="mt-1 text-xs text-brand-400">{{ c.user || 'Sistema' }} · {{ formatDateTime(c.created_at) }}</p>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm italic text-brand-400">Sin comentarios.</p>
                        </div>

                        <div v-if="selected.process" class="border-t border-brand-200 pt-4">
                            <Link :href="route('admin.processes.show', selected.process.id)" class="text-sm font-semibold text-accent-600 hover:text-accent-800">
                                Ver proceso completo →
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </transition>

        <!-- Responder correo de la bandeja (reutiliza el flujo de la ficha del proceso) -->
        <EmailReplyModal
            :show="replyModal"
            :process="replyProcess || {}"
            :email="replyEmail"
            @close="replyModal = false"
            @sent="onReplySent"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
/* Lienzo tipo Miro: patrón de puntos sutil */
.board-canvas {
    background-color: #f8fafc;
    background-image: radial-gradient(circle, rgba(100, 116, 139, 0.13) 1px, transparent 1px);
    background-size: 22px 22px;
}

/* Entrada escalonada de columnas */
.board-col {
    animation: colIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes colIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Entrada escalonada de tarjetas: se re-dispara al cambiar de proceso
   (el contenedor del tablero se re-monta vía :key). */
.kanban-card {
    animation: cardIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(10px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Arrastre */
.kanban-ghost {
    opacity: 0.4;
}
.kanban-drag {
    transform: rotate(2deg);
    cursor: grabbing;
}
/* Durante el arrastre, la animación de entrada no debe pelear con el transform. */
.kanban-ghost,
.kanban-drag {
    animation: none;
}

/* Accesibilidad: respeta la preferencia de movimiento reducido. */
@media (prefers-reduced-motion: reduce) {
    .board-col,
    .kanban-card {
        animation: none;
    }
}

/* Drawer */
.drawer-enter-active,
.drawer-leave-active {
    transition: opacity 0.2s ease;
}
.drawer-enter-from,
.drawer-leave-to {
    opacity: 0;
}
</style>
