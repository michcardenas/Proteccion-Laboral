<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: { type: String },
});

const form = useForm({
    nit: '',
    password: '',
});

const submit = () => {
    form.post(route('portal.login.store'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Portal del cliente" />

        <div class="mb-8">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-accent-700 ring-1 ring-inset ring-accent-100">
                Portal del cliente
            </span>
            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-brand-900">Consulta tu caso</h2>
            <p class="mt-1 text-sm text-brand-500">
                Ingresa con el <strong>NIT</strong> de tu empresa y la contraseña que te entregó Protección Laboral.
            </p>
        </div>

        <div v-if="status" class="mb-6 rounded-md border border-success-200 bg-success-50 px-3 py-2 text-sm text-success-700">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <FormField label="NIT de la empresa" :error="form.errors.nit" required for="nit">
                <TextInput
                    id="nit"
                    type="text"
                    v-model="form.nit"
                    class="w-full"
                    required
                    autofocus
                    inputmode="numeric"
                    placeholder="Ej: 900123456"
                    autocomplete="username"
                />
            </FormField>

            <FormField label="Contraseña" :error="form.errors.password" required for="password">
                <TextInput
                    id="password"
                    type="password"
                    v-model="form.password"
                    class="w-full"
                    required
                    autocomplete="current-password"
                />
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-md bg-brand-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-900 focus:ring-offset-2 disabled:opacity-50"
            >
                <span v-if="form.processing">Ingresando…</span>
                <span v-else>Ingresar al portal</span>
            </button>
        </form>

        <div class="mt-8 border-t border-brand-200 pt-5 text-center">
            <Link :href="route('login')" class="text-sm font-medium text-brand-500 transition hover:text-brand-900">
                ¿Eres del equipo de Protección Laboral? Inicia sesión aquí →
            </Link>
        </div>
    </GuestLayout>
</template>
