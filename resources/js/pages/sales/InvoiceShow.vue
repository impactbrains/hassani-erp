<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Printer, RotateCcw } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PinApproval from '@/components/app/PinApproval.vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import { useOptions } from '@/composables/useOptions';
import { ApiError, post, uuid } from '@/lib/http';
import { dateTime, money, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * A posted receipt. Returns create a credit note: choose the items and
 * quantities, why, what happens to the goods, and whether the customer gets
 * cash back or a Khata credit. Big refunds need a manager PIN.
 */
const props = defineProps<{ pager?: any; invoice: any; receipt: any; showCost: boolean; canReturn: boolean; returnReasons: any[]; openShift: number | null }>();
const options = useOptions(['payment_methods']);

const returning = ref(false);
const qtys = ref<Record<number, string>>({});
const dispositions = ref<Record<number, string>>({});
const reasonId = ref<number | null>(props.returnReasons[0]?.id ?? null);
const settlement = ref<'refund' | 'khata_credit'>('refund');
const methodId = ref<number | null>(null);
const busy = ref(false);
const error = ref('');
const approvalOpen = ref(false);
const lastNote = ref<any>(null);
const reprintEl = ref<HTMLElement | null>(null);
const noteEl = ref<HTMLElement | null>(null);
let key = uuid();

const returnable = (l: any) => Number(l.base_quantity) - Number(l.base_quantity_returned ?? 0);
const lines = computed(() => props.invoice.lines.filter((l: any) => returnable(l) > 0));
const cashMethods = computed(() => (options.payment_methods ?? []).filter((m: any) => m.type === 'cash' || m.type === 'wallet' || m.type === 'bank'));

/**
 * Prints just one receipt. A copy of it is placed directly under <body> and
 * everything else on the screen is hidden while printing (see the style block
 * at the bottom), so the menu, tables and modals never reach the paper.
 */
function printElement(el: HTMLElement | null) {
    if (!el) return;
    document.getElementById('print-root')?.remove();
    const root = document.createElement('div');
    root.id = 'print-root';
    root.appendChild(el.cloneNode(true));
    document.body.appendChild(root);
    document.body.classList.add('printing-one');
    window.addEventListener('afterprint', () => {
        root.remove();
        document.body.classList.remove('printing-one');
    }, { once: true });
    window.print();
}
async function submit(token: string | null = null) {
    busy.value = true;
    error.value = '';
    try {
        const chosen = Object.entries(qtys.value).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ invoice_line_id: Number(id), quantity: q, disposition: dispositions.value[Number(id)] ?? 'restock' }));
        const res = await post(route('sales.invoices.credit-note', props.invoice.id), {
            idempotency_key: key, lines: chosen, reason_code_id: reasonId.value, settlement: settlement.value,
            payment_method_id: settlement.value === 'refund' ? methodId.value ?? cashMethods.value[0]?.id : null, shift_id: props.openShift, approval_token: token,
        });
        lastNote.value = res;
        returning.value = false;
        key = uuid();
        router.reload({ only: ['invoice', 'receipt'] });
    } catch (e) {
        if (e instanceof ApiError && e.details?.action === 'refund') {
            approvalOpen.value = true;
        } else {
            error.value = (e as Error).message;
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader :pager="pager" :title="invoice.number" :subtitle="`${dateTime(invoice.posted_at)} · ${invoice.cashier?.name ?? ''} · ${invoice.store?.name ?? ''}`">
        <template #actions>
            <Badge :status="invoice.payment_status" />
            <Button @click="printElement(reprintEl)"><Printer class="h-4 w-4" />{{ t('Reprint') }}</Button>
            <Button v-if="canReturn && lines.length" variant="primary" @click="returning = true"><RotateCcw class="h-4 w-4" />{{ t('Return items') }}</Button>
        </template>
    </PageHeader>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="panel">
            <table class="table-base">
                <thead><tr><th>{{ t('Item') }}</th><th class="num">{{ t('Quantity') }}</th><th class="num">{{ t('Price') }}</th><th class="num">{{ t('Discount') }}</th><th class="num">{{ t('Amount') }}</th><th class="num">{{ t('Returned') }}</th><th v-if="showCost" class="num">{{ t('Cost') }}</th></tr></thead>
                <tbody>
                    <tr v-for="l in invoice.lines" :key="l.id">
                        <td><div class="font-medium">{{ l.description }}</div><div v-if="l.description_ur" class="urdu text-[13px] text-ink-500" dir="rtl" style="text-align: start">{{ l.description_ur }}</div></td>
                        <td class="num">{{ qty(l.quantity) }} {{ l.variant_unit?.unit?.code }}</td><td class="num">{{ money(l.unit_price) }}</td>
                        <td class="num">{{ money(Number(l.line_discount) + Number(l.cart_discount_share)) }}</td><td class="num font-medium">{{ money(l.line_total) }}</td>
                        <td class="num">{{ Number(l.base_quantity_returned) ? qty(l.base_quantity_returned) : '—' }}</td><td v-if="showCost" class="num text-ink-600">{{ money(l.cost_amount) }}</td>
                    </tr>
                </tbody>
            </table>
            <dl class="space-y-1 border-t border-ink-100 p-4 text-sm">
                <div class="flex justify-between"><dt>{{ t('Total') }}</dt><dd class="font-semibold tabular-nums">{{ money(invoice.total) }}</dd></div>
                <div class="flex justify-between"><dt>{{ t('Paid') }}</dt><dd class="tabular-nums">{{ money(invoice.amount_paid) }}</dd></div>
                <div class="flex justify-between"><dt>{{ t('Returned') }}</dt><dd class="tabular-nums">{{ money(invoice.amount_credited) }}</dd></div>
            </dl>
            <div v-if="invoice.credit_notes?.length" class="border-t border-ink-100 p-4">
                <h3 class="mb-2">{{ t('Returns on this receipt') }}</h3>
                <ul class="space-y-1 text-sm"><li v-for="c in invoice.credit_notes" :key="c.id">{{ c.number }} · {{ dateTime(c.posted_at) }} · {{ c.reason_code?.label }} · <span class="font-medium">{{ money(c.total) }}</span> · {{ c.settlement === 'refund' ? t('Refund') : t('Khata credit') }}</li></ul>
            </div>
        </section>
        <aside class="panel p-3"><ReceiptView :receipt="receipt" /></aside>
    </div>
    <!-- Kept off screen; this is the copy that Reprint sends to the printer. -->
    <div class="hidden"><div ref="reprintEl"><ReceiptView :receipt="{ ...receipt, reprint: true }" /></div></div>

    <Modal :open="returning" :title="t('Return items')" size="xl" @close="returning = false">
        <table class="table-base">
            <thead><tr><th>{{ t('Item') }}</th><th class="num">{{ t('Can return') }}</th><th class="w-28">{{ t('Return qty') }}</th><th>{{ t('What happens to it') }}</th></tr></thead>
            <tbody>
                <tr v-for="l in lines" :key="l.id">
                    <td>{{ l.description }}</td><td class="num">{{ qty(returnable(l) / Number(l.unit_factor || 1)) }} {{ l.variant_unit?.unit?.code }}</td>
                    <td><input v-model="qtys[l.id]" type="number" step="any" min="0" class="input py-1 text-end" /></td>
                    <td><select v-model="dispositions[l.id]" class="input py-1 text-sm"><option value="restock">{{ t('Back on the shelf') }}</option><option value="damaged">{{ t('Damaged (not for sale)') }}</option><option value="inspect">{{ t('Check it first') }}</option></select></td>
                </tr>
            </tbody>
        </table>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
            <label><span class="field-label">{{ t('Reason') }}</span><select v-model="reasonId" class="input"><option v-for="r in returnReasons" :key="r.id" :value="r.id">{{ localName(r) }}</option></select></label>
            <label><span class="field-label">{{ t('Give back as') }}</span><select v-model="settlement" class="input"><option value="refund">{{ t('Money back') }}</option><option v-if="invoice.customer_id" value="khata_credit">{{ t('Credit to Khata') }}</option></select></label>
            <label v-if="settlement === 'refund'"><span class="field-label">{{ t('Paid from') }}</span><select v-model="methodId" class="input"><option v-for="m in cashMethods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></label>
        </div>
        <p v-if="settlement === 'refund' && !openShift" class="mt-3 text-sm text-haldi-800">{{ t('Cash refunds come from your open shift. Open the POS first if you are refunding cash.') }}</p>
        <p v-if="error" class="mt-3 rounded bg-ledger-redSoft px-3 py-2 text-sm text-ledger-red">{{ error }}</p>
        <template #footer><Button @click="returning = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="busy" @click="submit()">{{ t('Record return') }}</Button></template>
    </Modal>
    <Modal :open="!!lastNote" :title="t('Return recorded')" size="md" @close="lastNote = null">
        <div v-if="lastNote" ref="noteEl"><ReceiptView :receipt="lastNote.receipt" /></div>
        <template #footer><Button @click="printElement(noteEl)"><Printer class="h-4 w-4" />{{ t('Print') }}</Button><Button variant="primary" @click="lastNote = null">{{ t('Done') }}</Button></template>
    </Modal>
    <PinApproval :open="approvalOpen" action="refund" @close="approvalOpen = false" @approved="(token: string) => { approvalOpen = false; submit(token); }" />
</template>

<!-- Not scoped on purpose: the printed copy lives under <body>, outside this component. -->
<style>
#print-root { display: none; }

@media print {
    body.printing-one > *:not(#print-root) { display: none !important; }
    body.printing-one #print-root { display: block; }
}
</style>