<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useFilters } from '@/composables/useFilters';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ orders: any; filters: Record<string, any> }>();
const filters = useFilters('purchasing.orders.index', { status: props.filters.status ?? '', supplier_id: props.filters.supplier_id ?? '' });
const columns = [
    { key: 'number', label: 'PO' }, { key: 'order_date', label: 'Date', type: 'date' as const }, { key: 'supplier.name', label: 'Supplier' }, { key: 'store.name', label: 'Store' },
    { key: 'expected_date', label: 'Expected', type: 'date' as const }, { key: 'total', label: 'Total', type: 'money' as const }, { key: 'creator.name', label: 'By' }, { key: 'status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Purchase orders')" :subtitle="t('What you ordered from suppliers. Big orders need approval before sending.')">
        <template #actions>
            <Button v-if="can('inventory.replenishment.manage')" :href="route('inventory.replenishment')">{{ t('Reorder suggestions') }}</Button>
            <Button v-if="can('purchasing.orders.manage')" variant="primary" :href="route('purchasing.orders.create')">{{ t('New purchase order') }}</Button>
        </template>
    </PageHeader>
    <Tabs :model-value="filters.status" :tabs="[{ key: '', label: 'All' }, { key: 'draft', label: 'Draft' }, { key: 'submitted', label: 'Waiting for approval' }, { key: 'approved', label: 'Approved' }, { key: 'sent', label: 'Sent' }, { key: 'partially_received', label: 'Part received' }, { key: 'received', label: 'Received' }, { key: 'closed', label: 'Closed' }, { key: 'cancelled', label: 'Cancelled' }]" @update:model-value="(v) => (filters.status = v)" />
    <section class="panel">
        <DataTable :columns="columns" :rows="orders.data" :row-href="(r) => route('purchasing.orders.show', r.id)" empty="No purchase orders." />
        <Pagination :meta="orders" />
    </section>
</template>
