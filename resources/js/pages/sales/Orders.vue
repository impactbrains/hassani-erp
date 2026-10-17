<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useFilters } from '@/composables/useFilters';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ orders: any; counts: Record<string, number>; filters: Record<string, any> }>();
const filters = useFilters('sales.orders.index', { q: props.filters.q ?? '', status: props.filters.status ?? '', channel: props.filters.channel ?? '' });
const tabs = [
    { key: '', label: 'All' }, { key: 'draft', label: 'Draft', count: props.counts.draft }, { key: 'confirmed', label: 'Confirmed', count: props.counts.confirmed },
    { key: 'picking', label: 'Picking', count: props.counts.picking }, { key: 'packed', label: 'Packed', count: props.counts.packed }, { key: 'dispatched', label: 'Out for delivery', count: props.counts.dispatched },
    { key: 'completed', label: 'Completed' }, { key: 'cancelled', label: 'Cancelled' },
];
const channels: Record<string, string> = { phone: 'Phone', whatsapp: 'WhatsApp', field_agent: 'Field agent', counter: 'Counter', ecommerce: 'Online' };
const columns = [
    { key: 'number', label: 'Order' }, { key: 'business_date', label: 'Date', type: 'date' as const }, { key: 'customer.name', label: 'Customer' }, { key: 'channel_label', label: 'Channel' },
    { key: 'fulfillment', label: 'Pickup / delivery' }, { key: 'sales_agent.name', label: 'Agent' }, { key: 'total', label: 'Total', type: 'money' as const }, { key: 'status', label: 'Status', type: 'status' as const },
];
const rows = (data: any[]) => data.map((o) => ({ ...o, channel_label: t(channels[o.channel] ?? o.channel), fulfillment: o.fulfillment_type === 'delivery' ? `${t('Delivery')}${o.delivery_area ? ' · ' + o.delivery_area : ''}` : t('Pickup') }));
</script>

<template>
    <PageHeader :title="t('Orders')" :subtitle="t('Phone, WhatsApp, field-agent and online orders. Confirming reserves the stock; dispatch makes the invoice.')">
        <template #actions><Button v-if="can('sales.orders.manage')" variant="primary" :href="route('sales.orders.create')">{{ t('New order') }}</Button></template>
    </PageHeader>
    <Tabs :model-value="filters.status" :tabs="tabs" @update:model-value="(v) => (filters.status = v)" />
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Order number or customer')" />
        <select v-model="filters.channel" class="input max-w-[12rem]"><option value="">{{ t('All channels') }}</option><option v-for="(l, k) in channels" :key="k" :value="k">{{ t(l) }}</option></select>
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="rows(orders.data)" :row-href="(r) => route('sales.orders.show', r.id)" empty="No orders." />
        <Pagination :meta="orders" />
    </section>
</template>
