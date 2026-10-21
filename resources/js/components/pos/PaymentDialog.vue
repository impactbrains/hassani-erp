<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Banknote, CreditCard, Smartphone, Landmark, Trash2 } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import { money, round2 } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * Taking payment. Any mix of cash, wallets (Easypaisa, JazzCash ...), card
 * and bank transfer; whatever is not paid goes on the customer's Khata when
 * a customer is chosen and the cashier may sell on credit. Cash shows the
 * change to give. Wallet and card payments need the transaction reference
 * and are verified against the statement later.
 */
const props = defineProps<{ open: boolean; total: string; methods: any[]; customer: any | null; canCredit: boolean; busy: boolean; error?: string | null; shortages?: { variant_id: number; name: string; needs: string; available: string }[] }>();
const emit = defineEmits<{ close: []; pay: [payments: any[]] }>();

interface Tender { method: any; amount: string; tendered: string; reference: string; terminal_label: string }
const tenders = ref<Tender[]>([]);
const firstInput = ref<HTMLInputElement[] | null>(null);

const totalNum = computed(() => Number(props.total));
const paid = computed(() => round2(tenders.value.reduce((s, x) => s + (Number(x.amount) || 0), 0)));
const remaining = computed(() => round2(totalNum.value - paid.value));
const cash = computed(() => tenders.value.find((x) => x.method.type === 'cash'));
const change = computed(() => (cash.value && Number(cash.value.tendered) > 0 ? round2(Number(cash.value.tendered) - Number(cash.value.amount)) : 0));
const onKhata = computed(() => (remaining.value > 0 && props.customer && props.canCredit ? remaining.value : 0));
const missingRef = computed(() => tenders.value.some((x) => x.method.requires_reference && Number(x.amount) > 0 && !x.reference.trim()));
// Negative stock is never allowed: while any item is short, the sale cannot be completed.
const hasShortages = computed(() => (props.shortages?.length ?? 0) > 0);
const canPay = computed(() => !props.busy && !hasShortages.value && !missingRef.value && remaining.value >= 0 && (remaining.value === 0 || onKhata.value > 0) && change.value >= 0);
const quickCash = computed(() => {
    const amount = totalNum.value;
    return [...new Set([amount, Math.ceil(amount / 100) * 100, Math.ceil(amount / 500) * 500, Math.ceil(amount / 1000) * 1000, Math.ceil(amount / 5000) * 5000])].filter((v) => v >= amount).slice(0, 4);
});

watch(() => props.open, (open) => {
    if (!open) return;
    const cashMethod = props.methods.find((m) => m.type === 'cash');
    tenders.value = cashMethod ? [{ method: cashMethod, amount: props.total, tendered: '', reference: '', terminal_label: '' }] : [];
    nextTick(() => firstInput.value?.[0]?.focus());
});

function icon(type: string) {
    return { cash: Banknote, card: CreditCard, wallet: Smartphone, bank: Landmark }[type] ?? Banknote;
}
function add(method: any) {
    const existing = tenders.value.find((x) => x.method.id === method.id);
    if (existing) return;
    const amount = Math.max(0, remaining.value);
    tenders.value.push({ method, amount: amount ? String(amount) : '', tendered: '', reference: '', terminal_label: method.terminal_labels?.[0] ?? '' });
}
function only(method: any) {
    tenders.value = [{ method, amount: props.total, tendered: '', reference: '', terminal_label: method.terminal_labels?.[0] ?? '' }];
}
function submit() {
    if (!canPay.value) return;
    emit('pay', tenders.value.filter((x) => Number(x.amount) > 0).map((x) => ({
        payment_method_id: x.method.id, amount: String(x.amount), tendered: x.method.type === 'cash' && x.tendered ? String(x.tendered) : null,
        reference: x.reference || null, terminal_label: x.terminal_label || null,
    })));
}
</script>

<template>
    <Modal :open="open" :title="t('Take payment')" size="lg" @close="emit('close')">
        <form @submit.prevent="submit">
            <div v-if="hasShortages" class="mb-4 rounded-lg border border-ledger-red/40 bg-ledger-redSoft px-4 py-3 text-ledger-red" role="alert">
                <div class="font-semibold">{{ t('Not enough stock for these items. Go back to the cart and lower the quantity, or remove them.') }}</div>
                <ul class="mt-1.5 space-y-0.5 text-[14.5px]">
                    <li v-for="x in shortages" :key="x.variant_id">{{ x.name }}: {{ t('needs :needs, only :have', { needs: x.needs, have: x.available }) }}</li>
                </ul>
            </div>
            <div class="mb-4 flex items-baseline justify-between rounded-lg bg-ink-800 px-5 py-4 text-white">
                <span class="text-ink-200">{{ t('Total') }}</span>
                <span class="text-4xl font-semibold tabular-nums">{{ money(total) }}</span>
            </div>

            <div class="mb-4 flex flex-wrap gap-2">
                <button v-for="m in methods" :key="m.id" type="button" class="flex items-center gap-2 rounded border px-3 py-2 text-sm font-medium" :class="tenders.some((x) => x.method.id === m.id) ? 'border-ink-800 bg-ink-50' : 'border-ink-200 hover:bg-ink-50'" @click="add(m)" @dblclick="only(m)">
                    <component :is="icon(m.type)" class="h-4 w-4 text-ink-500" />{{ localName(m) }}
                </button>
            </div>

            <div class="space-y-3">
                <div v-for="(x, i) in tenders" :key="x.method.id" class="rounded-lg border border-ink-100 p-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="w-36 pb-2 font-medium">{{ localName(x.method) }}</div>
                        <label class="flex-1">
                            <span class="field-label">{{ t('Amount') }}</span>
                            <input ref="firstInput" v-model="x.amount" type="number" step="0.01" min="0" inputmode="decimal" class="input text-end text-lg tabular-nums" />
                        </label>
                        <label v-if="x.method.type === 'cash'" class="flex-1">
                            <span class="field-label">{{ t('Cash given') }}</span>
                            <input v-model="x.tendered" type="number" step="1" min="0" inputmode="decimal" class="input text-end text-lg tabular-nums" />
                        </label>
                        <label v-if="x.method.requires_reference" class="flex-1">
                            <span class="field-label">{{ t('Transaction ID') }} *</span>
                            <input v-model="x.reference" class="input" :class="!x.reference && Number(x.amount) > 0 ? 'border-haldi-500' : ''" autocomplete="off" />
                        </label>
                        <label v-if="x.method.terminal_labels?.length" class="w-36">
                            <span class="field-label">{{ t('Machine') }}</span>
                            <select v-model="x.terminal_label" class="input"><option v-for="l in x.method.terminal_labels" :key="l">{{ l }}</option></select>
                        </label>
                        <button type="button" class="mb-1.5 rounded p-1.5 text-ink-400 hover:bg-ledger-redSoft hover:text-ledger-red" :aria-label="t('Remove')" @click="tenders.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
                    </div>
                    <div v-if="x.method.type === 'cash'" class="mt-2 flex flex-wrap gap-1.5">
                        <button v-for="v in quickCash" :key="v" type="button" class="rounded border border-ink-200 px-2.5 py-1 text-[13px] tabular-nums hover:bg-ink-50" @click="x.tendered = String(v)">{{ money(v) }}</button>
                    </div>
                    <p v-if="x.method.requires_verification" class="mt-2 text-[12.5px] text-ink-500">{{ t('Check the payment arrived on the phone/statement. It is marked “to verify” until a manager matches it.') }}</p>
                </div>
            </div>

            <dl class="mt-4 space-y-1 text-[15px]">
                <div class="flex justify-between"><dt class="text-ink-600">{{ t('Paid now') }}</dt><dd class="tabular-nums">{{ money(paid) }}</dd></div>
                <div v-if="change > 0" class="flex justify-between text-xl font-semibold text-ledger-green"><dt>{{ t('Change to give') }}</dt><dd class="tabular-nums">{{ money(change) }}</dd></div>
                <div v-if="change < 0" class="flex justify-between font-medium text-ledger-red"><dt>{{ t('Cash given is less than the cash amount') }}</dt></div>
                <div v-if="onKhata > 0" class="flex justify-between font-semibold text-ledger-red"><dt>{{ t('Goes on Khata of') }} {{ localName(customer) }}</dt><dd class="tabular-nums">{{ money(onKhata) }}</dd></div>
                <div v-else-if="remaining > 0" class="rounded bg-haldi-50 px-3 py-2 text-sm text-haldi-900">
                    {{ customer ? t('You are not allowed to sell on Khata. Take the full amount.') : t('Rs :amount still to pay. To put it on Khata, choose the customer first.', { amount: remaining.toLocaleString('en-PK') }) }}
                </div>
                <div v-if="remaining < 0" class="text-sm text-ledger-red">{{ t('Payments add up to more than the total. For change, use “Cash given”.') }}</div>
            </dl>
            <p v-if="error" class="mt-3 rounded bg-ledger-redSoft px-3 py-2 text-sm text-ledger-red" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <Button @click="emit('close')">{{ t('Back to cart') }}</Button>
            <Button variant="primary" size="lg" :disabled="!canPay" :loading="busy" @click="submit">{{ t('Complete sale') }} <kbd class="rounded bg-ink-900/10 px-1.5 text-[11px]">Enter</kbd></Button>
        </template>
    </Modal>
</template>
