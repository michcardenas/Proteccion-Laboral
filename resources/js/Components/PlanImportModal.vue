<script setup>
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InfoNote from '@/Components/InfoNote.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    process: { type: Object, required: true },
});

const emit = defineEmits(['close', 'applied']);

const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];
const TIPO_LABELS = {
    plan_trabajo: 'Plan de trabajo',
    contrato: 'Contrato',
    mixto: 'Plan + contrato',
    desconocido: 'No identificado',
};

const fileInput = ref(null);
const archivo = ref(null);
const archivoNombre = ref('');

const analizando = ref(false);
const aplicando = ref(false);
const error = ref(null);
const successMsg = ref(null);

// Resultado editable de la IA
const extraccion = ref(null); // { tipo_documento, resumen, etapas[], transversales[], tareas[], costo_usd }
const reemplazarPlan = ref(true);
const guardarPlantilla = ref(false);

const tieneResultado = computed(() => extraccion.value !== null);

function reset() {
    archivo.value = null;
    archivoNombre.value = '';
    extraccion.value = null;
    error.value = null;
    successMsg.value = null;
    reemplazarPlan.value = true;
    guardarPlantilla.value = false;
    if (fileInput.value) fileInput.value.value = '';
}

function close() {
    emit('close');
}

function onFile(e) {
    const f = e.target.files?.[0] ?? null;
    archivo.value = f;
    archivoNombre.value = f?.name ?? '';
    error.value = null;
}

function describeError(e) {
    if (e.response) {
        const data = e.response.data ?? {};
        if (data.error) return `${data.error}${data.detail ? ` — ${data.detail}` : ''}`;
        if (data.message) return data.message;
        return `HTTP ${e.response.status}`;
    }
    return `Error de red: ${e.message}`;
}

async function analizar() {
    if (!archivo.value) {
        error.value = 'Selecciona un archivo (PDF, DOCX o TXT).';
        return;
    }
    error.value = null;
    successMsg.value = null;
    analizando.value = true;
    try {
        const form = new FormData();
        form.append('archivo', archivo.value);
        const url = route('admin.processes.plan.analyze', { process: props.process.id });
        const { data } = await window.axios.post(url, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        extraccion.value = {
            tipo_documento: data.tipo_documento ?? 'desconocido',
            resumen: data.resumen ?? '',
            etapas: (data.etapas ?? []).map((e) => ({
                nombre: e.nombre ?? '',
                descripcion: e.descripcion ?? '',
                fecha_entrega: e.fecha_entrega ?? '',
                entregables: Array.isArray(e.entregables) ? [...e.entregables] : [],
            })),
            transversales: Array.isArray(data.transversales) ? [...data.transversales] : [],
            tareas: (data.tareas ?? []).map((t) => ({
                titulo: t.titulo ?? '',
                descripcion: t.descripcion ?? '',
                prioridad: PRIORIDADES.includes(t.prioridad) ? t.prioridad : 'media',
                fecha_limite: t.fecha_limite ?? '',
            })),
            costo_usd: data.costo_usd ?? null,
        };
    } catch (e) {
        error.value = describeError(e);
    } finally {
        analizando.value = false;
    }
}

// --- Edición de la estructura ---
function addEtapa() {
    extraccion.value.etapas.push({ nombre: '', descripcion: '', fecha_entrega: '', entregables: [] });
}
function removeEtapa(i) {
    extraccion.value.etapas.splice(i, 1);
}
function addEntregable(etapa) {
    etapa.entregables.push('');
}
function removeEntregable(etapa, j) {
    etapa.entregables.splice(j, 1);
}
function addTransversal() {
    extraccion.value.transversales.push('');
}
function removeTransversal(i) {
    extraccion.value.transversales.splice(i, 1);
}
function addTarea() {
    extraccion.value.tareas.push({ titulo: '', descripcion: '', prioridad: 'media', fecha_limite: '' });
}
function removeTarea(i) {
    extraccion.value.tareas.splice(i, 1);
}

function payload() {
    const e = extraccion.value;
    return {
        etapas: e.etapas.map((s) => ({
            nombre: s.nombre,
            descripcion: s.descripcion || null,
            fecha_entrega: s.fecha_entrega || null,
            entregables: s.entregables.filter((x) => x && x.trim() !== ''),
        })),
        transversales: e.transversales.filter((x) => x && x.trim() !== ''),
        tareas: e.tareas.map((t) => ({
            titulo: t.titulo,
            descripcion: t.descripcion || null,
            prioridad: t.prioridad,
            fecha_limite: t.fecha_limite || null,
        })),
        reemplazar_plan: reemplazarPlan.value,
        guardar_plantilla: guardarPlantilla.value,
    };
}

async function aplicar() {
    error.value = null;
    successMsg.value = null;
    aplicando.value = true;
    try {
        const url = route('admin.processes.plan.apply', { process: props.process.id });
        const { data } = await window.axios.post(url, payload());
        successMsg.value = `Plan aplicado: ${data.etapas_creadas} etapa(s) y ${data.tareas_creadas} tarea(s)`
            + (data.plantilla_actualizada ? ' · plantilla del servicio actualizada.' : '.');
        emit('applied', data);
    } catch (e) {
        error.value = describeError(e);
    } finally {
        aplicando.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="close">
        <div class="bg-white">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-brand-200 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-brand-900">Importar plan / contrato con IA</h3>
                    <p class="text-xs text-brand-500">Proceso {{ process.codigo }}</p>
                </div>
                <button @click="close" class="text-brand-400 transition hover:text-brand-600">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="max-h-[72vh] space-y-5 overflow-y-auto px-6 py-5">
                <InfoNote tono="info" titulo="Nada se aplica sin que lo confirmes">
                    La IA propone las etapas, entregables y tareas leyendo el documento. Podrás revisarlas y editarlas
                    una por una antes de aplicarlas al proceso.
                </InfoNote>

                <!-- Paso 1: subir documento -->
                <div class="rounded-lg border border-brand-200 bg-brand-50 p-4">
                    <InputLabel value="Documento (plan de trabajo o contrato)" />
                    <p class="mb-2 mt-0.5 text-xs text-brand-500">Formatos: PDF, DOCX o TXT (máx. 20 MB). La IA leerá el documento y propondrá las etapas, entregables y tareas.</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".pdf,.docx,.txt,.md"
                            @change="onFile"
                            class="block text-sm text-brand-600 file:mr-3 file:rounded-md file:border-0 file:bg-accent-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-accent-700 hover:file:bg-accent-100"
                        />
                        <PrimaryButton :disabled="analizando || !archivo" @click="analizar">
                            {{ analizando ? 'Analizando…' : (tieneResultado ? 'Re-analizar' : 'Analizar con IA') }}
                        </PrimaryButton>
                        <span v-if="analizando" class="text-sm text-brand-500">Interpretando con Claude (10–40s)…</span>
                    </div>
                </div>

                <!-- Error / éxito -->
                <div v-if="error" class="rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 whitespace-pre-wrap">{{ error }}</div>
                <div v-if="successMsg" class="rounded-md border border-success-200 bg-success-50 p-3 text-sm text-success-700">{{ successMsg }}</div>

                <!-- Paso 2: vista previa editable -->
                <div v-if="tieneResultado" class="space-y-6 border-t border-brand-100 pt-5">
                    <div class="flex items-start justify-between gap-4">
                        <p class="text-sm text-brand-600"><span class="font-medium text-brand-800">Resumen IA:</span> {{ extraccion.resumen || '—' }}</p>
                        <span class="shrink-0 rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-200">
                            {{ TIPO_LABELS[extraccion.tipo_documento] ?? extraccion.tipo_documento }}
                        </span>
                    </div>

                    <!-- Etapas -->
                    <section>
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-brand-800">Etapas del plan ({{ extraccion.etapas.length }})</h4>
                            <button type="button" @click="addEtapa" class="text-xs font-medium text-accent-600 hover:text-accent-800">+ Añadir etapa</button>
                        </div>
                        <div class="space-y-4">
                            <div v-for="(etapa, i) in extraccion.etapas" :key="'e'+i" class="rounded-lg border border-brand-200 p-4">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div class="sm:col-span-2">
                                        <InputLabel :for="'etapa-nombre-'+i" value="Nombre de la etapa" />
                                        <input :id="'etapa-nombre-'+i" v-model="etapa.nombre" type="text" maxlength="160"
                                            class="mt-1 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'etapa-fecha-'+i" value="Fecha de entrega" />
                                        <input :id="'etapa-fecha-'+i" v-model="etapa.fecha_entrega" type="date"
                                            class="mt-1 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="mb-1 flex items-center justify-between">
                                        <InputLabel value="Entregables" />
                                        <button type="button" @click="addEntregable(etapa)" class="text-xs font-medium text-accent-600 hover:text-accent-800">+ Añadir entregable</button>
                                    </div>
                                    <div class="space-y-2">
                                        <div v-for="(ent, j) in etapa.entregables" :key="'ent'+i+'-'+j" class="flex items-center gap-2">
                                            <input v-model="etapa.entregables[j]" type="text"
                                                class="block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                            <button type="button" @click="removeEntregable(etapa, j)" class="shrink-0 text-brand-400 hover:text-danger-600" title="Quitar">&times;</button>
                                        </div>
                                        <p v-if="!etapa.entregables.length" class="text-xs text-brand-400">Sin entregables.</p>
                                    </div>
                                </div>

                                <div class="mt-3 text-right">
                                    <button type="button" @click="removeEtapa(i)" class="text-xs font-medium text-danger-500 hover:text-danger-700">Eliminar etapa</button>
                                </div>
                            </div>
                            <p v-if="!extraccion.etapas.length" class="text-sm text-brand-400">No se detectaron etapas. Puedes añadirlas manualmente.</p>
                        </div>
                    </section>

                    <!-- Transversales -->
                    <section>
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-brand-800">Entregables transversales ({{ extraccion.transversales.length }})</h4>
                            <button type="button" @click="addTransversal" class="text-xs font-medium text-accent-600 hover:text-accent-800">+ Añadir</button>
                        </div>
                        <p class="mb-2 text-xs text-brand-500">Acompañamiento continuo durante todo el proceso (se enganchan a la primera etapa).</p>
                        <div class="space-y-2">
                            <div v-for="(t, i) in extraccion.transversales" :key="'tr'+i" class="flex items-center gap-2">
                                <input v-model="extraccion.transversales[i]" type="text"
                                    class="block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                <button type="button" @click="removeTransversal(i)" class="shrink-0 text-brand-400 hover:text-danger-600" title="Quitar">&times;</button>
                            </div>
                            <p v-if="!extraccion.transversales.length" class="text-xs text-brand-400">Ninguno.</p>
                        </div>
                    </section>

                    <!-- Tareas Kanban -->
                    <section>
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-brand-800">Tarjetas del tablero Kanban ({{ extraccion.tareas.length }})</h4>
                            <button type="button" @click="addTarea" class="text-xs font-medium text-accent-600 hover:text-accent-800">+ Añadir tarea</button>
                        </div>
                        <div class="space-y-3">
                            <div v-for="(tarea, i) in extraccion.tareas" :key="'t'+i" class="rounded-lg border border-brand-200 p-3">
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-12">
                                    <input v-model="tarea.titulo" type="text" maxlength="200" placeholder="Título de la tarea"
                                        class="sm:col-span-6 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                    <select v-model="tarea.prioridad"
                                        class="sm:col-span-3 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                        <option v-for="p in PRIORIDADES" :key="p" :value="p">{{ p }}</option>
                                    </select>
                                    <input v-model="tarea.fecha_limite" type="date"
                                        class="sm:col-span-2 block w-full rounded-md border-brand-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                    <button type="button" @click="removeTarea(i)" class="sm:col-span-1 text-brand-400 hover:text-danger-600" title="Quitar">&times;</button>
                                </div>
                            </div>
                            <p v-if="!extraccion.tareas.length" class="text-sm text-brand-400">No se detectaron tareas. Puedes añadirlas manualmente.</p>
                        </div>
                    </section>

                    <!-- Opciones -->
                    <section class="rounded-lg border border-brand-200 bg-brand-50 p-4">
                        <label class="flex items-start gap-2 text-sm text-brand-700">
                            <Checkbox v-model:checked="reemplazarPlan" class="mt-0.5" />
                            <span><span class="font-medium">Reemplazar el plan actual</span> — elimina las etapas existentes del proceso antes de crear estas. Si lo desmarcas, se añaden al final.</span>
                        </label>
                        <label class="mt-3 flex items-start gap-2 text-sm text-brand-700">
                            <Checkbox v-model:checked="guardarPlantilla" class="mt-0.5" />
                            <span><span class="font-medium">Guardar como plantilla del servicio</span> — futuros procesos de este tipo heredarán estas etapas, entregables y tareas (las fechas se guardan como días desde la apertura).</span>
                        </label>
                    </section>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between border-t border-brand-200 px-6 py-3">
                <span v-if="extraccion?.costo_usd != null" class="text-xs text-brand-400">Costo análisis: ${{ extraccion.costo_usd.toFixed(4) }} USD</span>
                <span v-else></span>
                <div class="flex gap-2">
                    <SecondaryButton @click="close">Cerrar</SecondaryButton>
                    <PrimaryButton v-if="tieneResultado" :disabled="aplicando" @click="aplicar">
                        {{ aplicando ? 'Aplicando…' : 'Aplicar al proceso' }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </Modal>
</template>
