<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InfoNote from '@/Components/InfoNote.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    processes: { type: Array, required: true },
    templates: { type: Array, required: true },
});

const selectedProcessId = ref(props.processes[0]?.id ?? '');
const selectedTemplate = ref(props.templates[0] ?? 'draft_demanda');
const placeholderTag = '{{...}}'; // se renderiza como literal en el texto de ayuda
const placeholdersJson = ref(JSON.stringify({
    facts: 'Trabajador con contrato a término indefinido despedido sin justa causa el 15 de marzo de 2026.',
    requested_claims: 'Indemnización por despido injustificado, cesantías y prima de servicios.',
    defendant: 'Empresa Demo S.A.S.',
    evidence_summary: 'Carta de despido, contrato laboral, comprobantes de pago.',
}, null, 2));

const loading = ref(false);
const result = ref(null);
const error = ref(null);

const selectedProcess = computed(() =>
    props.processes.find((p) => p.id === Number(selectedProcessId.value))
);

async function generar() {
    error.value = null;
    result.value = null;
    loading.value = true;

    let placeholders = {};
    try {
        placeholders = placeholdersJson.value.trim() ? JSON.parse(placeholdersJson.value) : {};
    } catch (e) {
        error.value = `JSON de placeholders inválido: ${e.message}`;
        loading.value = false;
        return;
    }

    try {
        const url = route('admin.processes.ai.generate', { process: selectedProcessId.value });
        const { data } = await window.axios.post(url, {
            template: selectedTemplate.value,
            placeholders,
        });
        result.value = data;
    } catch (e) {
        if (e.response) {
            const data = e.response.data ?? {};
            error.value = data.error
                ? `${data.error}${data.detail ? ` — ${data.detail}` : ''}`
                : data.message
                ? `${data.message}`
                : `HTTP ${e.response.status}: ${JSON.stringify(data)}`;
        } else {
            error.value = `Error de red: ${e.message}`;
        }
    } finally {
        loading.value = false;
    }
}

function copiarBorrador() {
    if (result.value?.borrador) {
        navigator.clipboard.writeText(result.value.borrador);
    }
}
</script>

<template>
    <Head title="IA · Playground" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader titulo="Laboratorio de IA" help-key="playground" />
        </template>

        <div>
            <div class="mx-auto max-w-5xl">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">

                    <InfoNote tono="info" titulo="Esto es un espacio de pruebas" class="mb-6">
                        Lo que generes aquí <strong>no se envía por correo</strong> ni se guarda en ningún expediente.
                        Para generar y enviar de verdad: entra al proceso → pestaña <strong>Correos</strong> →
                        <strong>Responder</strong> → <strong>Redactar con IA</strong>.
                        Cada generación sí queda registrada en Uso de IA con su costo.
                    </InfoNote>

                    <!-- Form -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <InputLabel for="process" value="Proceso (contexto)" />
                            <select
                                id="process"
                                v-model="selectedProcessId"
                                class="mt-1 block w-full border-brand-300 rounded-md shadow-sm focus:border-accent-500 focus:ring-accent-500"
                            >
                                <option v-for="p in processes" :key="p.id" :value="p.id">
                                    {{ p.codigo }} — {{ p.titulo }} ({{ p.client_name }})
                                </option>
                                <option v-if="processes.length === 0" disabled>
                                    No tienes procesos visibles
                                </option>
                            </select>
                            <p v-if="selectedProcess" class="text-xs text-brand-500 mt-1">
                                Cliente: {{ selectedProcess.client_name }} · Servicio: {{ selectedProcess.service_type }}
                            </p>
                        </div>

                        <div>
                            <InputLabel for="template" value="Plantilla de prompt" />
                            <select
                                id="template"
                                v-model="selectedTemplate"
                                class="mt-1 block w-full border-brand-300 rounded-md shadow-sm focus:border-accent-500 focus:ring-accent-500"
                            >
                                <option v-for="t in templates" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <p class="text-xs text-brand-500 mt-1">
                                Archivo: <code>resources/prompts/{{ selectedTemplate }}.md</code>
                            </p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <InputLabel for="placeholders" value="Placeholders (JSON)" />
                        <textarea
                            id="placeholders"
                            v-model="placeholdersJson"
                            rows="8"
                            class="mt-1 block w-full font-mono text-xs border-brand-300 rounded-md shadow-sm focus:border-accent-500 focus:ring-accent-500"
                        ></textarea>
                        <p class="text-xs text-brand-500 mt-1">
                            Estos valores se inyectan en los placeholders <code>{{ placeholderTag }}</code> del prompt.
                            <code>process_code</code>, <code>client_name</code> y <code>service_type</code> se autocompletan desde el proceso.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <PrimaryButton :disabled="loading || !selectedProcessId" @click="generar">
                            {{ loading ? 'Generando…' : 'Generar borrador' }}
                        </PrimaryButton>
                        <span v-if="loading" class="text-sm text-brand-500">
                            Llamando a Claude (puede tardar 5-30s)…
                        </span>
                    </div>

                    <!-- Error -->
                    <div v-if="error" class="mt-6 bg-danger-50 border border-danger-200 rounded-md p-4">
                        <h3 class="text-sm font-semibold text-danger-800 mb-1">Error</h3>
                        <p class="text-sm text-danger-700 whitespace-pre-wrap">{{ error }}</p>
                    </div>

                    <!-- Resultado -->
                    <div v-if="result" class="mt-6 space-y-4">
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-center">
                            <div class="bg-accent-50 border border-accent-100 rounded-md p-3">
                                <div class="text-xs text-accent-700">Modelo</div>
                                <div class="text-sm font-semibold text-accent-900">{{ result.modelo }}</div>
                            </div>
                            <div class="bg-success-50 border border-success-100 rounded-md p-3">
                                <div class="text-xs text-success-700">Tokens IN</div>
                                <div class="text-lg font-bold text-success-900">{{ result.tokens.input_tokens }}</div>
                            </div>
                            <div class="bg-info-50 border border-info-100 rounded-md p-3">
                                <div class="text-xs text-info-700">Tokens OUT</div>
                                <div class="text-lg font-bold text-info-900">{{ result.tokens.output_tokens }}</div>
                            </div>
                            <div class="bg-warning-50 border border-warning-100 rounded-md p-3">
                                <div class="text-xs text-warning-700">Costo</div>
                                <div class="text-lg font-bold text-warning-900">${{ result.costo_usd.toFixed(6) }}</div>
                            </div>
                            <div class="bg-accent-50 border border-accent-100 rounded-md p-3">
                                <div class="text-xs text-accent-700">Latencia</div>
                                <div class="text-lg font-bold text-accent-900">{{ result.latencia_ms }}ms</div>
                            </div>
                        </div>

                        <div class="border border-brand-200 rounded-md">
                            <div class="flex items-center justify-between px-4 py-2 border-b border-brand-200 bg-brand-50">
                                <span class="text-sm font-semibold text-brand-700">
                                    Borrador (AiGeneration #{{ result.id }})
                                </span>
                                <SecondaryButton @click="copiarBorrador">Copiar</SecondaryButton>
                            </div>
                            <pre class="p-4 text-sm text-brand-800 whitespace-pre-wrap break-words max-h-[600px] overflow-y-auto">{{ result.borrador }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
