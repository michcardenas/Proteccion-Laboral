<script setup>
import { computed } from 'vue';

/**
 * Nota contextual junto a un control o una sección: aclara un comportamiento en el punto
 * exacto donde puede malinterpretarse ("esto envía el correo de verdad", "esto consume
 * servicio de IA"). Usa los colores semánticos, no un color inventado por pantalla.
 */
const props = defineProps({
    tono: { type: String, default: 'info', validator: (v) => ['info', 'warning', 'success', 'neutral'].includes(v) },
    titulo: { type: String, default: null },
});

const clases = computed(() => ({
    info: 'border-info-200 bg-info-50 text-info-700',
    warning: 'border-warning-200 bg-warning-50 text-warning-700',
    success: 'border-success-200 bg-success-50 text-success-700',
    neutral: 'border-brand-200 bg-brand-50 text-brand-600',
}[props.tono]));
</script>

<template>
    <div class="rounded-lg border px-3 py-2.5 text-sm" :class="clases">
        <p v-if="titulo" class="font-medium">{{ titulo }}</p>
        <div :class="titulo ? 'mt-0.5' : ''">
            <slot />
        </div>
    </div>
</template>
