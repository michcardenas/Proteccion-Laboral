<script setup>
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    form: { type: Object, required: true },
    roles: { type: Array, required: true },
    isEdit: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Guardar' },
});

const emit = defineEmits(['submit']);
</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold uppercase tracking-wider text-brand-500">Información básica</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormField label="Nombre completo" :error="form.errors.name" required for="name">
                    <TextInput
                        id="name"
                        v-model="form.name"
                        type="text"
                        class="w-full"
                        autocomplete="name"
                    />
                </FormField>

                <FormField label="Email" :error="form.errors.email" required for="email">
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="w-full"
                        autocomplete="email"
                    />
                </FormField>

                <FormField label="Teléfono" :error="form.errors.phone" for="phone">
                    <TextInput
                        id="phone"
                        v-model="form.phone"
                        type="text"
                        class="w-full"
                    />
                </FormField>

                <FormField label="Rol" :error="form.errors.role" required for="role">
                    <select
                        id="role"
                        v-model="form.role"
                        class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="">— Selecciona un rol —</option>
                        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                    </select>
                </FormField>
            </div>
        </div>

        <div class="border-t border-brand-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-brand-500">Acceso</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormField
                    :label="isEdit ? 'Nueva contraseña (opcional)' : 'Contraseña'"
                    :error="form.errors.password"
                    :required="!isEdit"
                    :hint="isEdit ? 'Déjalo en blanco para no cambiarla' : 'Mínimo 8 caracteres con letras y números'"
                    for="password"
                >
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="w-full"
                        autocomplete="new-password"
                    />
                </FormField>

                <FormField
                    label="Confirmar contraseña"
                    :error="form.errors.password_confirmation"
                    for="password_confirmation"
                >
                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="w-full"
                        autocomplete="new-password"
                    />
                </FormField>
            </div>

            <label class="mt-4 inline-flex items-center gap-2">
                <input
                    type="checkbox"
                    v-model="form.is_active"
                    class="rounded border-brand-300 text-brand-900 shadow-sm focus:ring-brand-900"
                />
                <span class="text-sm text-brand-700">Usuario activo</span>
            </label>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-100 pt-6">
            <Link
                :href="route('admin.users.index')"
                class="rounded-md border border-brand-200 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
            >
                Cancelar
            </Link>
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex items-center justify-center rounded-md bg-brand-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-50"
            >
                {{ submitLabel }}
            </button>
        </div>
    </form>
</template>
