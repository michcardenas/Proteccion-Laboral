<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import UserForm from './Form.vue';

const props = defineProps({
    user: Object,
    roles: Array,
});

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    phone: props.user.phone ?? '',
    role: props.user.role ?? '',
    password: '',
    password_confirmation: '',
    is_active: !!props.user.is_active,
});

const submit = () => form.put(route('admin.users.update', props.user.id));
</script>

<template>
    <Head :title="`Editar — ${user.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.users.index')" class="text-brand-400 transition hover:text-brand-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <PageHeader titulo="Editar usuario" :resumen="user.name + ' · ' + user.email" />
            </div>
        </template>

        <div class="mx-auto max-w-3xl">
            <div class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm sm:p-8">
                <UserForm
                    :form="form"
                    :roles="roles"
                    :is-edit="true"
                    submit-label="Guardar cambios"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
