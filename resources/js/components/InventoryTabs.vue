<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const tabs = [
    { label: 'Overview', href: () => route('inventory.index'), active: () => page.component === 'inventory/Index' },
    { label: 'Record movement', href: () => route('inventory.record-movement'), active: () => page.component === 'inventory/RecordMovement' },
    { label: 'History', href: () => route('inventory.history'), active: () => page.component === 'inventory/History' },
    { label: 'Locations', href: () => route('inventory.locations.index'), active: () => page.component === 'inventory/Locations' },
];
</script>

<template>
    <div class="mb-6 flex gap-1 border-b border-slate-200">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href()"
            class="border-b-2 px-4 py-2.5 font-sans text-sm font-medium transition-colors"
            :class="
                tab.active()
                    ? 'border-brand-500 text-ink-900'
                    : 'border-transparent text-slate-500 hover:text-ink-800'
            "
        >
            {{ tab.label }}
        </Link>
    </div>
</template>