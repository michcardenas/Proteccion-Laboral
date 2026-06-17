<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Iniciar sesión" />

        <div class="mb-8">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Bienvenido de nuevo</h2>
            <p class="mt-1 text-sm text-slate-500">
                Inicia sesión para acceder al panel de Protección Laboral.
            </p>
        </div>

        <div v-if="status" class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <FormField label="Correo electrónico" :error="form.errors.email" required for="email">
                <TextInput
                    id="email"
                    type="email"
                    v-model="form.email"
                    class="w-full"
                    required
                    autofocus
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

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="rounded border-slate-300 text-brand-900 shadow-sm focus:ring-brand-900"
                    />
                    Recordarme
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm font-medium text-brand-900 hover:text-brand-700"
                >
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-md bg-brand-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-900 focus:ring-offset-2 disabled:opacity-50"
            >
                <span v-if="form.processing">Ingresando…</span>
                <span v-else>Iniciar sesión</span>
            </button>
        </form>

        <div class="mt-8 border-t border-slate-200 pt-5 text-center">
            <Link :href="route('portal.login')" class="text-sm font-medium text-slate-500 transition hover:text-brand-900">
                ¿Eres cliente de Protección Laboral? Ingresa con tu NIT →
            </Link>
        </div>
    </GuestLayout>
</template>
