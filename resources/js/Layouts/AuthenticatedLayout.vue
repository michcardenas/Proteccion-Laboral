<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppSidebar from '@/Components/Sidebar/AppSidebar.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const page = usePage();
const sidebarOpen = ref(false);

// Colapso del sidebar en escritorio (persistido en localStorage).
const collapsed = ref(typeof window !== 'undefined' && localStorage.getItem('sidebar-collapsed') === '1');
watch(collapsed, (v) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem('sidebar-collapsed', v ? '1' : '0');
    }
});

const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const user = computed(() => page.props.auth.user);
const userRoles = computed(() => user.value?.roles ?? []);

const initials = computed(() => {
    if (!user.value?.name) return '';
    return user.value.name.split(' ').map(n => n[0]).slice(0, 2).join('');
});

watch(() => page.url, () => { sidebarOpen.value = false; });
</script>

<template>
    <div class="min-h-screen bg-slate-50 font-sans text-slate-900">
        <!-- Mobile drawer overlay -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar (mobile drawer + desktop fixed) -->
        <div
            class="fixed inset-y-0 left-0 z-50 transform transition-transform duration-200 ease-out lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        >
            <AppSidebar
                :collapsed="collapsed"
                @close="sidebarOpen = false"
                @toggle-collapse="collapsed = !collapsed"
            />
        </div>

        <!-- Main column -->
        <div class="transition-[padding] duration-200 ease-out" :class="collapsed ? 'lg:pl-20' : 'lg:pl-72'">
            <!-- Topbar -->
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/85 backdrop-blur">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="rounded-md p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:hidden"
                        @click="sidebarOpen = true"
                        aria-label="Abrir menú"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        <slot name="header" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-1 pr-3 text-sm transition hover:bg-slate-50"
                                >
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-900 text-[11px] font-semibold text-white">
                                        {{ initials }}
                                    </span>
                                    <span class="hidden text-slate-700 sm:block">{{ user.name }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-slate-400">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <div class="border-b border-slate-100 px-4 py-3">
                                    <p class="text-sm font-medium text-slate-900">{{ user.name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
                                    <p class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-slate-700">
                                        {{ userRoles[0] }}
                                    </p>
                                </div>
                                <DropdownLink :href="route('profile.edit')">Mi perfil</DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">
                                    Cerrar sesión
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </header>

            <!-- Flash banners -->
            <div v-if="flashSuccess" class="border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-center text-sm text-emerald-800 sm:px-6 lg:px-8">
                {{ flashSuccess }}
            </div>
            <div v-if="flashError" class="border-b border-rose-200 bg-rose-50 px-4 py-2 text-center text-sm text-rose-800 sm:px-6 lg:px-8">
                {{ flashError }}
            </div>

            <!-- Page content -->
            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
