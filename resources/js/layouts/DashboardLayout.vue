<script setup lang="ts">
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Search, ChevronDown } from 'lucide-vue-next';

const page = usePage();
const user = (page.props.auth as any)?.user;

const showUserMenu = ref(false);
</script>

<template>
    <div class="flex min-h-screen bg-slate-50 font-sans">
        <!-- Sidebar -->
        <aside class="hidden w-16 flex-col items-center gap-6 bg-ink-900 py-5 md:flex">
            <Link :href="route('dashboard')" class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-500 font-display text-sm font-bold text-ink-900">
                H
            </Link>

            <Link
                :href="route('dashboard')"
                class="flex h-10 w-10 items-center justify-center rounded-lg text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                title="All apps"
            >
                <LayoutGrid :size="20" :stroke-width="1.75" />
            </Link>
        </aside>

        <div class="flex flex-1 flex-col">
            <!-- Top bar -->
            <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
                <div class="relative w-full max-w-sm">
                    <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        placeholder="Search..."
                        class="w-full rounded-lg border-slate-200 bg-slate-50 py-2 pl-9 pr-3 font-sans text-sm placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                    />
                </div>

                <div class="relative ml-4 shrink-0">
                    <button
                        @click="showUserMenu = !showUserMenu"
                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-slate-100"
                    >
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-ink-800 font-sans text-xs font-semibold text-white">
                            {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                        </div>
                        <span class="hidden font-sans text-sm font-medium text-ink-900 sm:block">
                            {{ user?.name }}
                        </span>
                        <ChevronDown :size="16" class="text-slate-400" />
                    </button>

                    <div
                        v-if="showUserMenu"
                        @click="showUserMenu = false"
                        class="absolute right-0 top-full mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                    >
                        <Link :href="route('profile.edit')" class="block px-4 py-2 font-sans text-sm text-ink-900 hover:bg-slate-50">
                            Profile
                        </Link>
                        <Link :href="route('logout')" method="post" as="button" class="block w-full px-4 py-2 text-left font-sans text-sm text-danger-500 hover:bg-slate-50">
                            Log out
                        </Link>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>