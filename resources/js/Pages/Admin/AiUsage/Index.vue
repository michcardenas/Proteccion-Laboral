<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    generations: { type: Object, required: true },
    stats: { type: Object, required: true },
    ultima: { type: Object, default: null },
    filters: { type: Object, default: () => ({ mes: '', user_id: null, modelo: null }) },
    filterOptions: { type: Object, default: () => ({ usuarios: [], modelos: [], meses: [] }) },
});

const filterMes = ref(props.filters.mes ?? '');
const filterUserId = ref(props.filters.user_id ?? '');
const filterModelo = ref(props.filters.modelo ?? '');

function mesLabel(mes) {
    if (!mes) return '';
    const [y, m] = mes.split('-');
    const d = new Date(Number(y), Number(m) - 1, 1);
    return d.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });
}

function applyFilters() {
    router.get(
        route('admin.ai.usage'),
        {
            mes: filterMes.value || undefined,
            user_id: filterUserId.value || undefined,
            modelo: filterModelo.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetFilters() {
    filterMes.value = '';
    filterUserId.value = '';
    filterModelo.value = '';
    applyFilters();
}

const expandedRow = ref(null);
const detailOpen = ref(false);
const detailGeneration = ref(null);

function formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
}

function shortHash(h) {
    return h ? h.substring(0, 12) : '—';
}

function formatCost(v) {
    if (v === null || v === undefined) return '$0.000000';
    return '$' + Number(v).toFixed(6);
}

function formatCostSmart(v) {
    const n = Number(v);
    if (n >= 1) return '$' + n.toFixed(2);
    if (n >= 0.01) return '$' + n.toFixed(4);
    return '$' + n.toFixed(6);
}

function openDetail(g) {
    detailGeneration.value = g;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
}

function copyToClipboard(text) {
    if (text) navigator.clipboard.writeText(text);
}

function toggleRow(id) {
    expandedRow.value = expandedRow.value === id ? null : id;
}

const successRate = computed(() => {
    if (!props.stats.total) return 0;
    return Math.round((props.stats.total_ok / props.stats.total) * 100);
});
</script>

<template>
    <Head title="IA · Uso del mes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Uso de Inteligencia Artificial
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

                <!-- Hero header -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-700 via-purple-700 to-fuchsia-700 px-8 py-10 text-white shadow-lg">
                    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="absolute -bottom-16 -left-16 h-56 w-56 rounded-full bg-white/5 blur-3xl"></div>
                    <div class="relative">
                        <p class="text-xs uppercase tracking-[0.2em] text-indigo-200">Reporte mensual</p>
                        <h1 class="mt-2 text-3xl font-bold capitalize">{{ stats.mes_nombre || stats.mes }}</h1>
                        <p class="mt-2 max-w-2xl text-sm text-indigo-100">
                            Actividad del módulo de IA: generación de borradores legales, clasificación de correos
                            y métricas de costo de Anthropic Claude.
                        </p>
                        <Link
                            :href="route('admin.ai.playground')"
                            class="mt-5 inline-flex items-center gap-2 rounded-lg bg-white/15 px-4 py-2 text-sm font-medium backdrop-blur-sm transition hover:bg-white/25"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                            </svg>
                            Generar nuevo borrador
                        </Link>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Mes</label>
                            <select
                                v-model="filterMes"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">Mes actual</option>
                                <option v-for="m in filterOptions.meses" :key="m" :value="m" class="capitalize">{{ mesLabel(m) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Usuario</label>
                            <select
                                v-model="filterUserId"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="u in filterOptions.usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Modelo</label>
                            <select
                                v-model="filterModelo"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="mod in filterOptions.modelos" :key="mod" :value="mod">{{ mod }}</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button
                                @click="resetFilters"
                                class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                            >
                                Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>

                <!-- KPI cards grid -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Total -->
                    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Generaciones</p>
                                <p class="mt-2 text-4xl font-bold text-slate-900">{{ stats.total }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    <span class="font-medium text-emerald-600">{{ stats.total_ok }} ok</span>
                                    <span v-if="stats.total_error > 0" class="text-rose-600"> · {{ stats.total_error }} error</span>
                                </p>
                            </div>
                            <div class="rounded-lg bg-indigo-50 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-indigo-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                                </svg>
                            </div>
                        </div>
                        <div v-if="stats.total > 0" class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: successRate + '%' }"></div>
                        </div>
                    </div>

                    <!-- Tokens -->
                    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tokens totales</p>
                                <p class="mt-2 text-4xl font-bold text-slate-900">
                                    {{ (stats.tokens_in_total + stats.tokens_out_total).toLocaleString() }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    <span class="font-medium">↓ {{ stats.tokens_in_total.toLocaleString() }}</span>
                                    <span> · ↑ {{ stats.tokens_out_total.toLocaleString() }}</span>
                                </p>
                            </div>
                            <div class="rounded-lg bg-blue-50 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-blue-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Cost -->
                    <div class="relative overflow-hidden rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 p-5 text-white shadow-lg">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-amber-100">Costo USD</p>
                                <p class="mt-2 text-4xl font-bold">{{ formatCostSmart(stats.costo_total) }}</p>
                                <p class="mt-1 text-xs text-amber-100">
                                    Promedio por generación: {{ stats.total > 0 ? formatCostSmart(stats.costo_total / stats.total) : '$0' }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-white/20 p-2 backdrop-blur-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4M5 12h14"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Latency -->
                    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Latencia</p>
                                <p class="mt-2 text-4xl font-bold text-slate-900">
                                    {{ stats.latencia_promedio.toLocaleString() }}<span class="text-base font-medium text-slate-500">ms</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Promedio · Máx {{ stats.latencia_max.toLocaleString() }}ms
                                </p>
                            </div>
                            <div class="rounded-lg bg-purple-50 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-purple-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Última generación destacada -->
                <div v-if="ultima" class="overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-900 shadow-xl">
                    <div class="border-b border-white/10 px-6 py-4 flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <h2 class="text-sm font-semibold uppercase tracking-wider text-white/80">Última generación exitosa</h2>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-white/60">
                            <span>{{ formatDate(ultima.created_at) }}</span>
                            <span class="font-mono">#{{ ultima.id }}</span>
                            <button
                                @click="copyToClipboard(ultima.respuesta)"
                                class="rounded-md bg-white/10 px-3 py-1 text-xs font-medium text-white hover:bg-white/20"
                            >Copiar texto</button>
                        </div>
                    </div>
                    <div class="px-6 py-6 grid lg:grid-cols-[1fr,260px] gap-6">
                        <!-- Respuesta -->
                        <div class="min-w-0">
                            <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-white/90 max-h-96 overflow-y-auto rounded-lg bg-black/30 p-4 backdrop-blur-sm">{{ ultima.respuesta }}</pre>
                        </div>
                        <!-- Side metadata -->
                        <div class="space-y-3 text-sm">
                            <div class="rounded-lg bg-white/10 px-4 py-3">
                                <p class="text-xs uppercase tracking-wider text-white/50">Generada por</p>
                                <p class="mt-1 font-medium text-white">{{ ultima.user || '—' }}</p>
                            </div>
                            <div class="rounded-lg bg-white/10 px-4 py-3">
                                <p class="text-xs uppercase tracking-wider text-white/50">Modelo</p>
                                <p class="mt-1 font-mono text-xs text-white">{{ ultima.modelo }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-lg bg-white/10 px-3 py-2 text-center">
                                    <p class="text-[10px] uppercase tracking-wider text-white/50">Tokens IN</p>
                                    <p class="mt-0.5 text-lg font-bold text-emerald-300">{{ ultima.tokens_in.toLocaleString() }}</p>
                                </div>
                                <div class="rounded-lg bg-white/10 px-3 py-2 text-center">
                                    <p class="text-[10px] uppercase tracking-wider text-white/50">Tokens OUT</p>
                                    <p class="mt-0.5 text-lg font-bold text-blue-300">{{ ultima.tokens_out.toLocaleString() }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-lg bg-white/10 px-3 py-2 text-center">
                                    <p class="text-[10px] uppercase tracking-wider text-white/50">Costo</p>
                                    <p class="mt-0.5 text-lg font-bold text-amber-300">{{ formatCostSmart(ultima.costo_usd) }}</p>
                                </div>
                                <div class="rounded-lg bg-white/10 px-3 py-2 text-center">
                                    <p class="text-[10px] uppercase tracking-wider text-white/50">Latencia</p>
                                    <p class="mt-0.5 text-lg font-bold text-purple-300">{{ ultima.latencia_ms ? ultima.latencia_ms + 'ms' : '—' }}</p>
                                </div>
                            </div>
                            <div class="rounded-lg bg-white/5 px-3 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-white/50">Request hash</p>
                                <p class="mt-0.5 font-mono text-[10px] text-white/70">{{ shortHash(ultima.request_hash) }}…</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Historial completo -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 bg-slate-50 px-6 py-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">Historial del mes</h2>
                            <p class="text-xs text-slate-500">Clic en una fila para ver el borrador generado.</p>
                        </div>
                        <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-medium text-slate-700">
                            {{ generations.data.length }} registros
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Fecha</th>
                                    <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Usuario</th>
                                    <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Modelo</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Tokens</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Latencia</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Costo</th>
                                    <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Estado</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template v-for="g in generations.data" :key="g.id">
                                    <tr
                                        class="cursor-pointer hover:bg-indigo-50/40 transition-colors"
                                        @click="toggleRow(g.id)"
                                    >
                                        <td class="px-4 py-3 text-sm text-slate-700 whitespace-nowrap">{{ formatDate(g.created_at) }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-700">{{ g.user || '—' }}</td>
                                        <td class="px-4 py-3 text-xs font-mono text-slate-500">{{ g.modelo }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-700 text-right whitespace-nowrap">
                                            <span class="text-emerald-700">↓{{ g.tokens_in.toLocaleString() }}</span>
                                            <span class="text-slate-400 mx-1">/</span>
                                            <span class="text-blue-700">↑{{ g.tokens_out.toLocaleString() }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-500 text-right whitespace-nowrap">{{ g.latencia_ms ? g.latencia_ms + 'ms' : '—' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-amber-700 text-right whitespace-nowrap">{{ formatCost(g.costo_usd) }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span
                                                :class="g.estado === 'ok'
                                                    ? 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'
                                                    : g.estado === 'error'
                                                    ? 'bg-rose-100 text-rose-700 ring-1 ring-rose-200'
                                                    : 'bg-amber-100 text-amber-700 ring-1 ring-amber-200'"
                                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                            >
                                                <span :class="g.estado === 'ok' ? 'bg-emerald-500' : g.estado === 'error' ? 'bg-rose-500' : 'bg-amber-500'" class="h-1.5 w-1.5 rounded-full"></span>
                                                {{ g.estado }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke-width="2"
                                                stroke="currentColor"
                                                class="h-4 w-4 text-slate-400 transition-transform"
                                                :class="{ 'rotate-180': expandedRow === g.id }"
                                            >
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                            </svg>
                                        </td>
                                    </tr>
                                    <tr v-if="expandedRow === g.id" class="bg-indigo-50/30">
                                        <td colspan="8" class="px-6 py-4">
                                            <div class="space-y-3">
                                                <div v-if="g.estado === 'error'" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                                    <p class="font-semibold">Error</p>
                                                    <p class="font-mono text-xs">{{ g.error_mensaje || 'Sin detalle' }}</p>
                                                </div>
                                                <div v-if="g.respuesta_preview" class="rounded-md border border-slate-200 bg-white p-4">
                                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Respuesta (preview)</p>
                                                    <p class="mt-2 text-sm text-slate-700 whitespace-pre-wrap">{{ g.respuesta_preview }}</p>
                                                </div>
                                                <div class="flex items-center justify-between text-xs">
                                                    <div class="flex items-center gap-4 text-slate-500">
                                                        <span><span class="font-semibold text-slate-700">ID:</span> #{{ g.id }}</span>
                                                        <span><span class="font-semibold text-slate-700">Hash:</span> <code class="font-mono">{{ shortHash(g.request_hash) }}…</code></span>
                                                        <span><span class="font-semibold text-slate-700">Contexto:</span> {{ g.contexto_tipo?.split('\\').pop() }} #{{ g.contexto_id }}</span>
                                                    </div>
                                                    <button
                                                        class="text-indigo-600 font-medium hover:text-indigo-800"
                                                        @click.stop="openDetail(g)"
                                                    >Ver detalle completo →</button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="generations.data.length === 0">
                                    <td colspan="8" class="px-6 py-10 text-center text-sm text-slate-400">
                                        <p class="font-medium">Aún no hay generaciones este mes</p>
                                        <p class="text-xs mt-1">
                                            Ve al
                                            <Link :href="route('admin.ai.playground')" class="text-indigo-600 underline">Playground</Link>
                                            para crear la primera.
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="generations.links && generations.links.length > 3" class="border-t border-slate-200 bg-slate-50 px-4 py-3 flex flex-wrap gap-1">
                        <template v-for="link in generations.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                v-html="link.label"
                                :class="link.active ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 hover:bg-indigo-50'"
                                class="rounded-md border border-slate-200 px-3 py-1 text-xs font-medium"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="rounded-md border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-medium text-slate-400"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de detalle completo -->
        <Modal :show="detailOpen" max-width="4xl" @close="closeDetail">
            <div v-if="detailGeneration" class="bg-white">
                <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Generación #{{ detailGeneration.id }}</h3>
                        <p class="text-xs text-slate-500">{{ formatDate(detailGeneration.created_at) }} · {{ detailGeneration.user }}</p>
                    </div>
                    <button @click="closeDetail" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="px-6 py-4 max-h-[70vh] overflow-y-auto space-y-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                        <div class="rounded-md bg-slate-50 px-3 py-2"><p class="text-slate-500">Modelo</p><p class="font-mono text-slate-900">{{ detailGeneration.modelo }}</p></div>
                        <div class="rounded-md bg-emerald-50 px-3 py-2"><p class="text-emerald-700">Tokens IN</p><p class="text-lg font-bold text-emerald-900">{{ detailGeneration.tokens_in.toLocaleString() }}</p></div>
                        <div class="rounded-md bg-blue-50 px-3 py-2"><p class="text-blue-700">Tokens OUT</p><p class="text-lg font-bold text-blue-900">{{ detailGeneration.tokens_out.toLocaleString() }}</p></div>
                        <div class="rounded-md bg-amber-50 px-3 py-2"><p class="text-amber-700">Costo</p><p class="text-lg font-bold text-amber-900">{{ formatCost(detailGeneration.costo_usd) }}</p></div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Prompt enviado</p>
                            <button @click="copyToClipboard(detailGeneration.prompt)" class="text-xs text-indigo-600 hover:text-indigo-800">Copiar</button>
                        </div>
                        <pre class="text-xs font-mono bg-slate-900 text-slate-100 rounded-md p-3 whitespace-pre-wrap max-h-64 overflow-y-auto">{{ detailGeneration.prompt }}</pre>
                    </div>

                    <div v-if="detailGeneration.respuesta">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Respuesta de Claude</p>
                            <button @click="copyToClipboard(detailGeneration.respuesta)" class="text-xs text-indigo-600 hover:text-indigo-800">Copiar</button>
                        </div>
                        <pre class="text-sm bg-slate-50 rounded-md p-3 whitespace-pre-wrap max-h-96 overflow-y-auto">{{ detailGeneration.respuesta }}</pre>
                    </div>

                    <div v-if="detailGeneration.error_mensaje" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wider text-rose-700">Error</p>
                        <p class="mt-1 text-sm font-mono text-rose-900">{{ detailGeneration.error_mensaje }}</p>
                    </div>

                    <div class="text-xs text-slate-500 font-mono">
                        Hash: {{ detailGeneration.request_hash }}
                    </div>
                </div>
                <div class="border-t border-slate-200 px-6 py-3 flex justify-end">
                    <SecondaryButton @click="closeDetail">Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>
