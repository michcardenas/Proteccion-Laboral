<script setup>
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    form: { type: Object, required: true },
    estados: { type: Array, required: true },
    modalidadesPago: { type: Array, required: true },
    clients: { type: Array, required: true },
    serviceTypes: { type: Array, required: true },
    isEdit: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Guardar' },
    cancelHref: { type: String, default: null },
});

const emit = defineEmits(['submit']);
</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold uppercase tracking-wider text-brand-500">Identificación</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormField label="Cliente" :error="form.errors.client_id" required>
                    <select
                        v-model="form.client_id"
                        class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="">— Selecciona empresa —</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">
                            {{ c.razon_social }}<template v-if="c.nit"> · NIT {{ c.nit }}</template>
                        </option>
                    </select>
                </FormField>

                <FormField label="Servicio" :error="form.errors.service_type_id" required>
                    <select
                        v-model="form.service_type_id"
                        class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option value="">— Selecciona servicio —</option>
                        <option v-for="s in serviceTypes" :key="s.id" :value="s.id">
                            {{ s.nombre }} ({{ s.modalidad }})
                        </option>
                    </select>
                </FormField>

                <FormField label="Código" :error="form.errors.codigo" :hint="isEdit ? null : 'Si lo dejas en blanco se autogenerará'">
                    <TextInput v-model="form.codigo" type="text" class="w-full" />
                </FormField>

                <FormField label="Estado" :error="form.errors.estado" required>
                    <select
                        v-model="form.estado"
                        class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                    </select>
                </FormField>
            </div>
        </div>

        <div class="border-t border-brand-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-brand-500">Vigencia y valor</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <FormField label="Fecha de inicio" :error="form.errors.fecha_inicio" required>
                    <TextInput v-model="form.fecha_inicio" type="date" class="w-full" />
                </FormField>
                <FormField label="Fecha de fin" :error="form.errors.fecha_fin" hint="Vacío = indefinido">
                    <TextInput v-model="form.fecha_fin" type="date" class="w-full" />
                </FormField>
                <FormField label="Valor (COP)" :error="form.errors.valor">
                    <TextInput v-model="form.valor" type="number" step="0.01" min="0" class="w-full" />
                </FormField>
                <FormField label="Modalidad de pago" :error="form.errors.modalidad_pago" required>
                    <select
                        v-model="form.modalidad_pago"
                        class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    >
                        <option v-for="m in modalidadesPago" :key="m" :value="m">{{ m }}</option>
                    </select>
                </FormField>
            </div>
        </div>

        <div class="border-t border-brand-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-brand-500">Notas internas</h3>
            <div class="mt-3">
                <FormField label="Observaciones" :error="form.errors.notas">
                    <textarea
                        v-model="form.notas"
                        rows="4"
                        class="w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    />
                </FormField>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-100 pt-6">
            <Link
                :href="cancelHref || route('admin.contracts.index')"
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
