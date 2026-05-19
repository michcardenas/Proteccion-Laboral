<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProcessForm from './Form.vue';

const props = defineProps({
    process: Object,
    estados: Array,
    clients: Array,
    serviceTypes: Array,
    contracts: Array,
    staff: Array,
});

const form = useForm({
    client_id: props.process.client_id ?? '',
    service_type_id: props.process.service_type_id ?? '',
    contract_id: props.process.contract_id ?? '',
    codigo: props.process.codigo ?? '',
    titulo: props.process.titulo ?? '',
    descripcion: props.process.descripcion ?? '',
    estado: props.process.estado ?? 'abierto',
    fecha_apertura: props.process.fecha_apertura ?? '',
    fecha_cierre: props.process.fecha_cierre ?? '',
    abogado_lider_id: props.process.abogado_lider_id ?? '',
    apoderado_id: props.process.apoderado_id ?? '',
    coordinador_id: props.process.coordinador_id ?? '',
});

const submit = () => form.put(route('admin.processes.update', props.process.id));
</script>

<template>
    <Head :title="`Editar proceso ${process.codigo}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.processes.show', process.id)" class="text-slate-400 transition hover:text-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div>
                    <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Editar proceso</h1>
                    <p class="text-xs text-slate-500">{{ process.codigo }}</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <ProcessForm
                    :form="form"
                    :estados="estados"
                    :clients="clients"
                    :service-types="serviceTypes"
                    :contracts="contracts"
                    :staff="staff"
                    :is-edit="true"
                    submit-label="Guardar cambios"
                    :cancel-href="route('admin.processes.show', process.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
