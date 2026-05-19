<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ContractForm from './Form.vue';

const props = defineProps({
    contract: Object,
    estados: Array,
    modalidadesPago: Array,
    clients: Array,
    serviceTypes: Array,
});

const form = useForm({
    client_id: props.contract.client_id ?? '',
    service_type_id: props.contract.service_type_id ?? '',
    codigo: props.contract.codigo ?? '',
    fecha_inicio: props.contract.fecha_inicio ?? '',
    fecha_fin: props.contract.fecha_fin ?? '',
    valor: props.contract.valor ?? '',
    modalidad_pago: props.contract.modalidad_pago ?? 'mensual',
    estado: props.contract.estado ?? 'borrador',
    notas: props.contract.notas ?? '',
});

const submit = () => form.put(route('admin.contracts.update', props.contract.id));
</script>

<template>
    <Head :title="`Editar contrato ${contract.codigo}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.contracts.show', contract.id)" class="text-slate-400 transition hover:text-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div>
                    <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Editar contrato</h1>
                    <p class="text-xs text-slate-500">{{ contract.codigo }}</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <ContractForm
                    :form="form"
                    :estados="estados"
                    :modalidades-pago="modalidadesPago"
                    :clients="clients"
                    :service-types="serviceTypes"
                    :is-edit="true"
                    submit-label="Guardar cambios"
                    :cancel-href="route('admin.contracts.show', contract.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
