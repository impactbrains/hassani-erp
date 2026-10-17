<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import { useFilters } from '@/composables/useFilters';
import { t } from '@/lib/i18n';

const props = defineProps<{ shifts: any; filters: Record<string, any> }>();
const filters = useFilters('pos.shifts.index', { status: props.filters.status ?? '', variance: props.filters.variance ?? '' });
const columns = [
    { key: 'number', label: 'Shift' }, { key: 'business_date', label: 'Date', type: 'date' as const }, { key: 'store.name', label: 'Store' }, { key: 'register.name', label: 'POS counter' },
    { key: 'opener.name', label: 'Cashier' }, { key: 'opening_float', label: 'Opening cash', type: 'money' as const }, { key: 'expected_cash', label: 'Expected cash', type: 'money' as const },
    { key: 'counted_cash', label: 'Counted', type: 'money' as const }, { key: 'variance', label: 'Short / over', type: 'money' as const }, { key: 'status', label: 'Status', type: 'status' as const }, { key: 'variance_status', label: 'Review', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Shifts')" :subtitle="t('Every POS shift with its cash count. Differences are reviewed by a manager and never deducted from pay automatically.')">
        <template #actions><Button variant="primary" :href="route('pos.screen')">{{ t('Open POS') }}</Button></template>
    </PageHeader>
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <select v-model="filters.status" class="input max-w-[12rem]"><option value="">{{ t('All shifts') }}</option><option value="open">{{ t('Open') }}</option><option value="closed">{{ t('Closed') }}</option></select>
        <label class="flex items-center gap-2 text-sm"><input v-model="filters.variance" type="checkbox" true-value="pending" false-value="" class="rounded border-ink-300" />{{ t('Only differences waiting for review') }}</label>
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="shifts.data" :row-href="(r) => route('pos.shifts.show', r.id)" empty="No shifts yet." />
        <Pagination :meta="shifts" />
    </section>
</template>
