<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import HelpPanel from '@/Components/HelpPanel.vue';
import { useHelp } from '@/Composables/useHelp';

// La ayuda del portal se declara desde cada página: <PortalLayout help-key="...">
const props = defineProps({
    helpKey: { type: String, default: null },
});

const { toggle, setHelp } = useHelp();
onMounted(() => setHelp(props.helpKey));

const page = usePage();
const client = computed(() => page.props.client ?? {});

const initials = (name) =>
    (name || '?').trim().split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase();

const logout = () => {
    router.post(route('portal.logout'));
};
</script>

<template>
    <div class="min-h-screen bg-brand-50 font-sans text-brand-900">
        <!-- Barra superior -->
        <header class="sticky top-0 z-30 border-b border-brand-200 bg-white/85 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <Link :href="route('portal.dashboard')" class="flex items-center gap-2.5">
                    <ApplicationLogo class="h-8 w-auto" />
                    <span class="hidden text-sm font-semibold text-brand-700 sm:inline">Portal del cliente</span>
                </Link>

                <div class="flex items-center gap-3">
                    <button
                        v-if="helpKey"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-brand-200 px-2.5 py-1 text-xs font-medium text-brand-600 transition hover:border-accent-500 hover:text-accent-600"
                        @click="toggle"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3.25a.75.75 0 000 1.5h.01a.75.75 0 000-1.5H10zM9.25 10a.75.75 0 01.75-.75h.01a.75.75 0 01.75.75v3.25a.75.75 0 01-1.5 0V10z" clip-rule="evenodd" />
                        </svg>
                        <span class="hidden sm:inline">¿Cómo funciona?</span>
                    </button>

                    <div class="hidden text-right sm:block">
                        <p class="max-w-[14rem] truncate text-sm font-semibold text-brand-800">{{ client.razon_social }}</p>
                        <p class="text-[11px] text-brand-400">NIT {{ client.nit }}</p>
                    </div>
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-900 text-xs font-bold text-white">
                        {{ initials(client.razon_social) }}
                    </span>
                    <button
                        type="button"
                        @click="logout"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-600 transition hover:bg-brand-50 hover:text-brand-900"
                        title="Cerrar sesión"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                        </svg>
                        <span class="hidden sm:inline">Salir</span>
                    </button>
                </div>
            </div>
        </header>

        <HelpPanel />

        <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>

        <footer class="mx-auto max-w-5xl px-4 pb-8 pt-2 text-center text-xs text-brand-400 sm:px-6">
            © {{ new Date().getFullYear() }} Protección Laboral – Soluciones Legales SAS
        </footer>
    </div>
</template>
