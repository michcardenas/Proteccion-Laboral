<script setup>
import { useHelp } from '@/Composables/useHelp';

// Panel de ayuda del módulo actual. Se despliega a lo ancho bajo la barra superior;
// lo abre el botón "¿Cómo funciona?" del PageHeader.
const { ayuda, abierto, cerrar } = useHelp();
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <section
            v-if="abierto && ayuda"
            class="border-b border-brand-200 bg-brand-50/70 px-4 py-5 sm:px-6 lg:px-8"
            aria-label="Cómo funciona este módulo"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-brand-900">{{ ayuda.titulo }}</h2>
                    <p v-if="ayuda.paraQue" class="mt-1 max-w-3xl text-sm text-brand-700">{{ ayuda.paraQue }}</p>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded-md p-1 text-brand-500 transition hover:bg-brand-100 hover:text-brand-900"
                    aria-label="Cerrar ayuda"
                    @click="cerrar"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="mt-4 grid gap-5 lg:grid-cols-3">
                <div v-if="ayuda.pasos?.length">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-500">Cómo se usa</p>
                    <ol class="mt-2 space-y-1.5">
                        <li v-for="(paso, i) in ayuda.pasos" :key="paso" class="flex gap-2 text-sm text-brand-800">
                            <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-brand-200 text-[10px] font-semibold text-brand-800">
                                {{ i + 1 }}
                            </span>
                            <span>{{ paso }}</span>
                        </li>
                    </ol>
                </div>

                <div v-if="ayuda.automatico?.length">
                    <p class="text-xs font-semibold uppercase tracking-wider text-success-700">Pasa solo</p>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="item in ayuda.automatico" :key="item" class="flex gap-2 text-sm text-brand-800">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 h-4 w-4 shrink-0 text-success-600">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="ayuda.ojo?.length">
                    <p class="text-xs font-semibold uppercase tracking-wider text-warning-700">Ten en cuenta</p>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="item in ayuda.ojo" :key="item" class="flex gap-2 text-sm text-brand-800">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 h-4 w-4 shrink-0 text-warning-600">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </Transition>
</template>
