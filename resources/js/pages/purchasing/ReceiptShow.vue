<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { date, dateTime, money, qty } from '@/lib/format';
import { can } from '@/lib/perms';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; receipt: any; showCost: boolean; canDecide: boolean }>();
function print() {
    window.print();
}
/** Bought with Purchase stock: billed (and maybe paid) in the same step. */
const isPurchase = computed(() => ['paid_now', 'on_credit'].includes(props.receipt.settlement));
const owed = computed(() => (props.receipt.bill ? Number(props.receipt.bill.total) - Number(props.receipt.bill.amount_paid) : 0));
const reason = ref('');
function decide(decision: 'approve' | 'reject') {
    router.post(route('purchasing.receipts.decide', props.receipt.id), { decision, reason: reason.value || null }, { preserveScroll: true });
}
</script>

<template>
<PageHeader :pager="pager" :title="`${isPurchase ? t('Purchase') : t('Goods received')} ${receipt.number}`" :subtitle="`${receipt.supplier?.name} · ${date(receipt.business_date)} · ${receipt.location?.name ?? ''}`">
        <template #actions>
            <Badge :status="receipt.status" />
            <Button @click="print"><Printer class="h-4 w-4" />{{ t('Print') }}</Button>
            <Button v-if="receipt.bill && can('purchasing.bills.manage')" :href="route('purchasing.bills.show', receipt.bill.id)">{{ t('Open the bill') }}</Button>
            <Button v-else-if="receipt.status === 'posted' && !isPurchase && can('purchasing.bills.manage')" variant="primary" :href="route('purchasing.bills.create', { supplier_id: receipt.supplier_id })">{{ t('Enter supplier bill') }}</Button>
        </template>
    </PageHeader>
    <section v-if="receipt.status === 'pending_approval'" class="panel mb-4 border-haldi-300 bg-haldi-50 p-4">
        <p class="text-sm font-medium">{{ canDecide ? t('This purchase is waiting for your approval. Nothing is added to stock, and nothing is paid, until it is approved.') : t('This purchase is waiting for a manager to approve it. Nothing is added to stock, and nothing is paid, until then.') }}</p>
        <div v-if="canDecide" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="flex-1"><span class="field-label">{{ t('Reason (needed to reject)') }}</span><input v-model="reason" class="input" /></label>
            <Button variant="danger" :disabled="!reason" @click="decide('reject')">{{ t('Reject') }}</Button>
            <Button variant="primary" @click="decide('approve')">{{ t('Approve and add to stock') }}</Button>
        </div>
    </section>
    <section v-if="receipt.status === 'rejected'" class="panel mb-4 border-ledger-red/30 bg-ledger-redSoft p-4 text-sm text-ledger-red">
        {{ t('Rejected by :name: :reason', { name: receipt.approver?.name ?? '—', reason: receipt.decision_note ?? '' }) }}
    </section>
    <section v-if="isPurchase" class="panel mb-4 grid gap-4 p-4 text-sm md:grid-cols-4">
        <div><span class="field-label">{{ t('Payment') }}</span>{{ receipt.settlement === 'paid_now' ? t('Paid now') : t('On credit') }}<span v-if="receipt.payment_method"> · {{ localName(receipt.payment_method) }}</span></div>
        <div v-if="receipt.settlement === 'paid_now'"><span class="field-label">{{ t('Amount paid') }}</span><span class="tabular-nums">{{ money(receipt.amount_paid ?? receipt.goods_value) }}</span><span v-if="receipt.payment_reference" class="text-ink-500"> · {{ receipt.payment_reference }}</span></div>
        <div v-if="receipt.bill"><span class="field-label">{{ t('Still owed to the supplier for this purchase') }}</span><span class="tabular-nums" :class="owed > 0 ? 'font-semibold text-ledger-red' : ''">{{ money(owed) }}</span><span v-if="owed > 0 && receipt.bill.due_date" class="text-ink-500"> · {{ t('Due') }} {{ date(receipt.bill.due_date) }}</span></div>
        <div v-if="receipt.approver && receipt.status === 'posted'"><span class="field-label">{{ t('Approved by') }}</span>{{ receipt.approver.name }}</div>
    </section>
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
