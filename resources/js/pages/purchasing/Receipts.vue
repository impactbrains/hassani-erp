<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ receipts: any; showCost: boolean }>();
const columns = [
    { key: 'number', label: 'GRN' }, { key: 'business_date', label: 'Date', type: 'date' as const }, { key: 'supplier.name', label: 'Supplier' }, { key: 'purchase_order.number', label: 'PO' },
    { key: 'supplier_reference', label: 'Supplier bill / DC no.' }, ...(props.showCost ? [{ key: 'goods_value', label: 'Value', type: 'money' as const }] : []), { key: 'receiver.name', label: 'Received by' }, { key: 'status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Goods received')" :subtitle="t('Every delivery from a supplier: what came, damage, batch and expiry. Stock and average cost update when saved.')">
        <template #actions><Button v-if="can('purchasing.receive')" variant="primary" :href="route('purchasing.receipts.create')">{{ t('Receive without PO') }}</Button></template>
    </PageHeader>
    <section class="panel">
        <DataTable :columns="columns" :rows="receipts.data" :row-href="(r) => route('purchasing.receipts.show', r.id)" empty="Nothing received yet." />
        <Pagination :meta="receipts" />
    </section>
</template>
