<script setup lang="ts">
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    routeName: string; // e.g. 'contacts.employees' or 'contacts.suppliers-view'
    activeView: string; // 'overview' | 'employees'/'suppliers' | 'candidates'
    listLabel: string; // 'Employees' or 'Suppliers'
    counts: { list: number; candidates: number };
}>();

const tabs = [
    { value: 'overview', label: 'Overview' },
    { value: 'list', label: props.listLabel },
    { value: 'candidates', label: 'Candidates' },
];

function selectView(view: string) {
    // 'list' maps back to whatever the actual view key is server-side
    // (employees / suppliers), everything else passes through as-is.
    const serverView = view === 'list' ? (props.routeName.includes('employees') ? 'employees' : 'suppliers') : view;
    router.get(route(props.routeName), { view: serverView }, { preserveState: true, replace: true });
}

function isActive(tabValue: string): boolean {
    if (tabValue === 'list') {
        return props.activeView !== 'overview' && props.activeView !== 'candidates';
    }
    return props.activeView === tabValue;
}
</script>

<template>
    <div class="mb-6 flex gap-1 border-b border-slate-200">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            @click="selectView(tab.value)"
            class="border-b-2 px-4 py-2.5 font-sans text-sm font-medium transition-colors"
            :class="
                isActive(tab.value)
                    ? 'border-brand-500 text-ink-900'
                    : 'border-transparent text-slate-500 hover:text-ink-800'
            "
        >
            {{ tab.label }}
            <span v-if="tab.value === 'list' && counts.list" class="ml-1 text-xs text-slate-400">({{ counts.list }})</span>
            <span v-if="tab.value === 'candidates' && counts.candidates" class="ml-1 text-xs text-slate-400">({{ counts.candidates }})</span>
        </button>
    </div>
</template>