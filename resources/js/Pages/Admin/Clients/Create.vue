<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ClientForm from './Form.vue';

defineProps({ estados: Array });

const form = useForm({
    razon_social: '',
    nit: '',
    dv: '',
    ciudad: '',
    sector: '',
    contacto_principal: '',
    email: '',
    telefono: '',
    fecha_alta: new Date().toISOString().slice(0, 10),
    estado: 'activo',
    notas: '',
});

const submit = () => form.post(route('admin.clients.store'));
</script>

<template>
    <Head title="Nuevo cliente" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.clients.index')" class="text-brand-400 transition hover:text-brand-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <PageHeader titulo="Nuevo cliente" resumen="El NIT es la clave con la que el cliente entrará a su portal." help-key="clients" />
            </div>
        </template>

        <div class="mx-auto max-w-4xl">
            <div class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm sm:p-8">
                <ClientForm
                    :form="form"
                    :estados="estados"
                    :is-edit="false"
                    submit-label="Crear cliente"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
