<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, RotateCcw } from 'lucide-vue-next';

interface RefundableLine {
    id: number;
    description: string;
    variant_id: number | null;
    original_quantity: number;
    already_refunded: number;
    refundable_quantity: number;
    unit_price: string;
    tax_id: number | null;
    tax_rate: string | null;
    tax_type: 'percentage' | 'fixed' | null;
}

interface DocumentData {
    id: number;
    number: string;
    total: string;
    contact: { name: string } | null;
}

const props = defineProps<{
    document: DocumentData;
    refundableLines: RefundableLine[];
    totalPaid: number;
    totalAlreadyRefunded: number;
}>();

// Which lines are checked, and how much of each to refund (defaults to
// the full refundable amount, editable down for partial returns).
const selectedQuantities = ref<Record<number, string>>(
    Object.fromEntries(props.refundableLines.map((l) => [l.id, String(l.refundable_quantity)]))
);
const selectedLines = ref<Record<number, boolean>>(
    Object.fromEntries(props.refundableLines.map((l) => [l.id, false]))
);

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function lineRefundAmount(line: RefundableLine): number {
    const qty = Number(selectedQuantities.value[line.id]) || 0;
    const gross = qty * Number(line.unit_price);
    let tax = 0;
    if (line.tax_rate) {
        tax = line.tax_type === 'percentage'
            ? gross * (Number(line.tax_rate) / 100)
            : Number(line.tax_rate) * (qty / line.original_quantity);
    }
    return gross + tax;
}

const estimatedRefundTotal = computed(() =>
    props.refundableLines
        .filter((l) => selectedLines.value[l.id])
        .reduce((sum, l) => sum + lineRefundAmount(l), 0)
);

const hasSelection = computed(() => Object.values(selectedLines.value).some(Boolean));

const refundMethod = ref<'cash' | 'card' | 'bank_transfer' | 'other'>('cash');
const reason = ref('');
const isSubmitting = ref(false);
const errorMessage = ref<string | null>(null);

async function submitRefund() {
    if (!hasSelection.value) return;
    isSubmitting.value = true;
    errorMessage.value = null;

    const lines = props.refundableLines
        .filter((l) => selectedLines.value[l.id])
        .map((l) => ({
            document_line_id: l.id,
            quantity: Number(selectedQuantities.value[l.id]),
        }));

    router.post(
        route('pos.refund.store', { document: props.document.id }),
        { lines, refund_method: refundMethod.value, reason: reason.value || undefined },
        {
            onError: (errors) => {
                errorMessage.value = Object.values(errors)[0] as string ?? 'Could not process the refund.';
                isSubmitting.value = false;
            },
        }
    );
}
</script>

<template>
    <Head :title="`Refund — ${document.number}`" />

    <DashboardLayout>
        <div class="mx-auto max-w-3xl">
            <Link :href="route('pos.history.show', { document: document.id })" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to order
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">Refund — {{ document.number }}</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                {{ document.contact?.name ?? 'Walk-in customer' }} · Paid Rs {{ money(totalPaid) }}
                <span v-if="totalAlreadyRefunded > 0"> · Already refunded Rs {{ money(totalAlreadyRefunded) }}</span>
            </p>

            <div v-if="refundableLines.length === 0" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center">
                <p class="font-sans text-sm text-slate-400">Every item on this order has already been fully refunded.</p>
            </div>

            <div v-else class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3"></th>
                            <th class="px-4 py-3 font-medium text-slate-500">Item</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Bought</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Refund qty</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Refund amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in refundableLines" :key="line.id" class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    v-model="selectedLines[line.id]"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                />
                            </td>
                            <td class="px-4 py-3 text-ink-900">
                                {{ line.description }}
                                <span v-if="line.already_refunded > 0" class="block text-xs text-slate-400">
                                    {{ line.already_refunded }} already refunded
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-500">{{ line.original_quantity }}</td>
                            <td class="px-4 py-3 text-right">
                                <input
                                    v-model="selectedQuantities[line.id]"
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    :max="line.refundable_quantity"
                                    :disabled="!selectedLines[line.id]"
                                    class="w-20 rounded border border-slate-200 text-right font-sans text-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-300"
                                />
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-ink-900">
                                Rs {{ money(selectedLines[line.id] ? lineRefundAmount(line) : 0) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="refundableLines.length > 0" class="mt-6 grid grid-cols-2 gap-6">
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Refund method
                    </h2>
                    <select
                        v-model="refundMethod"
                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    >
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="other">Other</option>
                    </select>

                    <label class="mb-1 mt-4 block font-sans text-xs font-medium text-slate-500">Reason (optional)</label>
                    <textarea
                        v-model="reason"
                        rows="2"
                        placeholder="e.g. Customer changed their mind, defective item..."
                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Summary
                    </h2>
                    <div class="flex justify-between font-sans text-lg font-bold text-ink-900">
                        <span>Refund total</span>
                        <span>Rs {{ money(estimatedRefundTotal) }}</span>
                    </div>
                    <p v-if="errorMessage" class="mt-2 font-sans text-xs text-danger-500">{{ errorMessage }}</p>

                    <button
                        @click="submitRefund"
                        :disabled="!hasSelection || isSubmitting"
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-danger-500 py-2.5 font-sans text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:opacity-40"
                    >
                        <RotateCcw :size="16" /> Issue refund
                    </button>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>