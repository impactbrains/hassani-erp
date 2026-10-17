<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ChevronDown, Languages, LogOut, Menu, Store as StoreIcon, UserCircle2, X } from 'lucide-vue-next';
import FlashToasts from '@/components/app/FlashToasts.vue';
import { navigation } from '@/lib/nav';
import { localName, t } from '@/lib/i18n';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const auth = computed(() => page.props.auth!);
const locale = computed(() => page.props.locale);
const mobileOpen = ref(false);
const userMenu = ref(false);

const groups = computed(() =>
    navigation
        .map((group) => ({ ...group, items: group.items.filter((item) => !item.any || item.any.some((p) => auth.value.permissions.includes(p))) }))
        .filter((group) => group.items.length > 0),
);
const isActive = (match?: string) => (match ? route().current(match) : false);
const store = computed(() => auth.value.stores.find((s) => s.id === auth.value.store_id));

function switchStore(e: Event) {
    router.post(route('store.switch'), { store_id: Number((e.target as HTMLSelectElement).value) }, { preserveScroll: true });
}
function toggleLocale() {
    router.post(route('locale.update'), { locale: locale.value === 'ur' ? 'en' : 'ur' }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <div class="min-h-screen lg:flex">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 start-0 z-40 flex w-64 -translate-x-full flex-col bg-ink-800 text-ink-100 transition-transform rtl:translate-x-full lg:static lg:translate-x-0 rtl:lg:translate-x-0" :class="{ '!translate-x-0': mobileOpen }">
            <div class="flex h-14 items-center gap-2.5 border-b border-white/10 px-4">
                <svg viewBox="0 0 32 32" class="h-8 w-8 shrink-0"><rect width="32" height="32" rx="7" fill="#E0A419" /><path d="M9 7v18M23 7v18M9 16h14" stroke="#1C2B4A" stroke-width="3.2" stroke-linecap="round" /></svg>
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-[15px] font-semibold text-white">{{ page.props.app.company?.name ?? 'Hassani ERP' }}</div>
                    <div class="truncate text-[12px] text-ink-300">{{ store ? localName(store) : '' }}</div>
                </div>
                <button class="ms-auto rounded p-1 text-ink-300 lg:hidden" @click="mobileOpen = false"><X class="h-5 w-5" /></button>
            </div>
            <nav class="flex-1 overflow-y-auto px-2 py-3 text-[14px]">
                <div v-for="group in groups" :key="group.label" class="mb-3">
                    <div class="flex items-center gap-2 px-2.5 pb-1 pt-1 text-[12px] font-medium text-ink-300">
                        <component :is="group.icon" class="h-4 w-4 text-ink-400" />{{ t(group.label) }}
                    </div>
                    <Link
                        v-for="item in group.items"
                        :key="item.route"
                        :href="route(item.route, item.params as any)"
                        class="relative block rounded px-2.5 py-1.5 ps-8 text-ink-100/90 hover:bg-white/5 hover:text-white"
                        :class="{ '!bg-white/10 !text-white': isActive(item.match) }"
                        @click="mobileOpen = false"
                    >
                        <span v-if="isActive(item.match)" class="absolute inset-y-1.5 start-3 w-[3px] rounded bg-haldi-500" />
                        {{ t(item.label) }}
                    </Link>
                </div>
            </nav>
        </aside>
        <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-ink-950/40 lg:hidden" @click="mobileOpen = false" />

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-ink-100 bg-white/95 px-4 backdrop-blur no-print">
                <button class="rounded p-1.5 text-ink-600 hover:bg-ink-50 lg:hidden" aria-label="Menu" @click="mobileOpen = true"><Menu class="h-5 w-5" /></button>
                <label v-if="auth.stores.length > 1" class="flex items-center gap-2 text-sm text-ink-600">
                    <StoreIcon class="h-4 w-4" />
                    <select class="rounded border-ink-200 py-1 pe-8 text-sm" :value="auth.store_id ?? ''" @change="switchStore">
                        <option v-for="s in auth.stores" :key="s.id" :value="s.id">{{ localName(s) }}</option>
                    </select>
                </label>
                <div class="ms-auto flex items-center gap-1">
                    <button class="flex items-center gap-1.5 rounded px-2.5 py-1.5 text-sm text-ink-700 hover:bg-ink-50" :title="t('Language')" @click="toggleLocale">
                        <Languages class="h-4 w-4" />
                        <span :class="locale === 'ur' ? '' : 'urdu text-[13px] leading-none'">{{ locale === 'ur' ? 'English' : 'اردو' }}</span>
                    </button>
                    <div class="relative">
                        <button class="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-ink-50" @click="userMenu = !userMenu">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-ink-800 text-[12px] font-semibold text-haldi-400">{{ auth.user.name.slice(0, 1) }}</span>
                            <span class="hidden max-w-[10rem] truncate font-medium text-ink-800 sm:block">{{ auth.user.name }}</span>
                            <ChevronDown class="h-4 w-4 text-ink-400" />
                        </button>
                        <div v-if="userMenu" class="absolute end-0 z-30 mt-1 w-56 rounded-lg border border-ink-100 bg-white py-1 shadow-pop" @mouseleave="userMenu = false">
                            <div class="border-b border-ink-100 px-3 py-2 text-[13px] text-ink-500">{{ auth.user.email }}</div>
                            <Link :href="route('account.show')" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-ink-50"><UserCircle2 class="h-4 w-4" />{{ t('My account') }}</Link>
                            <Link :href="route('logout')" method="post" as="button" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-ledger-red hover:bg-ledger-redSoft"><LogOut class="h-4 w-4" />{{ t('Sign out') }}</Link>
                        </div>
                    </div>
                </div>
            </header>
            <main class="mx-auto w-full max-w-[1500px] flex-1 px-4 py-5 sm:px-6"><slot /></main>
        </div>
        <FlashToasts />
    </div>
</template>
