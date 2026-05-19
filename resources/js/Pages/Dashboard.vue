<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const role = computed(() => user.value?.roles?.[0] ?? 'sin rol');

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Buenos días';
    if (hour < 19) return 'Buenas tardes';
    return 'Buenas noches';
});

const stats = [
    { label: 'Clientes activos', value: '—', hint: 'Próximamente' },
    { label: 'Procesos en curso', value: '—', hint: 'Próximamente' },
    { label: 'Tareas pendientes', value: '—', hint: 'Próximamente' },
    { label: 'Documentos generados', value: '—', hint: 'Próximamente' },
];

const upcomingModules = [
    { title: 'Clientes y empresas', desc: 'Gestión de empresas, contactos y asignación de abogados.' },
    { title: 'Servicios y contratos', desc: 'Catálogo de servicios y contratación por modalidad.' },
    { title: 'Procesos, etapas y tareas', desc: 'Workflow operativo con SLAs y checklists.' },
    { title: 'Documentos y portal del cliente', desc: 'Generación, versionado y compartido seguro.' },
    { title: 'IA y facturación', desc: 'Generación con IA, facturas y reportes contables.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Dashboard</h1>
                <p class="text-xs text-slate-500">Vista general de la operación.</p>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Greeting card -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-brand-900 via-brand-900 to-brand-800 p-6 text-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-indigo-200">{{ greeting }}</p>
                        <h2 class="mt-1 text-2xl font-semibold sm:text-3xl">{{ user.name }}</h2>
                        <p class="mt-2 max-w-xl text-sm text-slate-200">
                            Estás conectado como <span class="font-semibold text-white">{{ role }}</span>.
                            Aquí verás el resumen de la operación a medida que se habiliten los módulos.
                        </p>
                    </div>
                    <Link
                        v-if="user.roles?.includes('director')"
                        :href="route('admin.users.index')"
                        class="inline-flex items-center gap-2 rounded-md bg-white/10 px-4 py-2 text-sm font-medium text-white ring-1 ring-inset ring-white/20 backdrop-blur transition hover:bg-white/20"
                    >
                        Gestionar usuarios
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                        </svg>
                    </Link>
                </div>
            </section>

            <!-- Stat cards -->
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ stat.label }}</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{{ stat.value }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ stat.hint }}</p>
                </div>
            </section>

            <!-- Roadmap -->
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-900">Próximos módulos</h3>
                    <p class="text-xs text-slate-500">Funcionalidades en construcción para tu firma.</p>
                </div>
                <ul class="divide-y divide-slate-100">
                    <li
                        v-for="(m, idx) in upcomingModules"
                        :key="m.title"
                        class="flex gap-4 px-6 py-4"
                    >
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sm font-semibold text-brand-900">
                            {{ idx + 1 }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-900">{{ m.title }}</p>
                            <p class="text-xs text-slate-500">{{ m.desc }}</p>
                        </div>
                        <span class="self-start rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600">
                            Pronto
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
