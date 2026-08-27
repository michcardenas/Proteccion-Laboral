<script setup>
defineProps({
    label: { type: String, required: true },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    hint: { type: String, default: null },
    for: { type: String, default: null },
});
</script>

<template>
    <div class="space-y-1">
        <label
            :for="$props.for"
            class="block text-sm font-medium text-brand-700"
        >
            {{ label }}
            <span v-if="required" class="text-danger-500">*</span>
        </label>
        <slot />
        <p v-if="hint && !error" class="text-xs text-brand-500">{{ hint }}</p>
        <p v-if="error" class="text-xs text-danger-600">{{ error }}</p>
    </div>
</template>
