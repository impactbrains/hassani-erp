<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { t } from '@/lib/i18n';

defineProps<{ title: string; subtitle?: string }>();
const page = usePage<any>();
const company = computed(() => page.props.app?.company?.name ?? 'Hassani ERP');
const locale = computed(() => page.props.locale);

function toggleLocale() {
    router.post(route('locale.update'), { locale: locale.value === 'ur' ? 'en' : 'ur' }, { preserveState: false });
}
</script>

<template>
    <Head :title="title" />
    <div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <!-- Ledger page: ruled lines with the red margin, as in a khata register -->
        <aside class="relative hidden overflow-hidden bg-ink-800 lg:block">
            <div class="absolute inset-0 opacity-[.16]" style="background-image: repeating-linear-gradient(to bottom, transparent 0 39px, #8496b5 39px 40px)" />
            <div class="absolute inset-y-0 start-24 w-[2px] bg-ledger-red/70" />
            <div class="relative flex h-full flex-col justify-between p-12 ps-32 text-white">
                <svg viewBox="0 0 32 32" class="h-10 w-10"><rect width="32" height="32" rx="7" fill="#E0A419" /><path d="M9 7v18M23 7v18M9 16h14" stroke="#1C2B4A" stroke-width="3.2" stroke-linecap="round" /></svg>
                <div>
                    <p class="text-3xl font-semibold leading-tight">{{ company }}</p>
                    <p class="urdu mt-3 text-2xl text-haldi-300">کھاتہ، اسٹاک اور فروخت — ایک جگہ</p>
                    <p class="mt-3 max-w-sm text-ink-200">Sales, Khata, stock and staff in one place, kept on the shop computer.</p>
                </div>
                <p class="text-[13px] text-ink-300">Hassani ERP</p>
            </div>
        </aside>
        <main class="flex flex-col bg-paper px-6 py-8">
            <div class="flex justify-end">
                <button class="rounded px-3 py-1.5 text-sm text-ink-600 hover:bg-white" @click="toggleLocale">
                    <span :class="locale === 'ur' ? '' : 'urdu'">{{ locale === 'ur' ? 'English' : 'اردو' }}</span>
                </button>
            </div>
            <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-8">
                <h1 class="text-2xl">{{ t(title) }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-ink-500">{{ t(subtitle) }}</p>
                <div class="mt-6"><slot /></div>
            </div>
        </main>
    </div>
</template>
