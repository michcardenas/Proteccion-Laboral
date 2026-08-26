<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen bg-brand-50 font-sans text-brand-900 lg:grid lg:grid-cols-2">
        <!-- Brand panel (desktop only) -->
        <div class="relative hidden overflow-hidden bg-brand-900 lg:flex lg:flex-col lg:justify-between lg:p-10">
            <div
                class="pointer-events-none absolute inset-0 opacity-20"
                style="background-image: radial-gradient(circle at 20% 20%, rgba(99,102,241,0.6), transparent 45%), radial-gradient(circle at 80% 80%, rgba(255,255,255,0.15), transparent 50%);"
            />
            <div class="relative flex items-center gap-3">
                <ApplicationLogo variant="light" class="h-14 w-auto" />
            </div>

            <div class="relative max-w-md">
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-accent-200">
                    Sistema operativo integral
                </p>
                <h1 class="mt-3 text-3xl font-semibold leading-tight text-white">
                    Gestiona tu firma jurídica desde un solo lugar.
                </h1>
                <p class="mt-3 text-sm leading-6 text-brand-300">
                    Casos, procesos, etapas, documentos, facturación y portal de clientes — coordinados con
                    tu equipo en tiempo real.
                </p>

                <ul class="mt-8 space-y-3 text-sm text-brand-200">
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 rounded-full bg-accent-400" />
                        Asesoría laboral permanente y por evento
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 rounded-full bg-accent-400" />
                        Representación judicial y estrategia
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 rounded-full bg-accent-400" />
                        IA aplicada a generación y análisis legal
                    </li>
                </ul>
            </div>

            <p class="relative text-xs text-brand-400">
                © {{ new Date().getFullYear() }} Protección Laboral – Soluciones Legales SAS
            </p>
        </div>

        <!-- Form panel -->
        <div class="flex min-h-screen flex-col justify-center px-6 py-10 sm:px-10 lg:min-h-0">
            <div class="mx-auto w-full max-w-md">
                <Link href="/" class="mb-8 inline-flex items-center gap-2 lg:hidden">
                    <ApplicationLogo class="h-12 w-auto" />
                </Link>
                <slot />
            </div>
        </div>
    </div>
</template>
