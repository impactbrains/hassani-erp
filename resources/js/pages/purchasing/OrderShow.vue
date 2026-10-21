<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Pencil, Printer } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import { date, dateTime, money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; order: any; can: { manage: boolean; approve: boolean; receive: boolean } }>();
const s = () => props.order.status;
const reasonFor = ref<'' | 'cancel' | 'close'>('');
const reason = ref('');
function act(action: string, extra: Record<string, any> = {}) {
    router.post(route('purchasing.orders.action', props.order.id), { action, ...extra }, { preserveScroll: true, onSuccess: () => { reasonFor.value = ''; reason.value = ''; } });
}
function print() {
    window.print();
}
const received = (l: any) => Number(l.base_quantity_received) / Number(l.unit_factor || 1);
</script>

<template>
    <PageHeader :pager="pager" :title="`${t('Purchase order')} ${order.number}`" :subtitle="`${order.supplier?.name} · ${date(order.order_date)} · ${order.store?.name}`">
        <template #actions>
            <Badge :status="order.status" />
            <Button @click="print"><Printer class="h-4 w-4" />{{ t('Print') }}</Button>
            <Button v-if="s() === 'draft' && can.manage" :href="route('purchasing.orders.edit', order.id)"><Pencil class="h-4 w-4" />{{ t('Edit') }}</Button>
            <Button v-if="s() === 'draft' && can.manage" variant="primary" @click="act('submit')">{{ t('Submit') }}</Button>
            <Button v-if="s() === 'submitted' && can.approve" variant="primary" @click="act('approve')">{{ t('Approve') }}</Button>
            <Button v-if="s() === 'approved' && can.manage" variant="primary" @click="act('send')">{{ t('Mark sent to supplier') }}</Button>
            <Button v-if="['approved', 'sent', 'partially_received'].includes(s()) && can.receive" variant="primary" :href="route('purchasing.receipts.create', { purchase_order_id: order.id })">{{ t('Receive goods') }}</Button>
            <Button v-if="s() === 'partially_received' && can.manage" @click="reasonFor = 'close'">{{ t('Close (rest not coming)') }}</Button>
            <Button v-if="['draft', 'submitted', 'approved', 'sent'].includes(s()) && can.manage" variant="danger" @click="reasonFor = 'cancel'">{{ t('Cancel') }}</Button>
        </template>
    </PageHeader>
    <div class="hidden print:block mb-4"><h1 class="text-xl font-semibold">{{ t('Purchase order') }} {{ order.number }}</h1><p>{{ order.store?.name }} → {{ order.supplier?.name }} {{ order.supplier?.phone }}</p><p>{{ t('Date') }}: {{ date(order.order_date) }}<span v-if="order.expected_date"> · {{ t('Expected') }}: {{ date(order.expected_date) }}</span></p></div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Ordered') }}</th><th class="num">{{ t('Cost per pack') }}</th><th class="num">{{ t('Tax %') }}</th><th class="num">{{ t('Amount') }}</th><th class="num no-print">{{ t('Received') }}</th></tr></thead>
            <tbody>
                <tr v-for="l in order.lines" :key="l.id">
                    <td><div class="font-medium">{{ l.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ l.variant?.sku }}</div></td>
                    <td class="num">{{ qty(l.quantity) }} {{ l.variant_unit?.unit?.code }}</td><td class="num">{{ money(l.unit_price) }}</td><td class="num">{{ Number(l.tax_rate) || '—' }}</td><td class="num font-medium">{{ money(l.line_total) }}</td>
                    <td class="num no-print" :class="received(l) >= Number(l.quantity) ? 'text-ledger-green' : received(l) > 0 ? 'text-haldi-800' : ''">{{ qty(received(l)) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr><td colspan="4" class="px-3 py-1 text-end">{{ t('Subtotal') }}</td><td class="num px-3 py-1">{{ money(order.subtotal) }}</td><td class="no-print"></td></tr>
                <tr v-if="Number(order.tax_total)"><td colspan="4" class="px-3 py-1 text-end">{{ t('Tax') }}</td><td class="num px-3 py-1">{{ money(order.tax_total) }}</td><td class="no-print"></td></tr>
                <tr><td colspan="4" class="px-3 py-2 text-end font-semibold">{{ t('Total') }}</td><td class="num px-3 py-2 text-base font-semibold">{{ money(order.total) }}</td><td class="no-print"></td></tr>
            </tfoot>
        </table>
        <p v-if="order.notes" class="whitespace-pre-line border-t border-ink-100 p-4 text-sm">{{ order.notes }}</p>
    </section>
    <div class="no-print mt-5 grid gap-5 md:grid-cols-2">
        <section class="panel space-y-1 p-4 text-sm">
            <div>{{ t('Created by') }} {{ order.creator?.name }} · {{ dateTime(order.created_at) }}</div>
            <div v-if="order.approver">{{ t('Approved by') }} {{ order.approver.name }} · {{ dateTime(order.approved_at) }}</div>
            <div v-if="order.sent_at">{{ t('Sent') }} {{ dateTime(order.sent_at) }}</div>
        </section>
        <section class="panel p-4 text-sm">
            <h3 class="mb-2">{{ t('Goods received') }}</h3>
            <ul><li v-for="r in order.receipts" :key="r.id"><a :href="route('purchasing.receipts.show', r.id)" class="link">{{ r.number }}</a> · {{ date(r.business_date) }} · {{ money(r.goods_value) }}</li><li v-if="!order.receipts?.length" class="text-ink-500">{{ t('Nothing received yet.') }}</li></ul>
        </section>
    </div>
    <Modal :open="!!reasonFor" :title="reasonFor === 'cancel' ? t('Cancel purchase order') : t('Close purchase order')" size="sm" @close="reasonFor = ''">
        <label class="block"><span class="field-label">{{ t('Reason') }}</span><input v-model="reason" class="input" /></label>
        <template #footer><Button @click="reasonFor = ''">{{ t('Back') }}</Button><Button variant="danger" :disabled="!reason" @click="act(reasonFor, { reason })">{{ t('Confirm') }}</Button></template>
    </Modal>
</template>
