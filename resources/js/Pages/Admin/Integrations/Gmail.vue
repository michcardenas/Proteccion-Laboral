<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    connection: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const connectUrl = route('admin.integrations.gmail.connect');

const formatDateTime = (iso) =>
    iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const showDisconnect = ref(false);

// Scopes que la app pide pero el token vigente no trae. Google no amplía un token ya
// emitido: la única forma de otorgarlos es volver a pasar por el consentimiento.
const missingScopes = computed(() =>
    (props.connection?.missing_scopes ?? []).map((s) => s.replace('https://www.googleapis.com/auth/', '')),
);
const disconnecting = ref(false);

function disconnect() {
    disconnecting.value = true;
    router.post(route('admin.integrations.gmail.disconnect'), {}, {
        preserveScroll: true,
        onFinish: () => {
            disconnecting.value = false;
            showDisconnect.value = false;
        },
    });
}
</script>

<template>
    <Head title="Integraciones · Gmail" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader titulo="Conexión con Gmail" help-key="gmail" />
        </template>

        <div>
            <div class="mx-auto max-w-3xl space-y-6">

                <!-- Flash -->
                <div v-if="flash.success" class="rounded-md border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-800">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="rounded-md border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-800">
                    {{ flash.error }}
                </div>

                <!-- Tarjeta de estado -->
                <div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm">
                    <div class="flex items-center gap-4 border-b border-brand-100 px-6 py-5">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-danger-50">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6 text-danger-500">
                                <path d="M1.5 8.67v8.58a3 3 0 003 3h15a3 3 0 003-3V8.67l-8.928 5.493a3 3 0 01-3.144 0L1.5 8.67z" />
                                <path d="M22.5 6.908V6.75a3 3 0 00-3-3h-15a3 3 0 00-3 3v.158l9.714 5.978a1.5 1.5 0 001.572 0L22.5 6.908z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-900">Cuenta de Gmail</h3>
                            <p class="text-sm">
                                <span
                                    v-if="connection.connected"
                                    class="inline-flex items-center gap-1.5 font-medium text-success-700"
                                >
                                    <span class="h-2 w-2 rounded-full bg-success-500"></span> Conectado
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 font-medium text-brand-500"
                                >
                                    <span class="h-2 w-2 rounded-full bg-brand-400"></span> Desconectado
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="px-6 py-5">
                        <!-- Conectado -->
                        <dl v-if="connection.connected" class="space-y-4">
                            <div class="flex flex-wrap justify-between gap-2">
                                <dt class="text-sm text-brand-500">Cuenta</dt>
                                <dd class="text-sm font-medium text-brand-900">{{ connection.account_email }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2">
                                <dt class="text-sm text-brand-500">Conectada por</dt>
                                <dd class="text-sm text-brand-900">{{ connection.connected_by || '—' }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2">
                                <dt class="text-sm text-brand-500">Conectada el</dt>
                                <dd class="text-sm text-brand-900">{{ formatDateTime(connection.connected_at) }}</dd>
                            </div>
                            <div class="flex flex-wrap justify-between gap-2">
                                <dt class="text-sm text-brand-500">Token expira</dt>
                                <dd class="text-sm" :class="connection.is_expired ? 'font-medium text-warning-600' : 'text-brand-900'">
                                    {{ formatDateTime(connection.expires_at) }}
                                    <span v-if="connection.is_expired"> (expirado — se renovará automáticamente)</span>
                                </dd>
                            </div>
                            <div v-if="connection.scopes?.length">
                                <dt class="text-sm text-brand-500">Permisos otorgados</dt>
                                <dd class="mt-1 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="s in connection.scopes"
                                        :key="s"
                                        class="rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-600"
                                    >{{ s.replace('https://www.googleapis.com/auth/', '') }}</span>
                                </dd>
                            </div>

                            <div
                                v-if="missingScopes.length"
                                class="rounded-lg border border-warning-200 bg-warning-50 p-4"
                            >
                                <p class="text-sm font-medium text-warning-900">Faltan permisos por otorgar</p>
                                <p class="mt-1 text-sm text-warning-800">
                                    La cuenta está conectada, pero su autorización no incluye
                                    <span
                                        v-for="(s, i) in missingScopes"
                                        :key="s"
                                        class="font-mono text-xs"
                                    >{{ s }}<span v-if="i < missingScopes.length - 1">, </span></span>.
                                    Sin ese permiso la sincronización de la unidad compartida de Drive no puede leer
                                    los documentos de los clientes. Reconecta la cuenta para otorgarlo: no se pierde
                                    nada de lo ya configurado.
                                </p>
                                <a
                                    :href="connectUrl"
                                    class="mt-3 inline-flex items-center rounded-md bg-warning-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-warning-700"
                                >
                                    Reconectar y otorgar permisos
                                </a>
                            </div>

                            <div class="border-t border-brand-100 pt-5">
                                <button
                                    @click="showDisconnect = true"
                                    class="rounded-md border border-danger-200 bg-danger-50 px-4 py-2 text-sm font-medium text-danger-700 transition hover:bg-danger-100"
                                >
                                    Desconectar
                                </button>
                            </div>
                        </dl>

                        <!-- Desconectado -->
                        <div v-else class="space-y-4">
                            <p class="text-sm text-brand-600">
                                Conecta una cuenta de Gmail para habilitar la ingesta y clasificación automática de
                                correos entrantes. Se solicitarán permisos de <strong>lectura</strong> y
                                <strong>modificación</strong> (etiquetas / marcar como leído).
                            </p>
                            <a
                                :href="connectUrl"
                                class="inline-flex items-center gap-2 rounded-md bg-brand-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                                    <path d="M22.5 6.908V6.75a3 3 0 00-3-3h-15a3 3 0 00-3 3v.158l9.714 5.978a1.5 1.5 0 001.572 0L22.5 6.908z" />
                                    <path d="M1.5 8.67v8.58a3 3 0 003 3h15a3 3 0 003-3V8.67l-8.928 5.493a3 3 0 01-3.144 0L1.5 8.67z" />
                                </svg>
                                Conectar con Google
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="showDisconnect"
            title="Desconectar Gmail"
            message="¿Confirmas desconectar la cuenta de Gmail? Se eliminarán los tokens almacenados y deberás volver a autorizar para reactivar la ingesta."
            confirm-label="Sí, desconectar"
            variant="danger"
            @close="showDisconnect = false"
            @confirm="disconnect"
        />
    </AuthenticatedLayout>
</template>
