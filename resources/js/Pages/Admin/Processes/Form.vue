<script setup>
import { computed } from 'vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    form: { type: Object, required: true },
    estados: { type: Array, required: true },
    clients: { type: Array, required: true },
    serviceTypes: { type: Array, required: true },
    contracts: { type: Array, default: () => [] },
    staff: { type: Array, required: true },
    isEdit: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Guardar' },
    cancelHref: { type: String, default: null },
});

const emit = defineEmits(['submit']);

const filteredContracts = computed(() => {
    if (!props.form.client_id) return [];
    return props.contracts.filter((c) => c.client_id === Number(props.form.client_id));
});

const selectedService = computed(() =>
    props.serviceTypes.find((s) => s.id === Number(props.form.service_type_id))
);

const stagesPreview = computed(() => selectedService.value?.stage_templates ?? selectedService.value?.stageTemplates ?? []);
</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Identificación</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormField label="Cliente" :error="form.errors.client_id" required>
                    <select
                        v-model="form.client_id"
                        :disabled="isEdit"
                        class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900 disabled:bg-slate-50"
                    >
                        <option value="">— Selecciona empresa —</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">
                            {{ c.razon_social }}<template v-if="c.nit"> · NIT {{ c.nit }}</template>
                        </option>
                    </select>
                </FormField>

                <FormField label="Servicio" :error="form.errors.service_type_id" required :hint="isEdit ? 'No editable después de creado' : 'Define las etapas y checklist iniciales'">
                    <select
                        v-model="form.service_type_id"
                        :disabled="isEdit"
                        class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900 disabled:bg-slate-50"
                    >
                        <option value="">— Selecciona servicio —</option>
                        <option v-for="s in serviceTypes" :key="s.id" :value="s.id">
                            {{ s.nombre }} ({{ s.modalidad }})
                        </option>
                    </select>
                </FormField>

                <FormField label="Contrato relacionado" :error="form.errors.contract_id" hint="Opcional. Si lo dejas vacío, queda como caso suelto.">
                    <select
                        v-model="form.contract_id"
                        :disabled="!form.client_id"
                        class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900 disabled:bg-slate-50"
                    >
                        <option value="">— Sin contrato —</option>
                        <option v-for="c in filteredContracts" :key="c.id" :value="c.id">{{ c.codigo }}</option>
                    </select>
                </FormField>

                <FormField label="Código" :error="form.errors.codigo" :hint="isEdit ? null : 'Si lo dejas en blanco se autogenerará'">
                    <TextInput v-model="form.codigo" type="text" class="w-full" />
                </FormField>

                <FormField label="Título del proceso" :error="form.errors.titulo" required class="md:col-span-2">
                    <TextInput v-model="form.titulo" type="text" class="w-full" placeholder="Ej. Demanda laboral por terminación injustificada" />
                </FormField>

                <FormField label="Descripción / contexto" :error="form.errors.descripcion" class="md:col-span-2">
                    <textarea
                        v-model="form.descripcion"
                        rows="3"
                        class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900"
                    />
                </FormField>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Estado y fechas</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                <FormField label="Estado" :error="form.errors.estado" required>
                    <select v-model="form.estado" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                    </select>
                </FormField>

                <FormField label="Fecha de apertura" :error="form.errors.fecha_apertura" required>
                    <TextInput v-model="form.fecha_apertura" type="date" class="w-full" />
                </FormField>

                <FormField v-if="isEdit" label="Fecha de cierre" :error="form.errors.fecha_cierre" hint="Solo si el proceso está cerrado">
                    <TextInput v-model="form.fecha_cierre" type="date" class="w-full" />
                </FormField>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Equipo asignado</h3>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                <FormField label="Abogado líder" :error="form.errors.abogado_lider_id" hint="Responsable principal del caso">
                    <select v-model="form.abogado_lider_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">— Sin asignar —</option>
                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }} ({{ u.role }})</option>
                    </select>
                </FormField>

                <FormField label="Apoderado" :error="form.errors.apoderado_id" hint="Para procesos judiciales">
                    <select v-model="form.apoderado_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">— Sin asignar —</option>
                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }} ({{ u.role }})</option>
                    </select>
                </FormField>

                <FormField label="Coordinador" :error="form.errors.coordinador_id" hint="Supervisor operativo">
                    <select v-model="form.coordinador_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-900 focus:ring-brand-900">
                        <option value="">— Sin asignar —</option>
                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }} ({{ u.role }})</option>
                    </select>
                </FormField>
            </div>
        </div>

        <div v-if="!isEdit && stagesPreview.length" class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-5">
            <h3 class="text-sm font-semibold text-indigo-900">Etapas que se crearán automáticamente</h3>
            <p class="mt-1 text-xs text-indigo-800">Al crear el proceso se clonarán estas etapas desde la plantilla del servicio.</p>
            <ol class="mt-3 space-y-1 text-sm text-indigo-900">
                <li v-for="(s, idx) in stagesPreview" :key="s.id" class="flex items-baseline gap-2">
                    <span class="text-xs font-semibold text-indigo-700">{{ idx + 1 }}.</span>
                    {{ s.nombre }}
                </li>
            </ol>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-6">
            <Link
                :href="cancelHref || route('admin.processes.index')"
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
