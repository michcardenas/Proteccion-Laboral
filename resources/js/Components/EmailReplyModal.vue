<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    process: { type: Object, required: true },
    email: { type: Object, default: null }, // correo (EmailIngestion) al que se responde
});

const emit = defineEmits(['close', 'sent']);

const to = ref('');
const subject = ref('');
const body = ref('');
const instrucciones = ref('');
const visibleCliente = ref(false);

const redactando = ref(false);
const enviando = ref(false);
const error = ref(null);
const successMsg = ref(null);

// "Nombre <correo@dominio>" → "correo@dominio"
function soloEmail(v) {
    if (!v) return '';
    const m = v.match(/<([^>]+)>/);
    return (m ? m[1] : v).trim();
}

watch(
    () => props.show,
    (open) => {
        if (open && props.email) {
            to.value = soloEmail(props.email.from);
            const s = props.email.subject || '';
            subject.value = /^re:/i.test(s) ? s : `Re: ${s}`;
            body.value = '';
            instrucciones.value = '';
            visibleCliente.value = false;
            error.value = null;
            successMsg.value = null;
        }
    },
);

function close() {
    emit('close');
}

function describeError(e) {
    if (e.response) {
        const d = e.response.data ?? {};
        if (d.error) return `${d.error}${d.detail ? ` — ${d.detail}` : ''}`;
        if (d.message) return d.message;
        return `HTTP ${e.response.status}`;
    }
    return `Error de red: ${e.message}`;
}

async function redactarIA() {
    error.value = null;
    redactando.value = true;
    try {
        const url = route('admin.processes.emails.draft', { process: props.process.id, ingestion: props.email.id });
        const { data } = await window.axios.post(url, { instrucciones: instrucciones.value });
        body.value = data.borrador ?? '';
    } catch (e) {
        error.value = describeError(e);
    } finally {
        redactando.value = false;
    }
}

async function enviar() {
    error.value = null;
    successMsg.value = null;
    enviando.value = true;
    try {
        const url = route('admin.processes.emails.reply', { process: props.process.id, ingestion: props.email.id });
        await window.axios.post(url, {
            to: to.value,
            subject: subject.value,
            body: body.value,
            visible_cliente: visibleCliente.value,
        });
        successMsg.value = 'Respuesta enviada por correo.';
        emit('sent');
    } catch (e) {
        error.value = describeError(e);
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="close">
        <div class="bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Responder correo</h3>
                    <p class="text-xs text-slate-500">Proceso {{ process.codigo }}</p>
                </div>
                <button @click="close" class="text-slate-400 transition hover:text-slate-600">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="max-h-[72vh] space-y-4 overflow-y-auto px-6 py-5">
                <!-- Correo original, como referencia -->
                <div v-if="email" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                    <p class="text-xs font-medium text-slate-500">En respuesta a:</p>
                    <p class="mt-0.5 text-slate-700"><span class="font-medium">{{ email.from }}</span> — {{ email.subject }}</p>
                    <p class="mt-1 whitespace-pre-wrap text-xs text-slate-500">{{ email.body_preview }}</p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel for="reply-to" value="Para" />
                        <input id="reply-to" v-model="to" type="email"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <InputLabel for="reply-subject" value="Asunto" />
                        <input id="reply-subject" v-model="subject" type="text" maxlength="255"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <!-- Asistencia IA -->
                <div class="rounded-lg border border-indigo-100 bg-indigo-50/60 p-3">
                    <InputLabel for="reply-instr" value="Instrucciones para la IA (opcional)" />
                    <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                        <input id="reply-instr" v-model="instrucciones" type="text"
                            placeholder="Ej: confirmar recepción y pedir el contrato firmado"
                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <SecondaryButton :disabled="redactando" class="shrink-0" @click="redactarIA">
                            {{ redactando ? 'Redactando…' : 'Redactar con IA' }}
                        </SecondaryButton>
                    </div>
                </div>

                <div>
                    <InputLabel for="reply-body" value="Mensaje" />
                    <textarea id="reply-body" v-model="body" rows="11"
                        placeholder="Escribe la respuesta o genérala con IA…"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <Checkbox v-model:checked="visibleCliente" />
                    Registrar el comentario como visible para el cliente
                </label>

                <div v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 whitespace-pre-wrap">{{ error }}</div>
                <div v-if="successMsg" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{{ successMsg }}</div>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-3">
                <SecondaryButton @click="close">Cerrar</SecondaryButton>
                <PrimaryButton :disabled="enviando || !to || !body.trim()" @click="enviar">
                    {{ enviando ? 'Enviando…' : 'Enviar respuesta' }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
