<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, Printer, FileText, User, MapPin, UserCircle, RotateCcw } from 'lucide-vue-next';

interface DocumentLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_amount: string;
    line_total: string;
    tax: { id: number; name: string } | null;
}

interface Payment {
    id: number;
    method: 'cash' | 'card' | 'bank_transfer' | 'other';
    amount: string;
    reference: string | null;
    paid_at: string;
}

interface DocumentData {
    id: number;
    number: string;
    status: 'draft' | 'confirmed' | 'paid' | 'cancelled' | 'refunded';
    created_at: string;
    confirmed_at: string | null;
    formatted_created_at: string;
    formatted_confirmed_at: string | null;
    subtotal: string;
    tax_total: string;
    discount_type: 'percentage' | 'fixed' | null;
    discount_value: string | null;
    total: string;
    amount_paid: string;
    contact: { id: number; name: string; phone: string | null } | null;
    location: { id: number; name: string } | null;
    user: { id: number; name: string } | null;
    lines: DocumentLine[];
    payments: Payment[];
}

const props = defineProps<{
    document: DocumentData;
}>();

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function displayQty(value: string | number): string {
    const num = Number(value);
    return num.toFixed(3).replace(/\.?0+$/, '') || '0';
}

function orderDiscountLabel(): string {
    if (props.document.discount_type === 'percentage') {
        const amount = Number(props.document.subtotal) * (Number(props.document.discount_value ?? 0) / 100);
        return `Rs ${money(amount)} (${props.document.discount_value}%)`;
    }
    return `Rs ${money(props.document.discount_value ?? 0)}`;
}

const statusBadgeClass: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-600',
    confirmed: 'bg-amber-100 text-amber-700',
    paid: 'bg-brand-500/15 text-ink-800',
    cancelled: 'bg-danger-500/15 text-danger-500',
    refunded: 'bg-blue-100 text-blue-700',
};

function openReceipt() {
    window.open(route('pos.history.receipt', { document: props.document.id }), '_blank');
}

function openInvoice() {
    window.open(route('pos.history.invoice', { document: props.document.id }), '_blank');
}

function resumeDraft() {
    router.visit(route('pos.checkout.show', { document: props.document.id }));
}
</script>

<template>
    <Head :title="`Order ${document.number}`" />

    <DashboardLayout>
        <div class="mx-auto max-w-3xl">
            <Link :href="route('pos.history')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to Order History
            </Link>

            <div class="mt-2 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="font-display text-2xl font-bold text-ink-900">{{ document.number }}</h1>
                    <span
                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                        :class="statusBadgeClass[document.status]"
                    >
                        {{ document.status }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="document.status === 'draft'"
                        @click="resumeDraft"
                        class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400"
                    >
                        Continue order
                    </button>
                    <template v-else>
                        <button
                            @click="openReceipt"
                            class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                        >
                            <Printer :size="16" /> Receipt
                        </button>
                        <button
                            @click="openInvoice"
                            class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                        >
                            <FileText :size="16" /> Invoice
                        </button>
                        <Link
                            v-if="['confirmed', 'paid'].includes(document.status)"
                            :href="route('pos.refund.create', { document: document.id })"
                            class="flex items-center gap-1.5 rounded-lg border border-danger-500 px-4 py-2 font-sans text-sm font-semibold text-danger-500 hover:bg-danger-500/5"
                        >
                            <RotateCcw :size="16" /> Refund
                        </Link>
                    </template>
                </div>
            </div>

            <!-- Meta info -->
            <div class="mt-4 grid grid-cols-3 gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="flex items-center gap-1.5 font-sans text-xs font-medium text-slate-400">
                        <User :size="12" /> Customer
                    </p>
                    <p class="mt-1 font-sans text-sm text-ink-900">{{ document.contact?.name ?? 'Walk-in customer' }}</p>
                    <p v-if="document.contact?.phone" class="font-sans text-xs text-slate-400">{{ document.contact.phone }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="flex items-center gap-1.5 font-sans text-xs font-medium text-slate-400">
                        <MapPin :size="12" /> Location
                    </p>
                    <p class="mt-1 font-sans text-sm text-ink-900">{{ document.location?.name ?? '—' }}</p>
                    <p class="font-sans text-xs text-slate-400">{{ document.formatted_confirmed_at ?? document.formatted_created_at }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="flex items-center gap-1.5 font-sans text-xs font-medium text-slate-400">
                        <UserCircle :size="12" /> Cashier
                    </p>
                    <p class="mt-1 font-sans text-sm text-ink-900">{{ document.user?.name ?? '—' }}</p>
                </div>
            </div>

            <!-- Line items -->
            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Item</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Qty</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Unit price</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Discount</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Tax</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in document.lines" :key="line.id" class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-3 text-ink-900">{{ line.description }}</td>
                            <td class="px-4 py-3 text-right text-slate-500">{{ displayQty(line.quantity) }}</td>
                            <td class="px-4 py-3 text-right text-slate-500">Rs {{ money(line.unit_price) }}</td>
                            <td class="px-4 py-3 text-right text-slate-500">
                                <span v-if="Number(line.discount_amount) > 0">-Rs {{ money(line.discount_amount) }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-500">
                                Rs {{ money(line.tax_amount) }}
                                <span v-if="line.tax" class="block text-xs text-slate-400">{{ line.tax.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-ink-900">Rs {{ money(line.line_total) }}</td>
                        </tr>
                        <tr v-if="document.lines.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">No items on this order.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totals + payments -->
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <h2 class="mb-2 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">Totals</h2>
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
                    <div class="mt-1 flex justify-between border-t border-slate-100 pt-1 font-sans text-base font-bold text-ink-900">
                        <span>Total</span>
                        <span>Rs {{ money(document.total) }}</span>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <h2 class="mb-2 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">Payments</h2>
                    <div v-if="document.payments.length === 0" class="font-sans text-sm text-slate-400">
                        No payments recorded yet.
                    </div>
                    <div v-for="payment in document.payments" :key="payment.id" class="flex justify-between font-sans text-sm">
                        <span class="capitalize text-slate-500">
                            {{ payment.method.replace('_', ' ') }}
                            <span v-if="payment.reference" class="text-xs text-slate-400">({{ payment.reference }})</span>
                        </span>
                        <span class="text-ink-900">Rs {{ money(payment.amount) }}</span>
                    </div>
                    <div v-if="document.payments.length > 0" class="mt-1 flex justify-between border-t border-slate-100 pt-1 font-sans text-sm font-semibold text-ink-900">
                        <span>Total paid</span>
                        <span>Rs {{ money(document.amount_paid) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </DashboardLayout>
    </template>
