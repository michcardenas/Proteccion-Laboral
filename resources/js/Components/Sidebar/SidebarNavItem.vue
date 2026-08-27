<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
    icon: { type: String, default: null },
    active: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
    badge: { type: [Number, String], default: null },
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
                : 'text-brand-600 hover:bg-brand-100 hover:text-brand-900',
            collapsed ? 'lg:justify-center lg:gap-0 lg:px-2' : '',
        ]"
    >
        <span
            class="flex h-5 w-5 items-center justify-center"
            :class="active ? 'text-white' : 'text-brand-400 group-hover:text-brand-700'"
            v-html="icon"
        />
        <span class="truncate" :class="collapsed ? 'lg:hidden' : ''">{{ label }}</span>
        <span
            v-if="badge"
            class="ml-auto inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none"
            :class="[
                active ? 'bg-white/20 text-white' : 'bg-danger-100 text-danger-700',
                collapsed ? 'lg:hidden' : '',
            ]"
        >
            {{ badge }}
        </span>
    </Link>
</template>
