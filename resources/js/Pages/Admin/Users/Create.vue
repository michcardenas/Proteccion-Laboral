<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserForm from './Form.vue';

defineProps({ roles: Array });

const form = useForm({
    name: '',
    email: '',
    phone: '',
    role: '',
    password: '',
    password_confirmation: '',
    is_active: true,
});

const submit = () => form.post(route('admin.users.store'));
</script>

<template>
    <Head title="Nuevo usuario" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.users.index')" class="text-slate-400 transition hover:text-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </Link>
                <div>
                    <h1 class="text-lg font-semibold text-slate-900 sm:text-xl">Nuevo usuario</h1>
                    <p class="text-xs text-slate-500">Asigna un rol y datos de acceso al nuevo miembro del equipo.</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <UserForm
                    :form="form"
                    :roles="roles"
                    :is-edit="false"
                    submit-label="Crear usuario"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
