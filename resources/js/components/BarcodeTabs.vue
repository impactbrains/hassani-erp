<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const currentUrl = computed(() => page.url);

const tabs = [
    { label: 'Product barcodes', href: () => route('barcode.index'), match: '/barcode' },
    { label: 'Generate labels', href: () => route('barcode.generate-batch'), match: '/barcode/generate-batch' },
    { label: 'Barcode pool', href: () => route('barcode.pool'), match: '/barcode/pool' },
];

function isActive(match: string): boolean {
    if (match === '/barcode') return currentUrl.value === '/barcode';
    return currentUrl.value.startsWith(match);
}
</script>

<template>
    <div class="mt-4 flex gap-1 border-b border-slate-200">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href()"
            class="rounded-t-lg px-3 py-2 font-sans text-sm font-medium transition-colors"
            :class="isActive(tab.match) ? 'border-b-2 border-brand-500 text-ink-900' : 'text-slate-400 hover:text-ink-900'"
        >
            {{ tab.label }}
        </Link>
    </div>
</template>