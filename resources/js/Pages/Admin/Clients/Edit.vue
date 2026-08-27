<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ClientForm from './Form.vue';

const props = defineProps({
    client: Object,
    estados: Array,
});

const form = useForm({
    razon_social: props.client.razon_social ?? '',
    nit: props.client.nit ?? '',
    dv: props.client.dv ?? '',
    ciudad: props.client.ciudad ?? '',
    sector: props.client.sector ?? '',
    contacto_principal: props.client.contacto_principal ?? '',
    email: props.client.email ?? '',
    telefono: props.client.telefono ?? '',
    fecha_alta: props.client.fecha_alta ?? '',
    estado: props.client.estado ?? 'activo',
    notas: props.client.notas ?? '',
});

const submit = () => form.put(route('admin.clients.update', props.client.id));
</script>

<template>
    <Head :title="`Editar — ${client.razon_social}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.clients.show', client.id)" class="text-brand-400 transition hover:text-brand-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <PageHeader titulo="Editar cliente" :resumen="client.razon_social" />
            </div>
        </template>

        <div class="mx-auto max-w-4xl">
            <div class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm sm:p-8">
                <ClientForm
                    :form="form"
                    :estados="estados"
                    :is-edit="true"
                    submit-label="Guardar cambios"
                    :cancel-href="route('admin.clients.show', client.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
