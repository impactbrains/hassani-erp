<script setup lang="ts">
import { Printer } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { date, dateTime, money, qty } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

defineProps<{ receipt: any; showCost: boolean }>();
function print() {
    window.print();
}
</script>

<template>
    <PageHeader :title="`${t('Goods received')} ${receipt.number}`" :subtitle="`${receipt.supplier?.name} · ${date(receipt.business_date)} · ${receipt.location?.name ?? ''}`">
        <template #actions>
            <Badge :status="receipt.status" />
            <Button @click="print"><Printer class="h-4 w-4" />{{ t('Print') }}</Button>
            <Button v-if="can('purchasing.bills.manage')" variant="primary" :href="route('purchasing.bills.create', { supplier_id: receipt.supplier_id })">{{ t('Enter supplier bill') }}</Button>
        </template>
    </PageHeader>
    <section class="panel mb-4 grid gap-4 p-4 text-sm md:grid-cols-4">
        <div><span class="field-label">{{ t('Purchase order') }}</span><a v-if="receipt.purchase_order" :href="route('purchasing.orders.show', receipt.purchase_order.id)" class="link">{{ receipt.purchase_order.number }}</a><span v-else>—</span></div>
        <div><span class="field-label">{{ t('Supplier bill / DC no.') }}</span>{{ receipt.supplier_reference ?? '—' }}</div>
        <div><span class="field-label">{{ t('Received by') }}</span>{{ receipt.receiver?.name }} · {{ dateTime(receipt.received_at) }}</div>
        <div v-if="showCost"><span class="field-label">{{ t('Value') }}</span>{{ money(receipt.goods_value) }} <span v-if="Number(receipt.landed_cost_total)" class="text-ink-500">+ {{ money(receipt.landed_cost_total) }}</span></div>
        <p v-if="receipt.notes" class="md:col-span-4">{{ receipt.notes }}</p>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Expected') }}</th><th class="num">{{ t('Received') }}</th><th class="num">{{ t('Damaged') }}</th><th>{{ t('Batch / expiry') }}</th><th>{{ t('Quality') }}</th><th v-if="showCost" class="num">{{ t('Cost per pack') }}</th><th v-if="showCost" class="num">{{ t('Cost per unit incl. freight') }}</th></tr></thead>
            <tbody>
                <tr v-for="l in receipt.lines" :key="l.id">
                    <td><div class="font-medium">{{ l.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ l.variant?.sku }} · {{ l.variant_unit?.unit?.code }}</div><div v-if="l.discrepancy_note" class="text-[12px] text-haldi-800">{{ l.discrepancy_note }}</div></td>
                    <td class="num">{{ l.quantity_expected !== null ? qty(l.quantity_expected) : '—' }}</td><td class="num">{{ qty(l.quantity_received) }}</td>
                    <td class="num" :class="Number(l.quantity_damaged) ? 'text-ledger-red' : ''">{{ Number(l.quantity_damaged) ? qty(l.quantity_damaged) : '—' }}</td>
                    <td>{{ l.batch_no ?? '—' }}<span v-if="l.expiry_date"> · {{ date(l.expiry_date) }}</span></td>
                    <td><Badge :status="l.quality_status" /></td>
                    <td v-if="showCost" class="num">{{ money(l.unit_price) }}</td><td v-if="showCost" class="num">{{ money(l.base_unit_cost) }}</td>
                </tr>
            </tbody>
        </table>
        <div v-if="receipt.landed_costs?.length && showCost" class="border-t border-ink-100 p-4 text-sm">
            <h3 class="mb-1">{{ t('Extra costs') }}</h3>
            <ul><li v-for="c in receipt.landed_costs" :key="c.id">{{ t(c.type) }} · {{ c.description }} · {{ money(c.amount) }} ({{ c.allocation_method === 'quantity' ? t('by quantity') : t('by value') }})</li></ul>
        </div>
    </section>
</template>
