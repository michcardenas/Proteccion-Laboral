<script setup>
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirmar' },
    cancelLabel: { type: String, default: 'Cancelar' },
    variant: { type: String, default: 'primary' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-900">{{ title }}</h2>
            <p v-if="message" class="mt-2 text-sm text-brand-600">{{ message }}</p>
            <div class="mt-6 flex justify-end gap-2">
                <SecondaryButton @click="emit('close')">{{ cancelLabel }}</SecondaryButton>
                <DangerButton
                    v-if="variant === 'danger'"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </DangerButton>
                <PrimaryButton
                    v-else
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
