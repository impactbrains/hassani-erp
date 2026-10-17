<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Pencil } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PinApproval from '@/components/app/PinApproval.vue';
import { useOptions } from '@/composables/useOptions';
import { dateTime, money, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';
import { get } from '@/lib/http';

const props = defineProps<{ order: any; can: { manage: boolean; confirm: boolean; deliver: boolean }; cancelReasons: any[] }>();
const s = () => props.order.status;
const cancelling = ref(false);
const cancelReason = ref<number | null>(props.cancelReasons[0]?.id ?? null);
const dispatching = ref(false);
const riderId = ref<number | null>(null);
const riders = ref<any[]>([]);
const approval = ref(false);
const busy = ref(false);
const pay: Record<string, string> = { cod: 'Cash on delivery', khata: 'Khata (credit)', wallet: 'Easypaisa / JazzCash', prepaid: 'Paid in advance' };

function post(name: string, data: Record<string, any> = {}, done?: () => void) {
    busy.value = true;
    router.post(route(name, props.order.id), data, { preserveScroll: true, onFinish: () => { busy.value = false; done?.(); } });
}
async function openDispatch() {
    dispatching.value = true;
    if (props.order.fulfillment_type === 'delivery' && !riders.value.length) {
        riders.value = await get(route('lookup.contacts', { type: 'employee', role_type: 'rider', limit: 50 })).catch(() => []);
    }
}
</script>

<template>
    <PageHeader :title="`${t('Order')} ${order.number}`" :subtitle="`${order.customer?.name} · ${dateTime(order.created_at)} · ${order.creator?.name ?? ''}`">
        <template #actions>
            <Badge :status="order.status" />
            <Button v-if="s() === 'draft' && can.manage" :href="route('sales.orders.edit', order.id)"><Pencil class="h-4 w-4" />{{ t('Edit') }}</Button>
            <Button v-if="s() === 'draft' && can.confirm" variant="primary" :loading="busy" @click="post('sales.orders.confirm')">{{ t('Confirm and reserve stock') }}</Button>
            <Button v-if="s() === 'confirmed' && can.manage" :loading="busy" @click="post('sales.orders.advance', { status: 'picking' })">{{ t('Start picking') }}</Button>
            <Button v-if="s() === 'picking' && can.manage" :loading="busy" @click="post('sales.orders.advance', { status: 'packed' })">{{ t('Mark packed') }}</Button>
            <Button v-if="['confirmed', 'picking', 'packed'].includes(s()) && can.confirm" variant="primary" @click="openDispatch">{{ order.fulfillment_type === 'delivery' ? t('Invoice and send') : t('Invoice for pickup') }}</Button>
            <Button v-if="['draft', 'confirmed', 'picking', 'packed'].includes(s()) && can.manage" variant="danger" @click="cancelling = true">{{ t('Cancel order') }}</Button>
        </template>
    </PageHeader>
    <p v-if="s() === 'draft' && order.payment_preference === 'khata'" class="mb-4 text-sm text-ink-600">{{ t('Khata order: if it is over the customer’s credit limit, a manager can') }} <button class="link" @click="approval = true">{{ t('approve with PIN') }}</button>.</p>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <section class="panel">
            <table class="table-base">
                <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Quantity') }}</th><th class="num">{{ t('Price') }}</th><th class="num">{{ t('Discount') }}</th><th class="num">{{ t('Amount') }}</th><th class="num">{{ t('Invoiced') }}</th></tr></thead>
                <tbody>
                    <tr v-for="l in order.lines" :key="l.id">
                        <td><div class="font-medium">{{ l.variant?.display_name ?? l.description }}</div><div v-if="l.variant?.name_ur" class="urdu text-[13px] text-ink-500" dir="rtl" style="text-align: start">{{ l.variant.name_ur }}</div></td>
                        <td class="num">{{ qty(l.quantity) }} {{ l.variant_unit?.unit?.code }}</td><td class="num">{{ money(l.unit_price) }}</td><td class="num">{{ money(l.discount_amount) }}</td>
                        <td class="num font-medium">{{ money(l.line_total) }}</td><td class="num">{{ Number(l.base_quantity_invoiced) ? qty(l.base_quantity_invoiced) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <dl class="space-y-1 border-t border-ink-100 p-4 text-sm">
                <div class="flex justify-between"><dt>{{ t('Items') }}</dt><dd class="tabular-nums">{{ money(Number(order.subtotal) - Number(order.discount_total)) }}</dd></div>
                <div v-if="Number(order.tax_total)" class="flex justify-between"><dt>{{ t('Tax') }}</dt><dd class="tabular-nums">{{ money(order.tax_total) }}</dd></div>
                <div v-if="Number(order.delivery_charge)" class="flex justify-between"><dt>{{ t('Delivery') }}</dt><dd class="tabular-nums">{{ money(order.delivery_charge) }}</dd></div>
                <div class="flex justify-between text-base font-semibold"><dt>{{ t('Total') }}</dt><dd class="tabular-nums">{{ money(order.total) }}</dd></div>
            </dl>
            <p v-if="order.notes" class="whitespace-pre-line border-t border-ink-100 p-4 text-sm text-ink-700">{{ order.notes }}</p>
        </section>
        <aside class="space-y-5">
            <section class="panel space-y-2 p-4 text-sm">
                <div><span class="field-label">{{ t('Customer') }}</span>{{ order.customer?.name }} <span class="text-ink-500">{{ order.customer?.code }}</span></div>
                <div v-if="order.customer?.customer_profile"><span class="field-label">{{ t('Credit limit') }}</span>{{ money(order.customer.customer_profile.credit_limit) }}</div>
                <div><span class="field-label">{{ t('Payment') }}</span>{{ t(pay[order.payment_preference] ?? order.payment_preference ?? '—') }}</div>
                <div><span class="field-label">{{ t('Channel') }}</span>{{ order.channel }}<span v-if="order.sales_agent"> · {{ order.sales_agent.name }}</span></div>
                <div v-if="order.fulfillment_type === 'delivery'"><span class="field-label">{{ t('Deliver to') }}</span>{{ order.delivery_address }}<br />{{ order.delivery_area }} · {{ order.delivery_phone }}</div>
                <div v-else><span class="field-label">{{ t('Fulfilment') }}</span>{{ t('Pickup') }}</div>
                <div v-if="order.requested_delivery_at"><span class="field-label">{{ t('Wanted by') }}</span>{{ dateTime(order.requested_delivery_at) }}</div>
                <div v-if="order.cancel_reason"><span class="field-label">{{ t('Cancelled because') }}</span>{{ order.cancel_reason.label }}</div>
            </section>
            <section v-if="order.invoices?.length" class="panel p-4 text-sm">
                <h3 class="mb-2">{{ t('Invoices') }}</h3>
                <ul class="space-y-1"><li v-for="i in order.invoices" :key="i.id"><a :href="route('sales.invoices.show', i.id)" class="link">{{ i.number }}</a> · {{ money(i.total) }} · <Badge :status="i.payment_status" /></li></ul>
            </section>
            <section v-for="d in order.deliveries ?? []" :key="d.id" class="panel p-4 text-sm">
                <h3 class="mb-2">{{ t('Delivery') }} {{ d.number }} <Badge :status="d.status" /></h3>
                <div>{{ t('Rider') }}: {{ d.rider?.name ?? '—' }} · {{ t('Collect') }} {{ money(d.cod_amount) }}</div>
                <ul class="mt-2 space-y-0.5 text-[12.5px] text-ink-600"><li v-for="e in d.events" :key="e.id">{{ dateTime(e.created_at) }} · {{ t(e.status.replaceAll('_', ' ')) }}<span v-if="e.note"> · {{ e.note }}</span></li></ul>
                <a v-if="can.deliver" :href="route('sales.riders')" class="link mt-2 inline-block">{{ t('Rider board') }}</a>
            </section>
        </aside>
    </div>

    <Modal :open="cancelling" :title="t('Cancel order')" size="sm" @close="cancelling = false">
        <label class="block"><span class="field-label">{{ t('Reason') }}</span><select v-model="cancelReason" class="input"><option v-for="r in cancelReasons" :key="r.id" :value="r.id">{{ localName(r) }}</option></select></label>
        <template #footer><Button @click="cancelling = false">{{ t('Back') }}</Button><Button variant="danger" :loading="busy" @click="post('sales.orders.cancel', { reason_id: cancelReason }, () => (cancelling = false))">{{ t('Cancel order') }}</Button></template>
    </Modal>
    <Modal :open="dispatching" :title="order.fulfillment_type === 'delivery' ? t('Invoice and send') : t('Invoice for pickup')" size="sm" @close="dispatching = false">
        <p class="mb-3 text-sm text-ink-600">{{ t('This makes the invoice and takes the reserved stock out.') }}</p>
        <label v-if="order.fulfillment_type === 'delivery'" class="block"><span class="field-label">{{ t('Rider (can be chosen later)') }}</span>
            <select v-model="riderId" class="input"><option :value="null">{{ t('Later') }}</option><option v-for="r in riders" :key="r.id" :value="r.id">{{ r.name }}</option></select>
        </label>
        <template #footer><Button @click="dispatching = false">{{ t('Back') }}</Button><Button variant="primary" :loading="busy" @click="post('sales.orders.dispatch', { rider_id: riderId }, () => (dispatching = false))">{{ t('Make invoice') }}</Button></template>
    </Modal>
    <PinApproval :open="approval" action="credit_limit" @close="approval = false" @approved="(token: string) => { approval = false; post('sales.orders.confirm', { approval_token: token }); }" />
</template>
