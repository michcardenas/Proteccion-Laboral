<script setup>
/**
 * Estado vacío que además ENSEÑA: en vez de un "No hay registros" seco, explica por qué
 * está vacío y qué hacer (o qué esperar, cuando se llena solo).
 */
defineProps({
    titulo: { type: String, required: true },
    descripcion: { type: String, default: null },
    // `auto` para lo que se llena solo (ingesta de correos, tareas autogeneradas):
    // evita que alguien busque un botón que no existe.
    variante: { type: String, default: 'accion', validator: (v) => ['accion', 'auto'].includes(v) },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-brand-200 bg-brand-50/40 px-6 py-10 text-center">
        <span
            class="flex h-10 w-10 items-center justify-center rounded-full"
            :class="variante === 'auto' ? 'bg-info-100 text-info-600' : 'bg-brand-100 text-brand-500'"
        >
            <svg v-if="variante === 'auto'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-.001 9.585a8.25 8.25 0 11-1.98-8.198l3.181 3.182m-4.991-.002h4.99" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
        </span>

        <p class="mt-3 text-sm font-medium text-brand-900">{{ titulo }}</p>
        <p v-if="descripcion" class="mt-1 max-w-md text-sm text-brand-500">{{ descripcion }}</p>

        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
