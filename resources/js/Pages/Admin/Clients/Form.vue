<script setup>
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    form: { type: Object, required: true },
    estados: { type: Array, required: true },
    isEdit: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Guardar' },
    cancelHref: { type: String, default: null },
});

const emit = defineEmits(['submit']);
</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Identificación</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                <FormField label="Razón social" :error="form.errors.razon_social" required class="md:col-span-2">
                    <TextInput v-model="form.razon_social" type="text" class="w-full" />
                </FormField>

                <FormField label="Estado" :error="form.errors.estado" required>
                    <select
                        v-model="form.estado"
                        class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                    </select>
                </FormField>

                <FormField label="NIT" :error="form.errors.nit" hint="Sin dígito de verificación">
                    <TextInput v-model="form.nit" type="text" class="w-full" />
                </FormField>

                <FormField label="Dígito de verificación" :error="form.errors.dv">
                    <TextInput v-model="form.dv" type="text" class="w-full" />
                </FormField>

                <FormField label="Fecha de alta" :error="form.errors.fecha_alta">
                    <TextInput v-model="form.fecha_alta" type="date" class="w-full" />
                </FormField>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Ubicación y sector</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormField label="Ciudad" :error="form.errors.ciudad">
                    <TextInput v-model="form.ciudad" type="text" class="w-full" />
                </FormField>

                <FormField label="Sector" :error="form.errors.sector">
                    <TextInput v-model="form.sector" type="text" class="w-full" />
                </FormField>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Contacto principal</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                <FormField label="Nombre" :error="form.errors.contacto_principal" class="md:col-span-1">
                    <TextInput v-model="form.contacto_principal" type="text" class="w-full" />
                </FormField>

                <FormField label="Email" :error="form.errors.email">
                    <TextInput v-model="form.email" type="email" class="w-full" />
                </FormField>

                <FormField label="Teléfono" :error="form.errors.telefono">
                    <TextInput v-model="form.telefono" type="text" class="w-full" />
                </FormField>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Notas internas</h3>
            <div class="mt-3">
                <FormField label="Observaciones" :error="form.errors.notas" hint="Visible solo para el equipo interno.">
                    <textarea
                        v-model="form.notas"
                        rows="4"
                        class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    />
                </FormField>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-6">
            <Link
                :href="cancelHref || route('admin.clients.index')"
                class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
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
