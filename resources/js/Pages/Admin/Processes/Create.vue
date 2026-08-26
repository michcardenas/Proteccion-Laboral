<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProcessForm from './Form.vue';

const props = defineProps({
    estados: Array,
    clients: Array,
    serviceTypes: Array,
    contracts: Array,
    staff: Array,
    preselectClientId: [Number, null],
    preselectContractId: [Number, null],
    suggestedCode: String,
});

const form = useForm({
    client_id: props.preselectClientId ?? '',
    service_type_id: '',
    contract_id: props.preselectContractId ?? '',
    codigo: props.suggestedCode ?? '',
    titulo: '',
    descripcion: '',
    estado: 'abierto',
    fecha_apertura: new Date().toISOString().slice(0, 10),
    abogado_lider_id: '',
    apoderado_id: '',
    coordinador_id: '',
});

const submit = () => form.post(route('admin.processes.store'));
</script>

<template>
    <Head title="Nuevo proceso" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.processes.index')" class="text-brand-400 transition hover:text-brand-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <PageHeader titulo="Nuevo proceso" resumen="Las etapas y tarjetas se crean solas según el servicio que elijas." help-key="processes" />
            </div>
        </template>

        <div class="mx-auto max-w-4xl">
            <div class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm sm:p-8">
                <ProcessForm
                    :form="form"
                    :estados="estados"
                    :clients="clients"
                    :service-types="serviceTypes"
                    :contracts="contracts"
                    :staff="staff"
                    :is-edit="false"
                    submit-label="Crear proceso"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
