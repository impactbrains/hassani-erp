<script setup lang="ts">
import { usePage, router } from '@inertiajs/vue3';

usePage();

defineProps<{
    activeType?: string;
}>();

const tabs = [
    { value: '', label: 'All' },
    { value: 'customer', label: 'Customers' },
    { value: 'supplier', label: 'Suppliers' },
    { value: 'employee', label: 'Employees' },
];

function selectType(type: string) {
    router.get(route('contacts.index'), { type: type || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <div class="mb-6 flex gap-1 border-b border-slate-200">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            @click="selectType(tab.value)"
            class="border-b-2 px-4 py-2.5 font-sans text-sm font-medium transition-colors"
            :class="
                (activeType ?? '') === tab.value
                    ? 'border-brand-500 text-ink-900'
                    : 'border-transparent text-slate-500 hover:text-ink-800'
            "
        >
            {{ tab.label }}
        </button>
    </div>
</template>