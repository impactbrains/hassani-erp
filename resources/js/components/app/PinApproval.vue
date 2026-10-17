<script setup lang="ts">
import { ref, watch } from 'vue';
import { ShieldCheck } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import { ApiError, get, post } from '@/lib/http';
import { t } from '@/lib/i18n';

/**
 * A manager approves on the cashier's screen with their PIN. The server
 * returns a single-use token for this action and store, valid ten minutes.
 */
const props = defineProps<{ open: boolean; action: string | null; reason?: string; context?: Record<string, unknown> }>();
const emit = defineEmits<{ close: []; approved: [token: string, approver: string] }>();

const LABELS: Record<string, string> = {
    discount: 'Approve the discount', price_override: 'Approve the price change', refund: 'Approve the refund', payout: 'Approve the payout',
    no_sale: 'Approve opening the drawer', credit_limit: 'Approve going over the Khata limit', cash_variance: 'Approve the cash difference',
};
const approvers = ref<{ id: number; name: string }[]>([]);
const approverId = ref<number | null>(null);
const pin = ref('');
const error = ref('');
const busy = ref(false);
const pinInput = ref<HTMLInputElement | null>(null);

watch(() => [props.open, props.action], async () => {
    if (!props.open || !props.action) return;
    pin.value = '';
    error.value = '';
    approvers.value = await get(route('approvals.approvers', { action: props.action }));
    approverId.value = approvers.value[0]?.id ?? null;
    setTimeout(() => pinInput.value?.focus(), 50);
});

async function submit() {
    if (!approverId.value || !pin.value) return;
    busy.value = true;
    error.value = '';
    try {
        const res = await post(route('approvals.store'), { action: props.action, approver_id: approverId.value, pin: pin.value, context: props.context ?? {}, reason: props.reason });
        emit('approved', res.token, res.approved_by);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('Something went wrong. Try again.');
        pin.value = '';
        pinInput.value?.focus();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="t(LABELS[action ?? ''] ?? 'Manager approval')" size="sm" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <p v-if="reason" class="rounded bg-haldi-50 px-3 py-2 text-sm text-haldi-900">{{ reason }}</p>
            <p v-if="!approvers.length" class="text-sm text-ledger-red">{{ t('No manager in this store has set an approval PIN yet. A manager can set one under My account.') }}</p>
            <label v-else class="block">
                <span class="field-label">{{ t('Manager') }}</span>
                <select v-model="approverId" class="input"><option v-for="a in approvers" :key="a.id" :value="a.id">{{ a.name }}</option></select>
            </label>
            <label class="block">
                <span class="field-label">{{ t('PIN') }}</span>
                <input ref="pinInput" v-model="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8" class="input text-center text-2xl tracking-[0.5em]" />
            </label>
            <p v-if="error" class="text-sm text-ledger-red" role="alert">{{ error }}</p>
            <Button type="submit" variant="ink" size="lg" class="w-full" :loading="busy" :disabled="!approvers.length"><ShieldCheck class="h-5 w-5" />{{ t('Approve') }}</Button>
        </form>
    </Modal>
</template>
