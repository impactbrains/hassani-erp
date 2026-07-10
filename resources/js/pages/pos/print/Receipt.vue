<script setup lang="ts">
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';

interface DocumentLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    discount_amount: string;
    tax_amount: string;
    line_total: string;
}

interface Payment {
    id: number;
    method: string;
    amount: string;
}

interface DocumentData {
    number: string;
    created_at: string;
    formatted_created_at: string;
    contact: { name: string } | null;
    location: { name: string; address: string | null } | null;
    user: { name: string } | null;
    subtotal: string;
    tax_total: string;
    discount_type: 'percentage' | 'fixed' | null;
    discount_value: string | null;
    total: string;
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

// Order-level discount_type/value doesn't carry a pre-computed rupee
// amount — derive it here so the receipt can show "Rs X (Y%)" for
// percentage discounts, not just the bare percentage.
const orderDiscountAmount = props.document.discount_type === 'percentage'
    ? Number(props.document.subtotal) * (Number(props.document.discount_value ?? 0) / 100)
    : Number(props.document.discount_value ?? 0);

function orderDiscountLabel(): string {
    if (props.document.discount_type === 'percentage') {
        return `Rs ${money(orderDiscountAmount)} (${props.document.discount_value}%)`;
    }
    return `Rs ${money(props.document.discount_value ?? 0)}`;
}

function lineDiscountLabel(line: DocumentLine): string {
    if (Number(line.discount_percent) > 0) {
        return `Rs ${money(line.discount_amount)} (${line.discount_percent}%)`;
    }
    return `Rs ${money(line.discount_amount)}`;
}

const totalPaid = props.document.payments.reduce((sum, p) => sum + Number(p.amount), 0);
const changeGiven = Math.max(0, totalPaid - Number(props.document.total));

onMounted(() => {
    // Give the page a moment to fully render before invoking the browser's
    // print dialog — printing too early can clip content on some browsers.
    setTimeout(() => window.print(), 300);
});
</script>

<template>
    <Head :title="`Receipt — ${document.number}`" />

    <div class="receipt">
        <div class="center bold" style="font-size: 14px">Hassani Bachat Bazar</div>
        <div v-if="document.location?.address" class="center small">{{ document.location.address }}</div>
        <div class="center small">{{ document.location?.name }}</div>

        <div class="divider"></div>

        <div class="row"><span>Order</span><span>{{ document.number }}</span></div>
        <div class="row"><span>Date</span><span>{{ document.formatted_created_at }}</span></div>
        <div v-if="document.contact" class="row"><span>Customer</span><span>{{ document.contact.name }}</span></div>
        <div v-if="document.user" class="row"><span>Cashier</span><span>{{ document.user.name }}</span></div>

        <div class="divider"></div>

        <div v-for="line in document.lines" :key="line.id" class="line-item">
            <div class="row">
                <span>{{ line.description }}</span>
            </div>
            <div class="row small">
                <span>{{ displayQty(line.quantity) }} × Rs {{ money(line.unit_price) }}</span>
                <span>Rs {{ money(line.line_total) }}</span>
            </div>
            <div v-if="Number(line.discount_amount) > 0" class="row small">
                <span>Discount</span>
                <span>-{{ lineDiscountLabel(line) }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <div class="row"><span>Subtotal</span><span>Rs {{ money(document.subtotal) }}</span></div>
        <div class="row"><span>Tax</span><span>Rs {{ money(document.tax_total) }}</span></div>
        <div v-if="document.discount_type" class="row">
            <span>Discount</span>
            <span>-{{ orderDiscountLabel() }}</span>
        </div>
        <div class="row bold" style="font-size: 13px"><span>Total</span><span>Rs {{ money(document.total) }}</span></div>

        <div class="divider"></div>

        <div v-for="payment in document.payments" :key="payment.id" class="row small">
            <span class="capitalize">{{ payment.method.replace('_', ' ') }}</span>
            <span>Rs {{ money(payment.amount) }}</span>
        </div>
        <div v-if="changeGiven > 0" class="row bold">
            <span>Change</span><span>Rs {{ money(changeGiven) }}</span>
        </div>

        <div class="divider"></div>

        <div class="center small" style="margin-top: 8px">Thank you for shopping with us!</div>
    </div>
</template>

<style>
/* Intentionally global and print-focused — this page has no
   DashboardLayout, so there's no design-system CSS to inherit from. */
@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }
    body {
        margin: 0;
    }
}

body {
    background: white;
}

.receipt {
    width: 80mm;
    margin: 0 auto;
    padding: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: #000;
}

.center {
    text-align: center;
}

.bold {
    font-weight: bold;
}

.small {
    font-size: 10px;
}

.capitalize {
    text-transform: capitalize;
}

.divider {
    border-top: 1px dashed #000;
    margin: 6px 0;
}

.row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
}

.line-item {
    margin-bottom: 4px;
}
</style>