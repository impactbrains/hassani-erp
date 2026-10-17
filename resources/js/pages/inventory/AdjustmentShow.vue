<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { date, dateTime, money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ adjustment: any; canApprove: boolean; showCost: boolean }>();
const reason = ref('');
const kinds: Record<string, string> = { opening: 'Opening stock', write_off: 'Write-off', adjustment: 'Correction', quarantine: 'Quarantine', release: 'Release from quarantine' };
function decide(decision: 'approve' | 'reject') {
    router.post(route('inventory.adjustments.decide', props.adjustment.id), { decision, reason: reason.value || null }, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="`${t(kinds[adjustment.kind] ?? adjustment.kind)} ${adjustment.number}`" :subtitle="`${adjustment.store?.name} · ${date(adjustment.business_date ?? adjustment.created_at)}`">
        <template #actions><Badge :status="adjustment.status" /><Button :href="route('inventory.adjustments.index')">{{ t('All adjustments') }}</Button></template>
    </PageHeader>
    <section class="panel mb-5 grid gap-4 p-4 text-sm md:grid-cols-4">
        <div><div class="field-label">{{ t('Reason') }}</div>{{ adjustment.reason_code?.label ?? '—' }}</div>
        <div><div class="field-label">{{ t('Requested by') }}</div>{{ adjustment.requester?.name }} · {{ dateTime(adjustment.created_at) }}</div>
        <div><div class="field-label">{{ t('Approved by') }}</div>{{ adjustment.approver?.name ?? '—' }}<span v-if="adjustment.second_approver"> + {{ adjustment.second_approver.name }}</span></div>
        <div v-if="showCost"><div class="field-label">{{ t('Value') }}</div><b class="tabular-nums">{{ money(adjustment.total_value) }}</b></div>
        <p v-if="adjustment.note" class="md:col-span-4 whitespace-pre-line text-ink-700">{{ adjustment.note }}</p>
    </section>
    <section v-if="canApprove" class="panel mb-5 flex flex-wrap items-end gap-3 border-haldi-300 bg-haldi-50 p-4">
        <p class="w-full text-sm font-medium">{{ t('This adjustment is waiting for your approval.') }}</p>
        <label class="flex-1"><span class="field-label">{{ t('Reason (needed to reject)') }}</span><input v-model="reason" class="input" /></label>
        <Button variant="danger" :disabled="!reason" @click="decide('reject')">{{ t('Reject') }}</Button>
        <Button variant="primary" @click="decide('approve')">{{ t('Approve and post') }}</Button>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th>{{ t('Location') }}</th><th>{{ t('Batch') }}</th><th class="num">{{ t('Quantity') }}</th><th v-if="showCost" class="num">{{ t('Cost per unit') }}</th><th v-if="showCost" class="num">{{ t('Value') }}</th></tr></thead>
            <tbody>
                <tr v-for="l in adjustment.lines" :key="l.id">
                    <td><div class="font-medium">{{ l.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ l.variant?.sku }}</div></td>
                    <td>{{ l.location?.name }}</td>
                    <td>{{ l.batch_no ?? '—' }}<span v-if="l.expiry_date" class="text-[12px] text-ink-500"> · {{ date(l.expiry_date) }}</span></td>
                    <td class="num" :class="Number(l.quantity) < 0 ? 'text-ledger-red' : 'text-ledger-green'">{{ Number(l.quantity) > 0 ? '+' : '' }}{{ qty(l.quantity) }}</td>
                    <td v-if="showCost" class="num">{{ l.unit_cost !== null ? money(l.unit_cost) : '—' }}</td>
                    <td v-if="showCost" class="num">{{ l.unit_cost !== null ? money(Math.abs(Number(l.quantity) * Number(l.unit_cost))) : '—' }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
