<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    paginator: { type: Object, default: null },
    emptyMessage: { type: String, default: 'No hay registros para mostrar.' },
});
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-600"
                            :class="column.thClass"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="columns.length"
                            class="px-4 py-8 text-center text-sm text-gray-500"
                        >
                            {{ emptyMessage }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="hover:bg-gray-50"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-3 text-sm text-gray-700"
                            :class="column.tdClass"
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="row[column.key]"
                            >
                                {{ row[column.key] }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="paginator && paginator.last_page > 1"
            class="flex items-center justify-between border-t border-gray-200 px-4 py-3"
        >
            <div class="text-xs text-gray-600">
                Mostrando {{ paginator.from }} - {{ paginator.to }} de {{ paginator.total }}
            </div>
            <div class="flex flex-wrap gap-1">
                <Link
                    v-for="(link, idx) in paginator.links"
                    :key="idx"
                    :href="link.url || ''"
                    preserve-scroll
                    preserve-state
                    class="rounded border px-2.5 py-1 text-xs"
                    :class="[
                        link.active
                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                            : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50',
                        !link.url ? 'pointer-events-none opacity-50' : '',
                    ]"
                    v-html="link.label"
                />
            </div>
        </nav>
    </div>
</template>
