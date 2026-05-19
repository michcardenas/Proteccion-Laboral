<script setup>
defineProps({
    variant: { type: String, default: 'dark', validator: (v) => ['dark', 'light', 'mark'].includes(v) },
    alt: { type: String, default: 'Protección Laboral' },
});
</script>

<template>
    <img
        v-if="variant === 'dark'"
        :src="'/images/logosinfondo.png'"
        :alt="alt"
        class="object-contain"
    />
    <img
        v-else-if="variant === 'light'"
        :src="'/images/logosinfondo.png'"
        :alt="alt"
        class="object-contain brightness-0 invert"
    />
    <img
        v-else
        :src="'/images/logosinfondo.png'"
        :alt="alt"
        class="object-contain"
    />
</template>
