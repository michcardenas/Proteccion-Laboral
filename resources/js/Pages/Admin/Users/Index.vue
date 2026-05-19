<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    users: Object,
    filters: Object,
    roles: Array,
});

const page = usePage();
const currentUserId = page.props.auth.user?.id;

const search = ref(props.filters.search ?? '');
const role = ref(props.filters.role ?? '');
const isActive = ref(props.filters.is_active ?? '');

function debounce(fn, wait) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
    };
}

const applyFilters = debounce(() => {
    router.get(
        route('admin.users.index'),
        {
            search: search.value || undefined,
            role: role.value || undefined,
            is_active: isActive.value === '' ? undefined : isActive.value,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}, 300);

watch([search, role, isActive], applyFilters);

const columns = [
    { key: 'name', label: 'Usuario' },
    { key: 'role', label: 'Rol' },
    { key: 'is_active', label: 'Estado' },
    { key: 'last_login_at', label: 'Último acceso' },
    { key: 'actions', label: '', thClass: 'text-right', tdClass: 'text-right' },
];

const roleVariants = {
    director: 'purple',
    coordinador: 'indigo',
    abogado_interno: 'blue',
    abogado_externo: 'blue',
    apoderado: 'yellow',
    contador: 'green',
    cliente: 'gray',
};

const formatDate = (iso) => {
    if (!iso) return 'Nunca';
    return new Date(iso).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
};

const initialsFor = (name) => name?.split(' ').map(n => n[0]).slice(0, 2).join('') ?? '?';

const totalActive = computed(() => props.users.data?.filter((u) => u.is_active).length ?? 0);
const totalInactive = computed(() => (props.users.data?.length ?? 0) - totalActive.value);

const confirmDelete = ref(null);
const deleting = ref(false);

const askDelete = (user) => { confirmDelete.value = user; };

const performDelete = () => {
    if (!confirmDelete.value) return;
    deleting.value = true;
    router.delete(route('admin.users.destroy', confirmDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            confirmDelete.value = null;
        },
    });
};

const toggleActive = (user) => {
    router.patch(route('admin.users.toggle-active', user.id), {}, { preserveScroll: true });
};

const clearFilters = () => {
    search.value = '';
    role.value = '';
    isActive.value = '';
};

const hasActiveFilters = computed(() => !!search.value || !!role.value || isActive.value !== '');
</script>

<template>
    <Head title="Usuarios" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Gestión de usuarios</h1>
                <p class="text-xs text-slate-500">Crea, edita y administra los accesos de tu equipo.</p>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Page actions + summary -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2 text-xs text-slate-600">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        {{ totalActive }} activos
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1">
                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500" />
                        {{ totalInactive }} inactivos
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1">
                        Total: {{ users.total }}
                    </span>
                </div>
                <Link
                    :href="route('admin.users.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-brand-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-800"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Nuevo usuario
                </Link>
            </div>

            <!-- Filters -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label class="sr-only">Buscar</label>
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.34-4.34m0 0A7.5 7.5 0 1116.66 5.66a7.5 7.5 0 010 11"/>
                            </svg>
                            <TextInput
                                v-model="search"
                                type="text"
                                placeholder="Buscar por nombre o email…"
                                class="w-full pl-9"
                            />
                        </div>
                    </div>
                    <select
                        v-model="role"
                        class="rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="">Todos los roles</option>
                        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                    </select>
                    <div class="flex gap-2">
                        <select
                            v-model="isActive"
                            class="flex-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                        >
                            <option value="">Todos los estados</option>
                            <option value="1">Activos</option>
                            <option value="0">Inactivos</option>
                        </select>
                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            @click="clearFilters"
                            class="rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-600 hover:bg-slate-50"
                            title="Limpiar filtros"
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
                :rows="users.data"
                :paginator="users"
                empty-message="No hay usuarios que coincidan con los filtros."
            >
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-900 ring-1 ring-inset ring-brand-100">
                            {{ initialsFor(row.name) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900">{{ row.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ row.email }}</p>
                        </div>
                    </div>
                </template>

                <template #cell-role="{ row }">
                    <StatusBadge
                        v-if="row.role"
                        :variant="roleVariants[row.role] || 'gray'"
                        :label="row.role"
                    />
                    <span v-else class="text-xs text-slate-400">sin rol</span>
                </template>

                <template #cell-is_active="{ row }">
                    <StatusBadge
                        :variant="row.is_active ? 'green' : 'red'"
                        :label="row.is_active ? 'Activo' : 'Inactivo'"
                    />
                </template>

                <template #cell-last_login_at="{ row }">
                    <span class="text-xs text-slate-500">{{ formatDate(row.last_login_at) }}</span>
                </template>

                <template #cell-actions="{ row }">
                    <div class="flex justify-end gap-1.5">
                        <button
                            v-if="row.id !== currentUserId"
                            @click="toggleActive(row)"
                            class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                            {{ row.is_active ? 'Desactivar' : 'Activar' }}
                        </button>
                        <Link
                            :href="route('admin.users.edit', row.id)"
                            class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Editar
                        </Link>
                        <button
                            v-if="row.id !== currentUserId"
                            @click="askDelete(row)"
                            class="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                        >
                            Eliminar
                        </button>
                    </div>
                </template>
            </DataTable>
        </div>

        <ConfirmModal
            :show="!!confirmDelete"
            title="Eliminar usuario"
            :message="confirmDelete ? `¿Confirmas eliminar a ${confirmDelete.name}? Esta acción no se puede deshacer.` : ''"
            confirm-label="Sí, eliminar"
            variant="danger"
            :processing="deleting"
            @close="confirmDelete = null"
            @confirm="performDelete"
        />
    </AuthenticatedLayout>
</template>
