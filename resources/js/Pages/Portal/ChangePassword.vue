<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';

defineProps({
    razon_social: { type: String, default: '' },
    obligatorio: { type: Boolean, default: false },
});

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('portal.password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const salir = () => router.post(route('portal.logout'));
</script>

<template>
    <GuestLayout>
        <Head title="Elige tu contraseña" />

        <div class="mb-8">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-accent-700 ring-1 ring-inset ring-accent-100">
                Portal del cliente
            </span>
            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-brand-900">Elige tu contraseña</h2>
            <p class="mt-1 text-sm text-brand-500">
                <template v-if="obligatorio">
                    Entraste con una contraseña provisional. Antes de ver tus procesos, elige una propia para
                    <strong>{{ razon_social }}</strong>.
                </template>
                <template v-else>Cambia la contraseña de <strong>{{ razon_social }}</strong>.</template>
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <FormField label="Nueva contraseña" :error="form.errors.password" required for="password">
                <TextInput
                    id="password"
                    type="password"
                    v-model="form.password"
                    class="w-full"
                    required
                    autofocus
                    autocomplete="new-password"
                />
                <p class="mt-1 text-xs text-brand-400">Al menos 8 caracteres, con letras y números.</p>
            </FormField>

            <FormField label="Repite la contraseña" :error="form.errors.password_confirmation" required for="password_confirmation">
                <TextInput
                    id="password_confirmation"
                    type="password"
                    v-model="form.password_confirmation"
                    class="w-full"
                    required
                    autocomplete="new-password"
                />
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-md bg-brand-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-900 focus:ring-offset-2 disabled:opacity-50"
            >
                <span v-if="form.processing">Guardando…</span>
                <span v-else>Guardar y entrar</span>
            </button>
        </form>

        <div class="mt-8 border-t border-brand-200 pt-5 text-center">
            <button type="button" class="text-sm font-medium text-brand-500 transition hover:text-brand-900" @click="salir">
                Salir
            </button>
        </div>
    </GuestLayout>
</template>
