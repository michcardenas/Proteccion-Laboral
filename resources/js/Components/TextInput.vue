<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-md border-slate-300 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-brand-900 focus:ring-brand-900"
        v-model="model"
        ref="input"
    />
</template>
