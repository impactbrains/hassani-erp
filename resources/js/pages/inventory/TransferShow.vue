<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { useOptions } from '@/composables/useOptions';
import { dateTime, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; transfer: any; can: { approve: boolean; dispatch: boolean; receive: boolean } }>();
const options = useOptions(['reasons:transfer_discrepancy']);
const s = computed(() => props.transfer.status);
const entry = reactive<Record<number, { quantity: string; reason_id: number | null; note: string }>>({});
props.transfer.lines.forEach((l: any) => {
    entry[l.id] = { quantity: String(s.value === 'dispatched' ? l.quantity_dispatched : l.quantity_requested), reason_id: null, note: '' };
});
const cancelReason = ref('');
const busy = ref(false);

function act(action: string) {
    busy.value = true;
    const lines = props.transfer.lines.map((l: any) => ({ id: l.id, ...entry[l.id] }));
    router.post(route('inventory.transfers.action', props.transfer.id), { action, lines, reason: cancelReason.value || null }, { preserveScroll: true, onFinish: () => (busy.value = false) });
}
const short = (l: any) => s.value === 'dispatched' && Number(entry[l.id]?.quantity) < Number(l.quantity_dispatched);
</script>

<template>
    <PageHeader :pager="pager" :title="`${t('Transfer')} ${transfer.number}`" :subtitle="`${transfer.from_store?.name} · ${transfer.from_location?.name} → ${transfer.to_store?.name} · ${transfer.to_location?.name}`">
        <template #actions><Badge :status="transfer.status" /><Button :href="route('inventory.transfers.index')">{{ t('All transfers') }}</Button></template>
    </PageHeader>
    <section class="panel mb-5 grid gap-4 p-4 text-sm md:grid-cols-4">
        <div><div class="field-label">{{ t('Requested by') }}</div>{{ transfer.requester?.name }} · {{ dateTime(transfer.created_at) }}</div>
        <div><div class="field-label">{{ t('Approved') }}</div>{{ transfer.approved_at ? dateTime(transfer.approved_at) : '—' }}</div>
        <div><div class="field-label">{{ t('Dispatched') }}</div>{{ transfer.dispatched_at ? dateTime(transfer.dispatched_at) : '—' }}</div>
        <div><div class="field-label">{{ t('Received') }}</div>{{ transfer.received_at ? dateTime(transfer.received_at) : '—' }}</div>
        <p v-if="transfer.note" class="whitespace-pre-line text-ink-700 md:col-span-4">{{ transfer.note }}</p>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead>
                <tr><th>{{ t('Product') }}</th><th class="num">{{ t('Requested') }}</th><th class="num">{{ t('Sent') }}</th><th class="num">{{ t('Received') }}</th>
                    <th v-if="(s === 'approved' && can.dispatch) || (s === 'dispatched' && can.receive)" class="w-32">{{ s === 'approved' ? t('Sending now') : t('Received now') }}</th>
                    <th v-if="s === 'dispatched' && can.receive">{{ t('If short: why') }}</th></tr>
            </thead>
            <tbody>
                <tr v-for="l in transfer.lines" :key="l.id">
                    <td><div class="font-medium">{{ l.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ l.variant?.sku }}</div></td>
                    <td class="num">{{ qty(l.quantity_requested) }}</td>
                    <td class="num">{{ l.quantity_dispatched !== null ? qty(l.quantity_dispatched) : '—' }}</td>
                    <td class="num" :class="l.quantity_received !== null && Number(l.quantity_received) < Number(l.quantity_dispatched) ? 'text-ledger-red' : ''">{{ l.quantity_received !== null ? qty(l.quantity_received) : '—' }}</td>
                    <td v-if="(s === 'approved' && can.dispatch) || (s === 'dispatched' && can.receive)"><input v-model="entry[l.id].quantity" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                    <td v-if="s === 'dispatched' && can.receive">
                        <div v-if="short(l)" class="flex gap-2">
                            <select v-model="entry[l.id].reason_id" class="input py-1 text-sm"><option :value="null">—</option><option v-for="r in options['reasons:transfer_discrepancy'] ?? []" :key="r.id" :value="r.id">{{ localName(r) }}</option></select>
                            <input v-model="entry[l.id].note" class="input py-1 text-sm" :placeholder="t('Note')" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 p-3">
            <template v-if="['draft', 'requested', 'approved'].includes(s) && (can.dispatch || can.approve)">
                <input v-model="cancelReason" class="input max-w-xs py-1.5" :placeholder="t('Reason for cancelling')" />
                <Button variant="danger" :disabled="!cancelReason" :loading="busy" @click="act('cancel')">{{ s === 'requested' ? t('Reject') : t('Cancel transfer') }}</Button>
            </template>
            <div class="ms-auto flex gap-2">
                <Button v-if="s === 'requested' && can.approve" variant="primary" :loading="busy" @click="act('approve')">{{ t('Approve') }}</Button>
                <Button v-if="s === 'approved' && can.dispatch" variant="primary" :loading="busy" @click="act('dispatch')">{{ t('Send the goods') }}</Button>
                <Button v-if="s === 'dispatched' && can.receive" variant="primary" :loading="busy" @click="act('receive')">{{ t('Confirm received') }}</Button>
            </div>
        </div>
    </section>
</template>
