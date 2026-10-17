<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { dateTime, money } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

/** Rider board: who is carrying what, record each delivery, and take the cash back from riders. */
const props = defineProps<{ deliveries: any[]; riders: any[]; handovers: any[]; openShift: number | null }>();
const columns = [
    { key: 'pending', label: 'Waiting for a rider' }, { key: 'assigned', label: 'Assigned' }, { key: 'out_for_delivery', label: 'On the way' }, { key: 'done', label: 'Finished today' },
];
const grouped = computed(() => ({
    pending: props.deliveries.filter((d) => d.status === 'pending'),
    assigned: props.deliveries.filter((d) => d.status === 'assigned'),
    out_for_delivery: props.deliveries.filter((d) => d.status === 'out_for_delivery'),
    done: props.deliveries.filter((d) => !['pending', 'assigned', 'out_for_delivery'].includes(d.status)),
} as Record<string, any[]>));

const outcome = ref<any>(null);
const outcomeForm = useForm({ outcome: 'delivered', collected: '', reason: '', note: '' });
function openOutcome(d: any) {
    outcome.value = d;
    outcomeForm.reset();
    outcomeForm.collected = String(d.cod_amount ?? '');
}
function saveOutcome() {
    outcomeForm.post(route('sales.deliveries.update', outcome.value.id), { preserveScroll: true, onSuccess: () => (outcome.value = null) });
}
function assign(d: any, riderId: string) {
    if (riderId) router.post(route('sales.deliveries.assign', d.id), { rider_id: Number(riderId) }, { preserveScroll: true });
}
const handover = ref<any>(null);
const handoverForm = useForm({ rider_id: null as number | null, received: '', shift_id: props.openShift, note: '' });
function openHandover(r: any) {
    handover.value = r;
    handoverForm.rider_id = r.id;
    handoverForm.received = String(r.cash_with_rider ?? '');
    handoverForm.note = '';
}
function saveHandover() {
    handoverForm.post(route('sales.riders.handover'), { preserveScroll: true, onSuccess: () => (handover.value = null) });
}
</script>

<template>
    <PageHeader :title="t('Riders')" :subtitle="t('Assign deliveries, record what happened, and receive the cash riders collected.')" />
    <section class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="r in riders" :key="r.id" class="panel flex items-center gap-3 p-3">
            <div class="flex-1">
                <div class="font-medium">{{ r.name }}</div>
                <div class="text-[12.5px] text-ink-500">{{ r.phone }} · {{ r.active }} {{ t('on the way') }}</div>
                <div class="mt-1 text-sm">{{ t('Cash with rider') }}: <b class="tabular-nums" :class="Number(r.cash_with_rider) > 0 ? 'text-haldi-800' : ''">{{ money(r.cash_with_rider) }}</b></div>
            </div>
            <Button v-if="can('sales.riders.handover') && Number(r.cash_with_rider) > 0" size="sm" variant="primary" @click="openHandover(r)">{{ t('Receive cash') }}</Button>
        </div>
        <p v-if="!riders.length" class="panel p-4 text-sm text-ink-500 sm:col-span-2">{{ t('No riders yet. Add an employee with the job type “Rider” in HR.') }}</p>
    </section>

    <div class="grid gap-4 xl:grid-cols-4">
        <section v-for="c in columns" :key="c.key" class="panel">
            <div class="panel-head"><h2>{{ t(c.label) }}</h2><span class="text-sm tabular-nums text-ink-500">{{ grouped[c.key].length }}</span></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="d in grouped[c.key]" :key="d.id" class="space-y-1 p-3 text-sm">
                    <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ d.number }}</span><Badge :status="d.status" /></div>
                    <div>{{ d.sales_order?.customer?.name }} · <a v-if="d.sales_order" :href="route('sales.orders.show', d.sales_order.id)" class="link">{{ d.sales_order.number }}</a></div>
                    <div class="text-[12.5px] text-ink-600">{{ d.sales_order?.delivery_address }} {{ d.sales_order?.delivery_area }} · {{ d.sales_order?.delivery_phone }}</div>
                    <div class="text-[12.5px]">{{ t('Collect') }}: <b class="tabular-nums">{{ money(d.cod_amount) }}</b><span v-if="d.collected_amount !== null && c.key === 'done'"> · {{ t('collected') }} {{ money(d.collected_amount) }}</span></div>
                    <div v-if="d.failure_reason" class="text-[12.5px] text-ledger-red">{{ d.failure_reason }}</div>
                    <div v-if="c.key !== 'done'" class="flex items-center gap-2 pt-1">
                        <select class="input flex-1 py-1 text-sm" :value="d.rider_id ?? ''" @change="assign(d, ($event.target as HTMLSelectElement).value)">
                            <option value="">{{ t('Choose rider') }}</option><option v-for="r in riders" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <Button v-if="d.rider_id" size="sm" @click="openOutcome(d)">{{ t('Result') }}</Button>
                    </div>
                    <div v-else class="text-[12px] text-ink-500">{{ d.rider?.name }} · {{ dateTime(d.delivered_at ?? d.updated_at) }}</div>
                </li>
                <li v-if="!grouped[c.key].length" class="p-4 text-center text-sm text-ink-400">—</li>
            </ul>
        </section>
    </div>

    <section class="panel mt-5">
        <div class="panel-head"><h2>{{ t('Cash received from riders') }}</h2></div>
        <table class="table-base">
            <thead><tr><th>{{ t('Number') }}</th><th>{{ t('When') }}</th><th>{{ t('Rider') }}</th><th class="num">{{ t('Expected') }}</th><th class="num">{{ t('Received') }}</th><th class="num">{{ t('Difference') }}</th><th>{{ t('Received by') }}</th></tr></thead>
            <tbody>
                <tr v-for="h in handovers" :key="h.id">
                    <td>{{ h.number }}</td><td>{{ dateTime(h.received_at) }}</td><td>{{ h.rider?.name }}</td><td class="num">{{ money(h.expected_amount) }}</td><td class="num">{{ money(h.received_amount) }}</td>
                    <td class="num" :class="Number(h.variance) < 0 ? 'text-ledger-red' : ''">{{ money(h.variance) }}</td><td>{{ h.receiver?.name }}</td>
                </tr>
                <tr v-if="!handovers.length"><td colspan="7" class="py-6 text-center text-ink-500">{{ t('Nothing yet.') }}</td></tr>
            </tbody>
        </table>
    </section>

    <Modal :open="!!outcome" :title="`${t('Delivery')} ${outcome?.number ?? ''}`" size="sm" @close="outcome = null">
        <div class="space-y-3">
            <div class="flex gap-2 text-sm">
                <label v-for="o in [['delivered', 'Delivered'], ['failed', 'Not delivered'], ['returned', 'Goods returned']]" :key="o[0]" class="flex flex-1 items-center justify-center gap-1 rounded border px-2 py-2" :class="outcomeForm.outcome === o[0] ? 'border-haldi-500 bg-haldi-50' : 'border-ink-200'"><input v-model="outcomeForm.outcome" type="radio" :value="o[0]" class="sr-only" />{{ t(o[1]) }}</label>
            </div>
            <Field v-if="outcomeForm.outcome === 'delivered'" label="Cash collected" :error="outcomeForm.errors.collected"><input v-model="outcomeForm.collected" type="number" min="0" class="input text-end" /></Field>
            <Field v-else label="Reason" :error="outcomeForm.errors.reason" required><input v-model="outcomeForm.reason" class="input" :placeholder="t('Customer not home, refused …')" /></Field>
            <Field label="Note"><input v-model="outcomeForm.note" class="input" /></Field>
        </div>
        <template #footer><Button @click="outcome = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="outcomeForm.processing" @click="saveOutcome">{{ t('Save') }}</Button></template>
    </Modal>
    <Modal :open="!!handover" :title="`${t('Receive cash from')} ${handover?.name ?? ''}`" size="sm" @close="handover = null">
        <div class="space-y-3">
            <p class="text-sm">{{ t('Expected') }}: <b class="tabular-nums">{{ money(handover?.cash_with_rider) }}</b></p>
            <Field label="Counted cash" :error="handoverForm.errors.received" required><input v-model="handoverForm.received" type="number" min="0" class="input text-end text-lg" /></Field>
            <Field label="Note"><input v-model="handoverForm.note" class="input" /></Field>
            <p v-if="!openShift" class="text-[12.5px] text-haldi-800">{{ t('You have no open shift; the cash is recorded without a shift.') }}</p>
        </div>
        <template #footer><Button @click="handover = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="handoverForm.processing" @click="saveHandover">{{ t('Receive') }}</Button></template>
    </Modal>
</template>
