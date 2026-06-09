<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
    icon: { type: String, default: null },
    active: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
});
</script>

<template>
    <Link
        :href="href"
        :title="collapsed ? label : null"
        class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
        :class="[
            active
                ? 'bg-brand-900 text-white shadow-sm'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
            collapsed ? 'lg:justify-center lg:gap-0 lg:px-2' : '',
        ]"
    >
        <span
            class="flex h-5 w-5 items-center justify-center"
            :class="active ? 'text-white' : 'text-slate-400 group-hover:text-slate-700'"
            v-html="icon"
        />
        <span class="truncate" :class="collapsed ? 'lg:hidden' : ''">{{ label }}</span>
    </Link>
</template>
