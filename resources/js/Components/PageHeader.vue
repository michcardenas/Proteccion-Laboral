<script setup>
import { computed, onMounted, watch } from 'vue';
import { getHelp } from '@/help';
import { useHelp } from '@/Composables/useHelp';

/**
 * Cabecera única de las páginas: título, una línea que explica de qué va la pantalla y,
 * si el módulo tiene ayuda registrada en `help.js`, el botón que despliega el panel.
 *
 * El resumen sale del catálogo de ayuda para que la explicación no se escriba dos veces;
 * `resumen` permite sobreescribirlo en pantallas de detalle (donde el subtítulo suele ser
 * el nombre del caso o del cliente).
 */
const props = defineProps({
    titulo: { type: String, required: true },
    resumen: { type: String, default: null },
    helpKey: { type: String, default: null },
});

const { toggle, setHelp } = useHelp();

const ayuda = computed(() => (props.helpKey ? getHelp(props.helpKey) : null));
const bajada = computed(() => props.resumen ?? ayuda.value?.resumen ?? null);

onMounted(() => setHelp(props.helpKey));
watch(() => props.helpKey, (key) => setHelp(key));
</script>

<template>
    <div class="flex min-w-0 items-center gap-3">
        <div class="min-w-0">
            <h1 class="truncate text-lg font-semibold text-brand-900 sm:text-xl">{{ titulo }}</h1>
            <p v-if="bajada" class="truncate text-xs text-brand-500">{{ bajada }}</p>
        </div>

        <button
            v-if="ayuda"
            type="button"
            class="hidden shrink-0 items-center gap-1.5 rounded-full border border-brand-200 px-2.5 py-1 text-xs font-medium text-brand-600 transition hover:border-accent-500 hover:text-accent-600 sm:inline-flex"
            @click="toggle"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3.25a.75.75 0 000 1.5h.01a.75.75 0 000-1.5H10zM9.25 10a.75.75 0 01.75-.75h.01a.75.75 0 01.75.75v3.25a.75.75 0 01-1.5 0V10z" clip-rule="evenodd" />
            </svg>
            ¿Cómo funciona?
        </button>
    </div>
</template>
