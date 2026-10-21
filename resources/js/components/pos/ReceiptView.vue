<script setup lang="ts">
import { computed } from 'vue';
import { money, qty } from '@/lib/format';
import { code39 } from '@/lib/code39';

/**
 * Thermal receipt (80 mm or 58 mm) in English only, Urdu only, or both.
 * The default comes from Settings → POS → Receipt language; the cashier
 * can switch for one receipt (the `language` prop). In Urdu, item names
 * are the Urdu names typed on each product (never machine-translated);
 * items without one print their English name.
 */
const props = defineProps<{ receipt: any; language?: 'en' | 'ur' | 'both' }>();
const r = computed(() => props.receipt);
const lang = computed<'en' | 'ur' | 'both'>(() => props.language ?? r.value.language ?? 'en');
const urduOnly = computed(() => lang.value === 'ur');
// The number as a barcode, so a printed receipt can be scanned at the POS (reprint or return). Not for credit notes.
const numberBarcode = computed(() => (r.value.kind === 'credit_note' || !r.value.number ? null : code39(String(r.value.number))));

const LABELS: Record<string, [string, string]> = {
    receipt: ['RECEIPT', 'رسید'],
    invoice: ['INVOICE', 'انوائس'],
    credit_note: ['RETURN / CREDIT NOTE', 'واپسی رسید'],
    copy: ['COPY', 'نقل'],
    against: ['Against', 'بمقابلہ'],
    customer: ['Customer', 'گاہک'],
    subtotal: ['Subtotal', 'ذیلی کل'],
    discount: ['Discount', 'رعایت'],
    tax: ['Sales tax (incl.)', 'سیلز ٹیکس (شامل)'],
    delivery: ['Delivery', 'ڈیلیوری'],
    rounding: ['Rounding', 'راؤنڈنگ'],
    total: ['TOTAL', 'کل رقم'],
    given: ['Cash given / change', 'دی گئی رقم / بقایا'],
    khata: ['On Khata', 'کھاتے میں'],
    balance: ['Khata balance now', 'کھاتہ بیلنس'],
    items: ['Items', 'اشیاء'],
    refunded: ['REFUNDED', 'واپس کی گئی رقم'],
    credited: ['CREDITED TO KHATA', 'کھاتے میں جمع'],
    note: ['Prices include applicable tax. Keep this receipt for returns.', 'قیمتوں میں ٹیکس شامل ہے۔ واپسی کے لیے رسید سنبھال کر رکھیں۔'],
};
/** One label in the chosen language; "both" prints English with the Urdu beside it. */
function L(key: string): string {
    const [en, ur] = LABELS[key];
    return lang.value === 'ur' ? ur : lang.value === 'both' ? `${en} · ${ur}` : en;
}
function itemName(line: any): string {
    return urduOnly.value ? line.name_ur || line.name : line.name;
}
const nonZero = (v: any) => v !== null && v !== undefined && Number(v) !== 0;
</script>

<template>
    <div class="receipt mx-auto bg-white text-black" :class="urduOnly ? 'urdu-receipt' : ''" :style="{ width: r.width === '58' ? '54mm' : '72mm' }" :dir="urduOnly ? 'rtl' : 'ltr'">
        <div class="text-center">
            <div class="text-[15px] font-bold leading-tight">{{ r.business?.name }}</div>
            <div v-if="lang !== 'en' && r.store?.name_ur" class="urdu text-[14px]">{{ r.store.name_ur }}</div>
            <div v-if="!urduOnly || !r.store?.name_ur" class="text-[11px]">{{ r.store?.name }}</div>
            <div v-if="r.store?.address" class="text-[11px]">{{ r.store.address }}</div>
            <div v-if="r.business?.phone" class="text-[11px]" dir="ltr">{{ r.business.phone }}</div>
            <div v-if="r.business?.ntn" class="text-[11px]" dir="ltr">NTN {{ r.business.ntn }}</div>
        </div>
        <div class="my-2 border-t border-dashed border-black" />
        <div class="text-[12px] font-bold">
            {{ L(r.kind === 'credit_note' ? 'credit_note' : r.kind === 'invoice' ? 'invoice' : 'receipt') }} <span dir="ltr">{{ r.number }}</span>
            <span v-if="r.reprint"> ({{ L('copy') }})</span>
        </div>
        <div class="text-[11px]" dir="ltr">{{ r.posted_at }}<template v-if="r.cashier"> · {{ r.cashier }}</template></div>
        <div v-if="r.original" class="text-[11px]">{{ L('against') }} <span dir="ltr">{{ r.original }}</span> · {{ r.reason }}</div>
        <div v-if="r.customer" class="text-[11px]">{{ L('customer') }}: {{ r.customer.name }}<template v-if="r.customer.code"> ({{ r.customer.code }})</template></div>
        <div class="my-2 border-t border-dashed border-black" />

        <table class="w-full text-[11.5px] leading-snug">
            <tbody>
                <template v-for="(line, i) in r.lines" :key="i">
                    <tr>
                        <td colspan="3" class="pt-1 font-semibold">
                            <span :class="urduOnly && line.name_ur ? 'urdu text-[13px] font-normal' : ''">{{ itemName(line) }}</span>
                            <span v-if="lang === 'both' && line.name_ur" class="urdu block text-right text-[13px] font-normal" dir="rtl">{{ line.name_ur }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="tabular-nums" dir="ltr">{{ qty(line.quantity) }} {{ line.unit ?? '' }}<template v-if="line.unit_price"> × {{ money(line.unit_price, { symbol: false }) }}</template></td>
                        <td class="text-end tabular-nums" dir="ltr">{{ nonZero(line.discount) ? '−' + money(line.discount, { symbol: false }) : '' }}</td>
                        <td class="text-end font-semibold tabular-nums" dir="ltr">{{ money(line.total, { symbol: false }) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div class="my-2 border-t border-dashed border-black" />

        <template v-if="r.totals">
            <div class="flex justify-between text-[12px]"><span>{{ L('subtotal') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.totals.subtotal) }}</span></div>
            <div v-if="nonZero(r.totals.discount)" class="flex justify-between text-[12px]"><span>{{ L('discount') }}</span><span class="tabular-nums" dir="ltr">−{{ money(r.totals.discount) }}</span></div>
            <div v-if="nonZero(r.totals.tax)" class="flex justify-between text-[12px]"><span>{{ L('tax') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.totals.tax) }}</span></div>
            <div v-if="nonZero(r.totals.delivery)" class="flex justify-between text-[12px]"><span>{{ L('delivery') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.totals.delivery) }}</span></div>
            <div v-if="nonZero(r.totals.rounding)" class="flex justify-between text-[12px]"><span>{{ L('rounding') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.totals.rounding) }}</span></div>
            <div class="mt-1 flex justify-between text-[16px] font-bold"><span>{{ L('total') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.totals.total) }}</span></div>
            <div class="my-2 border-t border-dashed border-black" />
            <div v-for="(p, i) in r.payments" :key="i" class="text-[12px]">
                <div class="flex justify-between"><span>{{ urduOnly ? p.method_ur || p.method : p.method }}<template v-if="p.reference"> #{{ p.reference }}</template></span><span class="tabular-nums" dir="ltr">{{ money(p.amount) }}</span></div>
                <div v-if="p.tendered" class="flex justify-between text-[11px]"><span>{{ L('given') }}</span><span class="tabular-nums" dir="ltr">{{ money(p.tendered) }} / {{ money(p.change) }}</span></div>
            </div>
            <div v-if="nonZero(r.totals.khata)" class="flex justify-between text-[12px] font-semibold"><span>{{ L('khata') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.totals.khata) }}</span></div>
            <div v-if="r.khata_balance !== null && r.khata_balance !== undefined" class="flex justify-between text-[12px]"><span>{{ L('balance') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.khata_balance) }}</span></div>
            <div class="mt-1 text-[11px]">{{ L('items') }}: {{ r.items_count }}</div>
        </template>
        <template v-else>
            <div class="flex justify-between text-[15px] font-bold"><span>{{ L(r.settlement === 'refund' ? 'refunded' : 'credited') }}</span><span class="tabular-nums" dir="ltr">{{ money(r.total) }}</span></div>
        </template>

        <div class="my-2 border-t border-dashed border-black" />
        <div v-if="lang !== 'en' && r.footer_ur" class="urdu text-center text-[13px]" dir="rtl">{{ r.footer_ur }}</div>
        <div v-if="lang !== 'ur' && r.footer" class="text-center text-[11px]">{{ r.footer }}</div>
        <div class="mt-1 text-center text-[10px]" :class="lang === 'ur' ? 'urdu text-[11px]' : ''">{{ lang === 'ur' ? LABELS.note[1] : LABELS.note[0] }}</div>
        <div v-if="numberBarcode" class="mt-2 text-center">
            <svg :viewBox="`0 0 ${numberBarcode.width} 30`" preserveAspectRatio="none" class="mx-auto block h-9 w-full" role="img" :aria-label="String(r.number)">
                <rect v-for="(b, i) in numberBarcode.bars" :key="i" :x="b.x" y="0" :width="b.w" height="30" fill="#000" />
            </svg>
            <div class="text-[10px] tabular-nums" dir="ltr">{{ r.number }}</div>
        </div>
    </div>
</template>

<style>
.urdu-receipt { font-family: 'IBM Plex Sans', 'Noto Nastaliq Urdu', sans-serif; line-height: 1.9; }
@media print {
    @page { margin: 0; size: 80mm auto; }
    .receipt { width: 72mm !important; padding: 2mm 0; font-family: 'IBM Plex Sans', 'Noto Nastaliq Urdu', sans-serif; }
}
</style>
