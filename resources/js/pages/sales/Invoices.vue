<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useFilters } from '@/composables/useFilters';
import { t } from '@/lib/i18n';

const props = defineProps<{ invoices: any; filters: Record<string, any> }>();
const filters = useFilters('sales.invoices.index', { q: props.filters.q ?? '', payment_status: props.filters.payment_status ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const columns = [
    { key: 'number', label: 'Number' }, { key: 'business_date', label: 'Date', type: 'date' as const }, { key: 'customer_name', label: 'Customer' }, { key: 'cashier.name', label: 'Cashier' },
    { key: 'total', label: 'Total', type: 'money' as const }, { key: 'amount_paid', label: 'Paid', type: 'money' as const }, { key: 'amount_credited', label: 'Returned', type: 'money' as const }, { key: 'payment_status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Receipts')" :subtitle="t('Find a receipt to reprint it or take a return.')" />
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Receipt number or customer')" />
        <select v-model="filters.payment_status" class="input max-w-[12rem]"><option value="">{{ t('Any payment status') }}</option><option value="paid">{{ t('paid') }}</option><option value="partial">{{ t('partial') }}</option><option value="unpaid">{{ t('unpaid') }}</option></select>
        <input v-model="filters.from" type="date" class="input max-w-[11rem]" /><input v-model="filters.to" type="date" class="input max-w-[11rem]" />
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="invoices.data.map((r: any) => ({ ...r, customer_name: r.customer_name ?? t('Walk-in') }))" :row-href="(r) => route('sales.invoices.show', r.id)" empty="No receipts found." />
        <Pagination :meta="invoices" />
    </section>
</template>
