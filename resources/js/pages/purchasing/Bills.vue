<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useFilters } from '@/composables/useFilters';
import { date, money } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ bills: any; returns: any[]; filters: Record<string, any> }>();
const filters = useFilters('purchasing.bills', { payment_status: props.filters.payment_status ?? '' });
const columns = [
    { key: 'number', label: 'Bill' }, { key: 'bill_date', label: 'Date', type: 'date' as const }, { key: 'supplier.name', label: 'Supplier' }, { key: 'supplier_invoice_no', label: 'Supplier invoice no.' },
    { key: 'due_date', label: 'Due', type: 'date' as const }, { key: 'total', label: 'Total', type: 'money' as const }, { key: 'amount_paid', label: 'Paid', type: 'money' as const }, { key: 'payment_status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Supplier bills')" :subtitle="t('Bills from suppliers, matched to the goods received. Pay suppliers from the supplier page.')">
        <template #actions>
            <Button v-if="can('purchasing.returns.manage')" :href="route('purchasing.returns.create')">{{ t('Return goods to supplier') }}</Button>
            <Button v-if="can('purchasing.bills.manage')" variant="primary" :href="route('purchasing.bills.create')">{{ t('Enter bill') }}</Button>
        </template>
    </PageHeader>
    <Tabs :model-value="filters.payment_status" :tabs="[{ key: '', label: 'All' }, { key: 'unpaid', label: 'Unpaid' }, { key: 'partial', label: 'Part paid' }, { key: 'paid', label: 'Paid' }]" @update:model-value="(v) => (filters.payment_status = v)" />
    <section class="panel mb-5">
        <DataTable :columns="columns" :rows="bills.data" :row-href="(r) => route('purchasing.bills.show', r.id)" empty="No bills yet." />
        <Pagination :meta="bills" />
    </section>
    <section class="panel">
        <div class="panel-head"><h2>{{ t('Returns to suppliers') }}</h2></div>
        <table class="table-base">
            <thead><tr><th>{{ t('Number') }}</th><th>{{ t('Date') }}</th><th>{{ t('Supplier') }}</th><th>{{ t('Reason') }}</th><th class="num">{{ t('Value') }}</th></tr></thead>
            <tbody>
                <tr v-for="r in returns" :key="r.id"><td>{{ r.number }}</td><td>{{ date(r.business_date) }}</td><td>{{ r.supplier?.name }}</td><td>{{ r.reason_code?.label ?? '—' }}</td><td class="num">{{ money(r.total) }}</td></tr>
                <tr v-if="!returns.length"><td colspan="5" class="py-6 text-center text-ink-500">{{ t('No returns.') }}</td></tr>
            </tbody>
        </table>
    </section>
</template>
