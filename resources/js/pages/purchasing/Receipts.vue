<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import { useFilters } from '@/composables/useFilters';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ receipts: any; showCost: boolean; filters: { status: string }; waiting: number }>();
const filters = useFilters('purchasing.receipts.index', { status: props.filters.status ?? '' });
const paymentLabels: Record<string, string> = { paid_now: 'Paid now', on_credit: 'On credit', bill_later: 'Bill comes later' };
const columns = [
    { key: 'number', label: 'GRN' }, { key: 'business_date', label: 'Date', type: 'date' as const }, { key: 'supplier.name', label: 'Supplier' }, { key: 'purchase_order.number', label: 'PO' },
    { key: 'supplier_reference', label: 'Supplier bill / DC no.' }, ...(props.showCost ? [{ key: 'goods_value', label: 'Value', type: 'money' as const }] : []), { key: 'payment_label', label: 'Payment' }, { key: 'receiver.name', label: 'Received by' }, { key: 'status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Goods received')" :subtitle="t('Every delivery from a supplier: what came, damage, batch and expiry. Stock and average cost update when saved.')">
<template #actions>
            <Button v-if="can('inventory.purchase_stock')" :href="route('purchasing.purchase-stock')">{{ t('Purchase stock') }}</Button>
            <Button v-if="can('purchasing.receive')" variant="primary" :href="route('purchasing.receipts.create')">{{ t('Receive without PO') }}</Button>
        </template>
    </PageHeader>
    <p v-if="waiting && filters.status !== 'pending_approval'" class="mb-4 rounded-lg border border-haldi-300 bg-haldi-50 px-4 py-2.5 text-sm">
        {{ waiting === 1 ? t('1 purchase is waiting for your approval.') : t(':n purchases are waiting for your approval.', { n: waiting }) }}
        <Link :href="route('purchasing.receipts.index', { status: 'pending_approval' })" class="link ms-1">{{ t('Show them') }}</Link>
    </p>
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <select v-model="filters.status" class="input max-w-[14rem]"><option value="">{{ t('Any status') }}</option><option value="pending_approval">{{ t('Waiting for approval') }}</option><option value="posted">{{ t('Posted') }}</option><option value="rejected">{{ t('Rejected') }}</option></select>
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="receipts.data.map((r: any) => ({ ...r, payment_label: r.settlement && paymentLabels[r.settlement] ? t(paymentLabels[r.settlement]) : '—' }))" :row-href="(r) => route('purchasing.receipts.show', r.id)" empty="Nothing received yet." />
        <Pagination :meta="receipts" />
    </section>
</template>
