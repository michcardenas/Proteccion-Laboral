<script setup>
import { ref, computed, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    process: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

// Etiquetas legibles y tipo de documento sugerido por plantilla.
const TEMPLATE_LABELS = {
    draft_demanda: 'Demanda laboral',
    draft_respuesta: 'Contestación / respuesta',
    draft_dictamen: 'Dictamen jurídico',
    draft_comunicacion_cliente: 'Comunicación al cliente',
};
const TIPO_BY_TEMPLATE = {
    draft_demanda: 'escrito',
    draft_respuesta: 'escrito',
    draft_dictamen: 'concepto',
    draft_comunicacion_cliente: 'comunicacion',
};
const DOC_TYPES = ['contrato', 'concepto', 'informe', 'escrito', 'comunicacion', 'soporte', 'otro'];

const availableTemplates = computed(() =>
    props.templates.length ? props.templates : Object.keys(TEMPLATE_LABELS)
);
const labelFor = (t) => TEMPLATE_LABELS[t] ?? t;

const selectedTemplate = ref(availableTemplates.value[0]);
const contexto = ref('');

const loading = ref(false);
const error = ref(null);

// Resultado editable
const borrador = ref('');
const generationId = ref(null);

// Sub-formulario Document
const docNombre = ref('');
const docTipo = ref('escrito');
const docVisibleCliente = ref(false);

// Sub-formulario Comment
const commentVisibleCliente = ref(false);

const saving = ref(false);
const successMsg = ref(null);

const hasResult = computed(() => borrador.value.trim().length > 0);

watch(selectedTemplate, (t) => {
    docTipo.value = TIPO_BY_TEMPLATE[t] ?? 'escrito';
});

function resetAll() {
    contexto.value = '';
    borrador.value = '';
    generationId.value = null;
    error.value = null;
    successMsg.value = null;
    docNombre.value = '';
    docVisibleCliente.value = false;
    commentVisibleCliente.value = false;
    docTipo.value = TIPO_BY_TEMPLATE[selectedTemplate.value] ?? 'escrito';
}

function close() {
    emit('close');
}

watch(() => props.show, (open) => {
    if (open) resetAll();
});

function describeError(e) {
    if (e.response) {
        const data = e.response.data ?? {};
        if (data.error) return `${data.error}${data.detail ? ` — ${data.detail}` : ''}`;
        if (data.message) return data.message;
        return `HTTP ${e.response.status}`;
    }
    return `Error de red: ${e.message}`;
}

async function generar() {
    error.value = null;
    successMsg.value = null;
    loading.value = true;
    try {
        const url = route('admin.processes.ai.generate', { process: props.process.id });
        const { data } = await window.axios.post(url, {
            template: selectedTemplate.value,
            placeholders: { contexto_adicional: contexto.value },
        });
        borrador.value = data.borrador ?? '';
        generationId.value = data.id ?? null;
        if (!docNombre.value) {
            docNombre.value = `${labelFor(selectedTemplate.value)} — ${props.process.codigo}`;
        }
    } catch (e) {
        error.value = describeError(e);
    } finally {
        loading.value = false;
    }
}

async function guardarComoDocumento() {
    error.value = null;
    successMsg.value = null;
    saving.value = true;
    try {
        const url = route('admin.processes.ai.document', { process: props.process.id });
        await window.axios.post(url, {
            contenido: borrador.value,
            nombre: docNombre.value || null,
            tipo: docTipo.value,
            visible_cliente: docVisibleCliente.value,
        });
        successMsg.value = 'Borrador guardado como documento.';
        emit('saved', { kind: 'document' });
    } catch (e) {
        error.value = describeError(e);
    } finally {
        saving.value = false;
    }
}

async function guardarComoComentario() {
    error.value = null;
    successMsg.value = null;
    saving.value = true;
    try {
        const url = route('admin.processes.ai.comment', { process: props.process.id });
        await window.axios.post(url, {
            body: borrador.value,
            visible_cliente: commentVisibleCliente.value,
        });
        successMsg.value = 'Borrador guardado como comentario.';
        emit('saved', { kind: 'comment' });
    } catch (e) {
        error.value = describeError(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="close">
        <div class="bg-white">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-brand-200 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-brand-900">Generar borrador con IA</h3>
                    <p class="text-xs text-brand-500">Proceso {{ process.codigo }}</p>
                </div>
                <button @click="close" class="text-brand-400 transition hover:text-brand-600">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="max-h-[70vh] space-y-5 overflow-y-auto px-6 py-5">
                <!-- Controles de generación -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="ai-template" value="Tipo de borrador" />
                        <select
                            id="ai-template"
                            v-model="selectedTemplate"
                            class="mt-1 block w-full rounded-md border-brand-300 shadow-sm focus:border-accent-500 focus:ring-accent-500"
                        >
                            <option v-for="t in availableTemplates" :key="t" :value="t">{{ labelFor(t) }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <InputLabel for="ai-contexto" value="Contexto adicional" />
                    <textarea
                        id="ai-contexto"
                        v-model="contexto"
                        rows="4"
                        placeholder="Hechos, postura, datos clave del caso que la IA debe tener en cuenta…"
                        class="mt-1 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500"
                    ></textarea>
                </div>

                <div class="flex items-center gap-3">
                    <PrimaryButton :disabled="loading" @click="generar">
                        {{ loading ? 'Generando…' : (hasResult ? 'Regenerar' : 'Generar') }}
                    </PrimaryButton>
                    <span v-if="loading" class="text-sm text-brand-500">Llamando a Claude (5–30s)…</span>
                </div>

                <!-- Error / éxito -->
                <div v-if="error" class="rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 whitespace-pre-wrap">
                    {{ error }}
                </div>
                <div v-if="successMsg" class="rounded-md border border-success-200 bg-success-50 p-3 text-sm text-success-700">
                    {{ successMsg }}
                </div>

                <!-- Preview editable + acciones de guardado -->
                <div v-if="hasResult" class="space-y-5 border-t border-brand-100 pt-5">
                    <div>
                        <InputLabel for="ai-borrador" value="Borrador (editable)" />
                        <textarea
                            id="ai-borrador"
                            v-model="borrador"
                            rows="12"
                            class="mt-1 block w-full rounded-md border-brand-300 font-mono text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500"
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- Guardar como Document -->
                        <div class="rounded-lg border border-brand-200 p-4">
                            <h4 class="text-sm font-semibold text-brand-800">Guardar como Documento</h4>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <InputLabel for="doc-nombre" value="Nombre" />
                                    <input
                                        id="doc-nombre"
                                        v-model="docNombre"
                                        type="text"
                                        maxlength="200"
                                        class="mt-1 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500"
                                    />
                                </div>
                                <div>
                                    <InputLabel for="doc-tipo" value="Tipo" />
                                    <select
                                        id="doc-tipo"
                                        v-model="docTipo"
                                        class="mt-1 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500"
                                    >
                                        <option v-for="t in DOC_TYPES" :key="t" :value="t">{{ t }}</option>
                                    </select>
                                </div>
                                <label class="flex items-center gap-2 text-sm text-brand-600">
                                    <Checkbox v-model:checked="docVisibleCliente" />
                                    Visible para el cliente
                                </label>
                                <PrimaryButton class="w-full justify-center" :disabled="saving" @click="guardarComoDocumento">
                                    Guardar como Document
                                </PrimaryButton>
                            </div>
                        </div>

                        <!-- Guardar como Comment -->
                        <div class="rounded-lg border border-brand-200 p-4">
                            <h4 class="text-sm font-semibold text-brand-800">Guardar como Comentario</h4>
                            <div class="mt-3 space-y-3">
                                <p class="text-xs text-brand-500">
                                    Se guarda el texto del borrador como comentario interno del proceso.
                                </p>
                                <label class="flex items-center gap-2 text-sm text-brand-600">
                                    <Checkbox v-model:checked="commentVisibleCliente" />
                                    Visible para el cliente
                                </label>
                                <SecondaryButton class="w-full justify-center" :disabled="saving" @click="guardarComoComentario">
                                    Guardar como Comment
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex justify-end border-t border-brand-200 px-6 py-3">
                <SecondaryButton @click="close">Cerrar</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
