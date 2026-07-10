<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, Plus, X, Printer, FileText, Check } from 'lucide-vue-next';

interface DocumentLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    tax_amount: string;
    discount_amount: string;
    line_total: string;
}

interface DocumentData {
    id: number;
    number: string;
    status: string;
    contact: { id: number; name: string } | null;
    location: { id: number; name: string } | null;
    subtotal: string;
    tax_total: string;
    discount_type: 'percentage' | 'fixed' | null;
    discount_value: string | null;
    total: string;
    lines: DocumentLine[];
}

const props = defineProps<{
    document: DocumentData;
}>();

interface PaymentEntry {
    method: 'cash' | 'card' | 'bank_transfer' | 'other';
    amount: string;
    reference: string;
}

const payments = ref<PaymentEntry[]>([
    { method: 'cash', amount: props.document.total, reference: '' },
]);

function addPaymentRow() {
    payments.value.push({ method: 'cash', amount: '0', reference: '' });
}

function removePaymentRow(index: number) {
    if (payments.value.length === 1) return;
    payments.value.splice(index, 1);
}

const totalEntered = computed(() =>
    payments.value.reduce((sum, p) => sum + (Number(p.amount) || 0), 0)
);

const remaining = computed(() => Number(props.document.total) - totalEntered.value);
const changeDue = computed(() => Math.max(0, -remaining.value));
const isFullyCovered = computed(() => remaining.value <= 0.001);

// Only relevant when the covering payment includes cash — change is given
// in cash regardless of what other methods were also used.
const hasCashPayment = computed(() => payments.value.some((p) => p.method === 'cash'));

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function orderDiscountLabel(): string {
    if (props.document.discount_type === 'percentage') {
        const amount = Number(props.document.subtotal) * (Number(props.document.discount_value ?? 0) / 100);
        return `Rs ${money(amount)} (${props.document.discount_value}%)`;
    }
    return `Rs ${money(props.document.discount_value ?? 0)}`;
}

// Display-only — backend stores/calculates at full precision so a typed
// price round-trips exactly, but the cart/receipt/invoice only need to
// show up to 3 decimals for readability.
function displayQty(value: string | number): string {
    const num = Number(value);
    return num.toFixed(3).replace(/\.?0+$/, '') || '0';
}

function jsonHeaders(): HeadersInit {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
    };
}

const isProcessing = ref(false);
const errorMessage = ref<string | null>(null);
const completedDocument = ref<DocumentData | null>(null);

async function confirmSale(): Promise<boolean> {
    if (completedDocument.value) return true; // already confirmed, don't double-submit

    isProcessing.value = true;
    errorMessage.value = null;

    try {
        const response = await fetch(route('pos.checkout', { document: props.document.id }), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                payments: payments.value.map((p) => ({
                    method: p.method,
                    amount: p.amount,
                    reference: p.reference || null,
                })),
            }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            errorMessage.value = data?.errors?.payments?.[0] ?? data?.message ?? 'Could not complete the sale.';
            return false;
        }

        completedDocument.value = await response.json();
        return true;
    } catch {
        errorMessage.value = 'Something went wrong — check your connection and try again.';
        return false;
    } finally {
        isProcessing.value = false;
    }
}

async function continueWithoutPrinting() {
    const success = await confirmSale();
    if (success) {
        router.visit(route('pos.index'));
    }
}

async function printReceipt() {
    const success = await confirmSale();
    if (success) {
        window.open(route('pos.history.receipt', { document: props.document.id }), '_blank');
        // Deliberately stays on the checkout/confirmation page — only
        // "Complete sale" navigates back to POS. This lets the cashier
        // reprint or open the invoice afterward without losing this screen.
    }
}

async function printInvoice() {
    const success = await confirmSale();
    if (success) {
        window.open(route('pos.history.invoice', { document: props.document.id }), '_blank');
    }
}
</script>

<template>
    <Head :title="`Checkout — ${document.number}`" />

    <DashboardLayout>
        <div class="mx-auto max-w-3xl">
            <Link :href="route('pos.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to POS
            </Link>

            <div class="mt-2 flex items-center justify-between">
                <h1 class="font-display text-2xl font-bold text-ink-900">Checkout — {{ document.number }}</h1>
                <p v-if="document.contact" class="font-sans text-sm text-slate-500">{{ document.contact.name }}</p>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-6">
                <!-- Order summary -->
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Order summary
                    </h2>

                    <div class="flex flex-col gap-2">
                        <div v-for="line in document.lines" :key="line.id" class="flex justify-between font-sans text-sm">
                            <span class="text-ink-900">{{ line.description }} <span class="text-slate-400">× {{ displayQty(line.quantity) }}</span></span>
                            <span class="text-ink-900">Rs {{ money(line.line_total) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-1 border-t border-slate-100 pt-3">
                        <div class="flex justify-between font-sans text-sm text-slate-500">
                            <span>Subtotal</span>
                            <span>Rs {{ money(document.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between font-sans text-sm text-slate-500">
                            <span>Tax</span>
                            <span>Rs {{ money(document.tax_total) }}</span>
                        </div>
                        <div v-if="document.discount_type" class="flex justify-between font-sans text-sm text-danger-500">
                            <span>Discount</span>
                            <span>
                            -{{ orderDiscountLabel() }}
                            </span>
                        </div>
                        <div class="mt-1 flex justify-between border-t border-slate-100 pt-1 font-sans text-lg font-bold text-ink-900">
                            <span>Total</span>
                            <span>Rs {{ money(document.total) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Payment entry -->
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Payment
                    </h2>

                    <div class="flex flex-col gap-2">
                        <div v-for="(payment, index) in payments" :key="index" class="flex items-center gap-2">
                            <select
                                v-model="payment.method"
                                class="rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank transfer</option>
                                <option value="other">Other</option>
                            </select>
                            <input
                                v-model="payment.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-28 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <input
                                v-if="payment.method !== 'cash'"
                                v-model="payment.reference"
                                type="text"
                                placeholder="Reference"
                                class="flex-1 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <button
                                v-if="payments.length > 1"
                                @click="removePaymentRow(index)"
                                class="text-slate-300 hover:text-danger-500"
                            >
                                <X :size="16" />
                            </button>
                        </div>

                        <button
                            @click="addPaymentRow"
                            class="mt-1 flex items-center gap-1.5 font-sans text-xs font-medium text-ink-800 hover:underline"
                        >
                            <Plus :size="14" /> Split payment
                        </button>
                    </div>

                    <div class="mt-4 flex flex-col gap-1 border-t border-slate-100 pt-3">
                        <div class="flex justify-between font-sans text-sm text-slate-500">
                            <span>Entered</span>
                            <span>Rs {{ money(totalEntered) }}</span>
                        </div>
                        <div
                            v-if="hasCashPayment && changeDue > 0"
                            class="flex justify-between font-sans text-sm font-semibold text-brand-600"
                        >
                            <span>Change due</span>
                            <span>Rs {{ money(changeDue) }}</span>
                        </div>
                        <div v-else-if="remaining > 0.001" class="flex justify-between font-sans text-sm font-semibold text-danger-500">
                            <span>Remaining</span>
                            <span>Rs {{ money(remaining) }}</span>
                        </div>
                    </div>

                    <p v-if="errorMessage" class="mt-3 font-sans text-xs text-danger-500">{{ errorMessage }}</p>
                </div>
            </div>

            <!-- End-of-sale actions -->
            <div class="mt-6 flex items-center gap-3">
                <button
                    @click="continueWithoutPrinting"
                    :disabled="!isFullyCovered || isProcessing"
                    class="flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-40"
                >
                    <Check :size="16" /> Complete sale
                </button>
                <button
                    @click="printReceipt"
                    :disabled="!isFullyCovered || isProcessing"
                    class="flex items-center gap-2 rounded-lg border border-slate-200 px-5 py-2.5 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50 disabled:opacity-40"
                >
                    <Printer :size="16" /> Print receipt
                </button>
                <button
                    @click="printInvoice"
                    :disabled="!isFullyCovered || isProcessing"
                    class="flex items-center gap-2 rounded-lg border border-slate-200 px-5 py-2.5 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50 disabled:opacity-40"
                >
                    <FileText :size="16" /> Print invoice (PDF)
                </button>
            </div>

            <p v-if="!isFullyCovered" class="mt-2 font-sans text-xs text-slate-400">
                Enter payment covering the full total before completing the sale.
            </p>
        </div>
    </DashboardLayout>
</template>