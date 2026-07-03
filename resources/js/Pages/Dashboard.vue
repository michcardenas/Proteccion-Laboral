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
                            Usa el menú lateral para acceder a los módulos de la plataforma.
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

        </div>
    </AuthenticatedLayout>
</template>
