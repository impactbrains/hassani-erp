<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import { useFilters } from '@/composables/useFilters';
import { useOptions } from '@/composables/useOptions';
import { dateTime, money } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * Easypaisa, JazzCash, card and bank payments are checked by hand against the
 * wallet or bank statement. Rejecting puts the amount back on the customer's Khata.
 */
const props = defineProps<{ payments: any; filters: Record<string, any> }>();
const filters = useFilters('sales.payments.verify', { status: props.filters.status ?? 'pending', method: props.filters.method ?? '' });
const options = useOptions(['payment_methods']);
const rejecting = ref<any>(null);
const reason = ref('');
function verify(p: any) {
    router.post(route('sales.payments.decide', p.id), { decision: 'verify' }, { preserveScroll: true });
}
function reject() {
    router.post(route('sales.payments.decide', rejecting.value.id), { decision: 'reject', reason: reason.value }, { preserveScroll: true, onSuccess: () => { rejecting.value = null; reason.value = ''; } });
}
</script>

<template>
    <PageHeader :title="t('Verify payments')" :subtitle="t('Open the Easypaisa / JazzCash app or bank statement and tick each payment you can see.')" />
    <Tabs :model-value="filters.status" :tabs="[{ key: 'pending', label: 'Waiting' }, { key: 'verified', label: 'Verified' }, { key: 'rejected', label: 'Rejected' }]" @update:model-value="(v) => (filters.status = v)" />
    <div class="panel mb-4 flex gap-3 p-3">
        <select v-model="filters.method" class="input max-w-[14rem]"><option value="">{{ t('All methods') }}</option><option v-for="m in (options.payment_methods ?? []).filter((m: any) => m.requires_verification)" :key="m.id" :value="m.id">{{ localName(m) }}</option></select>
    </div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('When') }}</th><th>{{ t('Method') }}</th><th>{{ t('Transaction ID') }}</th><th>{{ t('Customer') }}</th><th class="num">{{ t('Amount') }}</th><th>{{ t('Taken by') }}</th><th>{{ t('Store') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="p in payments.data" :key="p.id">
                    <td class="whitespace-nowrap">{{ dateTime(p.received_at) }}</td><td>{{ p.method?.name }}<div v-if="p.terminal_label" class="text-[12px] text-ink-500">{{ p.terminal_label }}</div></td>
                    <td class="font-mono text-[13px]">{{ p.reference ?? '—' }}</td><td>{{ p.contact?.name ?? t('Walk-in') }}</td><td class="num font-semibold">{{ money(p.amount) }}</td>
                    <td>{{ p.receiver?.name }}</td><td>{{ p.store?.name }}</td>
                    <td class="whitespace-nowrap">
                        <template v-if="filters.status === 'pending'"><Button size="sm" variant="primary" @click="verify(p)">{{ t('Seen, verify') }}</Button> <Button size="sm" variant="danger" @click="rejecting = p">{{ t('Not received') }}</Button></template>
                        <span v-else class="text-[12.5px] text-ink-500">{{ p.verifier?.name }} · {{ dateTime(p.verified_at) }}</span>
                    </td>
                </tr>
                <tr v-if="!payments.data.length"><td colspan="8" class="py-8 text-center text-ink-500">{{ filters.status === 'pending' ? t('Nothing waiting. All payments are checked.') : t('Nothing here.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="payments" />
    </section>
    <Modal :open="!!rejecting" :title="t('Payment not received')" size="sm" @close="rejecting = null">
        <p class="mb-3 text-sm">{{ money(rejecting?.amount) }} · {{ rejecting?.reference }} — {{ t('the amount goes back on the customer’s Khata and the cashier is flagged in the audit report.') }}</p>
        <label class="block"><span class="field-label">{{ t('Reason') }}</span><input v-model="reason" class="input" /></label>
        <template #footer><Button @click="rejecting = null">{{ t('Cancel') }}</Button><Button variant="danger" :disabled="!reason" @click="reject">{{ t('Reject') }}</Button></template>
    </Modal>
</template>
