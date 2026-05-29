<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                IA · Playground de generación
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">

                    <p class="text-sm text-gray-600 mb-6">
                        Página de pruebas para generar borradores con la API de Claude.
                        Cada generación queda registrada en <code class="bg-gray-100 px-1 rounded">ai_generations</code>
                        con tokens, costo USD, latencia y hash del request.
                    </p>

                    <!-- Form -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <InputLabel for="process" value="Proceso (contexto)" />
                            <select
                                id="process"
                                v-model="selectedProcessId"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="p in processes" :key="p.id" :value="p.id">
                                    {{ p.codigo }} — {{ p.titulo }} ({{ p.client_name }})
                                </option>
                                <option v-if="processes.length === 0" disabled>
                                    No tienes procesos visibles
                                </option>
                            </select>
                            <p v-if="selectedProcess" class="text-xs text-gray-500 mt-1">
                                Cliente: {{ selectedProcess.client_name }} · Servicio: {{ selectedProcess.service_type }}
                            </p>
                        </div>

                        <div>
                            <InputLabel for="template" value="Plantilla de prompt" />
                            <select
                                id="template"
                                v-model="selectedTemplate"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="t in templates" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">
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
                            class="mt-1 block w-full font-mono text-xs border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        ></textarea>
                        <p class="text-xs text-gray-500 mt-1">
                            Estos valores se inyectan en los placeholders <code>{{ placeholderTag }}</code> del prompt.
                            <code>process_code</code>, <code>client_name</code> y <code>service_type</code> se autocompletan desde el proceso.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <PrimaryButton :disabled="loading || !selectedProcessId" @click="generar">
                            {{ loading ? 'Generando…' : 'Generar borrador' }}
                        </PrimaryButton>
                        <span v-if="loading" class="text-sm text-gray-500">
                            Llamando a Claude (puede tardar 5-30s)…
                        </span>
                    </div>

                    <!-- Error -->
                    <div v-if="error" class="mt-6 bg-red-50 border border-red-200 rounded-md p-4">
                        <h3 class="text-sm font-semibold text-red-800 mb-1">Error</h3>
                        <p class="text-sm text-red-700 whitespace-pre-wrap">{{ error }}</p>
                    </div>

                    <!-- Resultado -->
                    <div v-if="result" class="mt-6 space-y-4">
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-center">
                            <div class="bg-indigo-50 border border-indigo-100 rounded-md p-3">
                                <div class="text-xs text-indigo-700">Modelo</div>
                                <div class="text-sm font-semibold text-indigo-900">{{ result.modelo }}</div>
                            </div>
                            <div class="bg-green-50 border border-green-100 rounded-md p-3">
                                <div class="text-xs text-green-700">Tokens IN</div>
                                <div class="text-lg font-bold text-green-900">{{ result.tokens.input_tokens }}</div>
                            </div>
                            <div class="bg-blue-50 border border-blue-100 rounded-md p-3">
                                <div class="text-xs text-blue-700">Tokens OUT</div>
                                <div class="text-lg font-bold text-blue-900">{{ result.tokens.output_tokens }}</div>
                            </div>
                            <div class="bg-amber-50 border border-amber-100 rounded-md p-3">
                                <div class="text-xs text-amber-700">Costo</div>
                                <div class="text-lg font-bold text-amber-900">${{ result.costo_usd.toFixed(6) }}</div>
                            </div>
                            <div class="bg-purple-50 border border-purple-100 rounded-md p-3">
                                <div class="text-xs text-purple-700">Latencia</div>
                                <div class="text-lg font-bold text-purple-900">{{ result.latencia_ms }}ms</div>
                            </div>
                        </div>

                        <div class="border border-gray-200 rounded-md">
                            <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200 bg-gray-50">
                                <span class="text-sm font-semibold text-gray-700">
                                    Borrador (AiGeneration #{{ result.id }})
                                </span>
                                <SecondaryButton @click="copiarBorrador">Copiar</SecondaryButton>
                            </div>
                            <pre class="p-4 text-sm text-gray-800 whitespace-pre-wrap break-words max-h-[600px] overflow-y-auto">{{ result.borrador }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
