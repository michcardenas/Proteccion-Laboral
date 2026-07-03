<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarNavItem from '@/Components/Sidebar/SidebarNavItem.vue';

const props = defineProps({
    collapsed: { type: Boolean, default: false },
});

defineEmits(['close', 'toggle-collapse']);

// `mini` = colapsado y SIN el mouse encima. Al pasar el mouse se expande.
const hovered = ref(false);
const mini = computed(() => props.collapsed && !hovered.value);

const page = usePage();
const user = computed(() => page.props.auth.user);
const userPermissions = computed(() => user.value?.permissions ?? []);
const userRoles = computed(() => user.value?.roles ?? []);

const can = (p) => userPermissions.value.includes(p);
const hasRole = (r) => userRoles.value.includes(r);

const emailsReviewCount = computed(() => page.props.emails_review_count ?? 0);

const icons = {
    dashboard: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10.5V21h4.5v-6h5v6H19V10.5"/></svg>`,
    board: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h12A2.25 2.25 0 0120.25 6v12A2.25 2.25 0 0118 20.25H6A2.25 2.25 0 013.75 18V6zM9 3.75v16.5m6-16.5v16.5"/></svg>`,
    users: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>`,
    clients: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>`,
    services: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a6.759 6.759 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>`,
    invoices: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg>`,
    payments: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>`,
    contracts: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>`,
    processes: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5"/></svg>`,
    ai: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>`,
    usage: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>`,
    gmail: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>`,
    inbox: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z"/></svg>`,
};

const navSections = computed(() => {
    const sections = [];

    sections.push({
        title: 'Principal',
        items: [
            { label: 'Dashboard', href: route('dashboard'), icon: icons.dashboard, key: 'dashboard' },
        ],
    });

    const operationItems = [];

    if (can('clients.view') || can('clients.view_assigned')) {
        operationItems.push({
            label: 'Clientes',
            href: route('admin.clients.index'),
            icon: icons.clients,
            key: 'admin.clients',
        });
    }

    if (can('contracts.view')) {
        operationItems.push({
            label: 'Contratos',
            href: route('admin.contracts.index'),
            icon: icons.contracts,
            key: 'admin.contracts',
        });
    }

    if (can('processes.view') || can('processes.view_assigned')) {
        operationItems.push({
            label: 'Procesos',
            href: route('admin.processes.index'),
            icon: icons.processes,
            key: 'admin.processes',
        });
    }

    if (can('tasks.view')) {
        operationItems.push({
            label: 'Tablero',
            href: route('admin.tasks.board'),
            icon: icons.board,
            key: 'admin.tasks.board',
        });
    }

    if (operationItems.length) {
        sections.push({ title: 'Operación', items: operationItems });
    }

    const aiItems = [];
    if (can('ai.use')) {
        aiItems.push({
            label: 'Playground IA',
            href: route('admin.ai.playground'),
            icon: icons.ai,
            key: 'admin.ai.playground',
        });
    }
    if (can('ai.usage_view')) {
        aiItems.push({
            label: 'Uso del mes',
            href: route('admin.ai.usage'),
            icon: icons.usage,
            key: 'admin.ai.usage',
        });
    }
    if (can('emails.review')) {
        aiItems.push({
            label: 'Revisión de correos',
            href: route('admin.emails.review.index'),
            icon: icons.inbox,
            key: 'admin.emails.review',
            badge: emailsReviewCount.value || null,
        });
    }
    if (aiItems.length) {
        sections.push({ title: 'Inteligencia Artificial', items: aiItems });
    }

    if (hasRole('director') || can('users.view')) {
        sections.push({
            title: 'Administración',
            items: [
                { label: 'Usuarios', href: route('admin.users.index'), icon: icons.users, key: 'admin.users' },
            ],
        });
    }

    if (hasRole('director')) {
        sections.push({
            title: 'Integraciones',
            items: [
                { label: 'Gmail', href: route('admin.integrations.gmail.status'), icon: icons.gmail, key: 'admin.integrations.gmail' },
            ],
        });
    }

    if (can('payments.view')) {
        sections.push({
            title: 'Finanzas',
            items: [
                { label: 'Facturación', href: route('admin.payments.index'), icon: icons.invoices, key: 'admin.payments' },
            ],
        });
    }

    return sections;
});

const isActive = (key) => {
    if (key === 'dashboard') return route().current('dashboard');
    if (key === 'admin.users') return route().current('admin.users.*');
    if (key === 'admin.clients') return route().current('admin.clients.*');
    if (key === 'admin.contracts') return route().current('admin.contracts.*');
    if (key === 'admin.processes') return route().current('admin.processes.*');
    if (key === 'admin.tasks.board') return route().current('admin.tasks.*');
    if (key === 'admin.ai.playground') return route().current('admin.ai.playground');
    if (key === 'admin.ai.usage') return route().current('admin.ai.usage');
    if (key === 'admin.emails.review') return route().current('admin.emails.review.*');
    if (key === 'admin.integrations.gmail') return route().current('admin.integrations.gmail.*');
    if (key === 'admin.payments') return route().current('admin.payments.*');
    return false;
};
</script>

<template>
    <aside
        class="flex h-full w-72 flex-col border-r border-slate-200 bg-white transition-[width] duration-200 ease-out"
        :class="[mini ? 'lg:w-20' : 'lg:w-72', collapsed && hovered ? 'lg:shadow-2xl' : '']"
        @mouseenter="hovered = true"
        @mouseleave="hovered = false"
    >
        <!-- Brand -->
        <div
            class="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-5"
            :class="mini ? 'lg:justify-center lg:px-3' : ''"
        >
            <Link
                :href="route('dashboard')"
                class="flex items-center gap-2"
                :class="mini ? 'lg:hidden' : ''"
            >
                <ApplicationLogo class="h-10 w-auto" />
            </Link>

            <!-- Colapsar (solo escritorio) -->
            <button
                type="button"
                class="hidden rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 lg:inline-flex"
                @click="$emit('toggle-collapse')"
                :aria-label="collapsed ? 'Expandir menú' : 'Colapsar menú'"
                :title="collapsed ? 'Expandir menú' : 'Colapsar menú'"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    stroke-width="2" stroke="currentColor" class="h-5 w-5 transition-transform duration-200"
                    :class="collapsed ? 'rotate-180' : ''"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 4.5l-7.5 7.5 7.5 7.5M11.25 4.5l-7.5 7.5 7.5 7.5"/>
                </svg>
            </button>

            <!-- Cerrar (solo móvil) -->
            <button
                type="button"
                class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 lg:hidden"
                @click="$emit('close')"
                aria-label="Cerrar menú"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Nav -->
        <nav class="scrollbar-thin flex-1 space-y-6 overflow-y-auto px-3 py-5">
            <div v-for="section in navSections" :key="section.title">
                <p
                    class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-slate-400"
                    :class="mini ? 'lg:hidden' : ''"
                >
                    {{ section.title }}
                </p>
                <div class="space-y-0.5">
                    <template v-for="item in section.items" :key="item.key">
                        <SidebarNavItem
                            v-if="!item.disabled"
                            :href="item.href"
                            :label="item.label"
                            :icon="item.icon"
                            :active="isActive(item.key)"
                            :collapsed="mini"
                            :badge="item.badge"
                        />
                        <div
                            v-else
                            :title="mini ? item.label : null"
                            class="flex cursor-not-allowed items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm text-slate-400"
                            :class="mini ? 'lg:justify-center lg:px-2' : ''"
                        >
                            <span class="flex items-center gap-3" :class="mini ? 'lg:gap-0' : ''">
                                <span class="flex h-5 w-5 items-center justify-center" v-html="item.icon" />
                                <span class="truncate" :class="mini ? 'lg:hidden' : ''">{{ item.label }}</span>
                            </span>
                            <span
                                v-if="item.soon"
                                class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-slate-500"
                                :class="mini ? 'lg:hidden' : ''"
                            >
                                Pronto
                            </span>
                        </div>
                    </template>
                </div>
            </div>
        </nav>

        <!-- User card -->
        <div class="border-t border-slate-100 p-4">
            <div class="flex items-center gap-3" :class="mini ? 'lg:justify-center' : ''">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-900 text-xs font-semibold text-white"
                    :title="mini ? user?.name : null"
                >
                    {{ user?.name?.split(' ').map(n => n[0]).slice(0,2).join('') }}
                </div>
                <div class="min-w-0 flex-1" :class="mini ? 'lg:hidden' : ''">
                    <p class="truncate text-sm font-medium text-slate-900">{{ user?.name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ userRoles[0] }}</p>
                </div>
            </div>
        </div>
    </aside>
</template>
