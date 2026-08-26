<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'gray',
        validator: (v) =>
            [
                // Semánticas: preferir estas. Se nombran por lo que significan.
                'success', 'warning', 'danger', 'info', 'neutral', 'brand',
                // Por color: quedan por compatibilidad con las pantallas antiguas.
                'gray', 'green', 'red', 'yellow', 'blue', 'indigo', 'purple', 'teal',
            ].includes(v),
    },
    label: { type: String, required: true },
});

const classes = computed(() => {
    const map = {
        success: 'bg-success-50 text-success-700 ring-success-200',
        warning: 'bg-warning-50 text-warning-700 ring-warning-200',
        danger: 'bg-danger-50 text-danger-700 ring-danger-200',
        info: 'bg-info-50 text-info-700 ring-info-200',
        neutral: 'bg-brand-100 text-brand-700 ring-brand-200',
        brand: 'bg-brand-900 text-white ring-brand-900',
        gray: 'bg-brand-100 text-brand-700 ring-brand-300',
        green: 'bg-success-50 text-success-700 ring-success-200',
        red: 'bg-danger-50 text-danger-700 ring-danger-200',
        yellow: 'bg-warning-50 text-warning-800 ring-warning-200',
        blue: 'bg-info-50 text-info-700 ring-info-200',
        indigo: 'bg-accent-50 text-accent-700 ring-accent-200',
        purple: 'bg-accent-50 text-accent-700 ring-accent-200',
        teal: 'bg-success-50 text-success-700 ring-success-200',
    };
    return map[props.variant] ?? map.neutral;
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
