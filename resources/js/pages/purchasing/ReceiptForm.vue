<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { date, money, qty, round2 } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * Goods received note. With a PO, the ordered lines are listed; type what actually arrived.
 * In "purchase" mode (Purchase stock) the same screen also records the supplier's bill and,
 * when paid now, the payment, so stock, what you owe and the cash all agree.
 */
const props = defineProps<{
    order: any | null;
    mode: 'receive' | 'purchase';
    showCost: boolean;
    can: { purchase: boolean; receive: boolean; add_supplier: boolean };
    /** Purchases above this value wait for a manager. null: this person's purchases never wait. */
    approvalAbove: string | null;
}>();
const purchaseMode = computed(() => props.mode === 'purchase');
const { here: locations } = useStoreLocations();
const options = useOptions(['payment_methods']);
const methods = computed(() => (options.payment_methods ?? []).filter((m: any) => ['cash', 'bank', 'wallet'].includes(m.type)));
const supplier = ref<any>(props.order?.supplier ?? null);
const outstanding = (l: any) => Math.max(0, (Number(l.base_quantity) - Number(l.base_quantity_received) - Number(l.base_quantity_cancelled)) / Number(l.unit_factor || 1));
const form = useForm<any>({
    supplier_id: props.order?.supplier_id ?? null, purchase_order_id: props.order?.id ?? null, location_id: null, supplier_reference: '', notes: '', is_blind: false,
    lines: (props.order?.lines ?? []).filter((l: any) => outstanding(l) > 0).map((l: any) => ({
        variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, purchase_order_line_id: l.id, name: l.variant?.display_name, sku: l.variant?.sku, unit: l.variant_unit?.unit?.code,
        expected: outstanding(l), quantity_received: String(outstanding(l)), quantity_damaged: '', unit_price: l.unit_price, batch_no: '', expiry_date: '', quality_status: 'accepted', discrepancy_note: '',
    })),
    landed_costs: [] as any[],
    // How it was paid. Purchase stock must choose; plain receiving defaults to "the bill comes later".
    settlement: purchaseMode.value ? null : (props.can.receive ? 'bill_later' : null),
    payment_method_id: null as number | null,
    amount_paid: '',
    payment_reference: '',
});
watch(supplier, (s) => (form.supplier_id = s?.id ?? null));
watch(locations, (l) => { if (!form.location_id && l.length) form.location_id = (l.find((x: any) => x.type === 'warehouse' || x.type === 'godown') ?? l.find((x: any) => x.is_sellable) ?? l[0]).id; }, { immediate: true });
watch(methods, (m) => { if (!form.payment_method_id && m.length) form.payment_method_id = (m.find((x: any) => x.type === 'cash') ?? m[0]).id; }, { immediate: true });

const factorOf = (l: any) => Number(l.units?.find((u: any) => u.id === l.variant_unit_id)?.factor ?? 1);
const unitCode = (l: any) => l.units?.find((u: any) => u.id === l.variant_unit_id)?.code ?? l.unit;
/** The cost we suggest for a line: the product's Purchase price (or what was paid last time), for the pack chosen. */
function suggestedCost(l: any): string {
    const perUnit = Number(l.purchase_price ?? l.last_cost?.per_unit ?? 0);
    return perUnit > 0 ? String(round2(perUnit * factorOf(l))) : '';
}
function add(p: any) {
    const u = p.units.find((x: any) => x.is_default) ?? p.units[0];
    const line: any = { variant_id: p.variant_id, variant_unit_id: u?.id, units: p.units, purchase_order_line_id: null, name: p.name, sku: p.sku, unit: u?.code, expected: null, quantity_received: '1', quantity_damaged: '', unit_price: '', cost_typed: false,
        purchase_price: p.purchase_price ?? null, last_cost: p.last_cost ?? null, average_cost: p.average_cost ?? null, batch_no: '', expiry_date: '', quality_status: 'accepted', discrepancy_note: '', track_batches: p.track_batches };
    line.unit_price = suggestedCost(line);
    form.lines.push(line);
}
/** Changing the pack re-suggests the cost, unless you typed one. */
function onUnitChange(l: any) {
    l.unit = unitCode(l);
    if (!l.cost_typed) l.unit_price = suggestedCost(l);
}
const goods = computed(() => round2(form.lines.reduce((s: number, l: any) => s + Number(l.quantity_received || 0) * Number(l.unit_price || 0), 0)));
const extra = computed(() => round2(form.landed_costs.reduce((s: number, c: any) => s + Number(c.amount || 0), 0)));

// ----- Payment -----
const showPayment = computed(() => props.can.purchase);
const paid = computed(() => (form.amount_paid === '' ? goods.value : Number(form.amount_paid)));
const owedAfter = computed(() => round2(Math.max(0, goods.value - paid.value)));
const waitsForApproval = computed(() => {
    if (props.approvalAbove === null || !['paid_now', 'on_credit'].includes(form.settlement)) return false;
    const limit = Number(props.approvalAbove);
    return !(limit > 0 && goods.value + extra.value <= limit);
});
/** Errors not already shown next to their own field. */
const otherErrors = computed(() => Object.fromEntries(Object.entries(form.errors as Record<string, string>).filter(([k]) => !['supplier_id', 'payment_method_id', 'amount_paid'].includes(k))));
const canSave = computed(() => form.lines.length > 0 && !!form.supplier_id && !!form.settlement && (form.settlement !== 'paid_now' || !!form.payment_method_id));

function submit() {
    form.transform((d: any) => ({
        ...d,
        settlement: d.settlement,
        payment_method_id: d.settlement === 'paid_now' ? d.payment_method_id : null,
        amount_paid: d.settlement === 'paid_now' && d.amount_paid !== '' ? d.amount_paid : null,
        payment_reference: d.settlement === 'paid_now' ? d.payment_reference || null : null,
        lines: d.lines.map((l: any) => ({ variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, purchase_order_line_id: l.purchase_order_line_id, quantity_received: l.quantity_received || 0, quantity_damaged: l.quantity_damaged || 0, unit_price: l.unit_price || 0, batch_no: l.batch_no || null, expiry_date: l.expiry_date || null, quality_status: l.quality_status, discrepancy_note: l.discrepancy_note || null })),
    })).post(route('purchasing.receipts.store'));
}
</script>

<template>
    <PageHeader
        :title="purchaseMode ? t('Purchase stock') : order ? `${t('Receive goods for')} ${order.number}` : t('Receive goods')"
        :subtitle="purchaseMode ? t('Stock you bought from a supplier. Saving adds it to stock and records the bill, and the payment if you paid now.') : t('Count what arrived. Damaged items are kept separately and not added to sellable stock.')"
    />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-4">
        <Field label="Supplier" :error="form.errors.supplier_id" required>
            <div v-if="order" class="py-2 font-medium">{{ order.supplier?.name }}</div>
            <ContactPicker v-else v-model="supplier" type="supplier" :placeholder="t('Search suppliers by name or phone')" :create-route="can.add_supplier ? 'purchasing.suppliers.quick' : undefined" />
        </Field>
        <Field label="Put stock in"><select v-model="form.location_id" class="input"><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></Field>
        <Field label="Supplier bill / delivery note no."><input v-model="form.supplier_reference" class="input" /></Field>
        <Field label="Notes"><input v-model="form.notes" class="input" /></Field>
    </section>
    <section class="panel mb-4">
        <div v-if="!order" class="border-b border-ink-100 p-3"><ProductPicker purpose="purchase" :supplier-id="form.supplier_id" @pick="add" /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr><th>{{ t('Product') }}</th><th v-if="order" class="num">{{ t('Expected') }}</th><th class="w-24">{{ t('Received') }}</th><th class="w-24">{{ t('Damaged') }}</th><th class="w-44">{{ t('Cost per pack') }}</th><th class="w-32">{{ t('Batch') }}</th><th class="w-36">{{ t('Expiry') }}</th><th class="w-32">{{ t('Quality') }}</th><th class="num">{{ t('Amount') }}</th><th class="w-8"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in form.lines" :key="i">
                        <td><div class="font-medium">{{ l.name }}</div><div class="text-[12px] text-ink-500">{{ l.sku }} · <template v-if="l.units?.length > 1"><select v-model="l.variant_unit_id" class="rounded border-ink-200 py-0 text-[12px]" @change="onUnitChange(l)"><option v-for="u in l.units" :key="u.id" :value="u.id">{{ u.name }}</option></select></template><template v-else>{{ l.unit }}</template></div>
                            <input v-if="l.expected !== null && Number(l.quantity_received) !== l.expected" v-model="l.discrepancy_note" class="input mt-1 py-1 text-[12.5px]" :placeholder="t('Why different?')" /></td>
                        <td v-if="order" class="num">{{ l.expected !== null ? qty(l.expected) : '—' }}</td>
                        <td><input v-model="l.quantity_received" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                        <td><input v-model="l.quantity_damaged" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                        <td>
                            <input v-model="l.unit_price" type="number" min="0" step="any" class="input py-1 text-end" :readonly="!showCost && !!order" @input="l.cost_typed = true" />
                            <div v-if="l.last_cost" class="mt-0.5 text-[11.5px] leading-snug text-ink-500" :title="l.last_cost.supplier ?? ''">
                                <div class="whitespace-nowrap">{{ t('Last time: :price per :unit', { price: money(l.last_cost.pack_price), unit: l.last_cost.unit }) }}</div>
                                <div class="truncate">{{ date(l.last_cost.date) }}<template v-if="l.last_cost.supplier && !l.last_cost.same_supplier"> · {{ l.last_cost.supplier }}</template></div>
                            </div>
                            <div v-else-if="l.average_cost" class="mt-0.5 text-[11.5px] text-ink-500">{{ t('Average cost: :price per :unit', { price: money(l.average_cost), unit: l.units?.find((u: any) => u.is_base)?.code ?? '' }) }}</div>
                            <div v-else-if="!order" class="mt-0.5 text-[11.5px] text-ink-400">{{ t('First purchase') }}</div>
                        </td>
                        <td><input v-model="l.batch_no" class="input py-1 text-sm" /></td>
                        <td><input v-model="l.expiry_date" type="date" class="input py-1 text-sm" /></td>
                        <td><select v-model="l.quality_status" class="input py-1 text-sm"><option value="accepted">{{ t('OK') }}</option><option value="quarantined">{{ t('Hold to check') }}</option></select></td>
                        <td class="num">{{ money(Number(l.quantity_received || 0) * Number(l.unit_price || 0)) }}</td>
                        <td><button class="text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="form.lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                    <tr v-if="!form.lines.length"><td colspan="10" class="py-8 text-center text-ink-500">{{ t('Add products using the search above.') }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <section class="panel self-start p-4">
            <div class="mb-2 flex items-center justify-between"><h3>{{ t('Freight and other costs') }}</h3><Button size="sm" @click="form.landed_costs.push({ type: 'freight', amount: '', description: '', allocation_method: 'value' })"><Plus class="h-4 w-4" />{{ t('Add cost') }}</Button></div>
            <p class="mb-2 text-[12.5px] text-ink-500">{{ t('Spread over the products so their cost includes delivery. Pay these in Accounting if paid to someone other than the supplier.') }}</p>
            <div v-for="(c, i) in form.landed_costs" :key="i" class="mb-2 flex gap-2">
                <select v-model="c.type" class="input w-32 py-1"><option value="freight">{{ t('Freight') }}</option><option value="loading">{{ t('Loading') }}</option><option value="customs">{{ t('Customs') }}</option><option value="other">{{ t('Other') }}</option></select>
                <input v-model="c.description" class="input flex-1 py-1" :placeholder="t('Description')" />
                <input v-model="c.amount" type="number" min="0" class="input w-28 py-1 text-end" />
                <select v-model="c.allocation_method" class="input w-36 py-1"><option value="value">{{ t('By value') }}</option><option value="quantity">{{ t('By quantity') }}</option></select>
                <button class="text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="form.landed_costs.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
            </div>
        </section>
        <section class="panel space-y-3 p-4 text-sm">
            <div class="space-y-1">
                <div class="flex justify-between"><span>{{ t('Goods') }}</span><span class="tabular-nums">{{ money(goods) }}</span></div>
                <div class="flex justify-between"><span>{{ t('Extra costs') }}</span><span class="tabular-nums">{{ money(extra) }}</span></div>
                <div class="flex justify-between border-t border-ink-100 pt-1 text-base font-semibold"><span>{{ t('Total') }}</span><span class="tabular-nums">{{ money(goods + extra) }}</span></div>
            </div>

            <div v-if="showPayment" role="radiogroup" aria-labelledby="paid-how" class="space-y-2 border-t border-ink-100 pt-3">
                <h3 id="paid-how" class="mb-1 font-semibold">{{ t('How was it paid?') }}</h3>
                <label class="flex cursor-pointer gap-2 rounded border p-2" :class="form.settlement === 'paid_now' ? 'border-haldi-500 bg-haldi-50' : 'border-ink-100'">
                    <input v-model="form.settlement" type="radio" value="paid_now" class="mt-0.5" />
                    <span><span class="font-medium">{{ t('Paid now') }}</span><span class="block text-[12.5px] text-ink-500">{{ t('The bill is recorded as paid, from the account you choose.') }}</span></span>
                </label>
                <label class="flex cursor-pointer gap-2 rounded border p-2" :class="form.settlement === 'on_credit' ? 'border-haldi-500 bg-haldi-50' : 'border-ink-100'">
                    <input v-model="form.settlement" type="radio" value="on_credit" class="mt-0.5" />
                    <span><span class="font-medium">{{ t('On credit') }}</span><span class="block text-[12.5px] text-ink-500">{{ t('The amount is added to what you owe this supplier. Pay it later from Supplier bills.') }}</span></span>
                </label>
                <label v-if="can.receive && !purchaseMode" class="flex cursor-pointer gap-2 rounded border p-2" :class="form.settlement === 'bill_later' ? 'border-haldi-500 bg-haldi-50' : 'border-ink-100'">
                    <input v-model="form.settlement" type="radio" value="bill_later" class="mt-0.5" />
                    <span><span class="font-medium">{{ t('Bill comes later') }}</span><span class="block text-[12.5px] text-ink-500">{{ t('Only the stock is added. Enter the supplier’s bill later in Supplier bills.') }}</span></span>
                </label>

                <div v-if="form.settlement === 'paid_now'" class="space-y-2 rounded bg-ink-50 p-2">
                    <Field label="Paid from" :error="form.errors.payment_method_id"><select v-model="form.payment_method_id" class="input"><option v-for="m in methods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></Field>
                    <Field label="Amount paid" :error="form.errors.amount_paid" hint="Leave empty if you paid the full goods total.">
                        <input v-model="form.amount_paid" type="number" min="0" step="any" class="input text-end tabular-nums" :placeholder="String(goods)" />
                    </Field>
                    <p v-if="owedAfter > 0" class="text-[12.5px] text-ink-700">{{ t('The rest, :amount, is added to what you owe this supplier.', { amount: money(owedAfter) }) }}</p>
                    <Field label="Cheque / transaction no. (optional)"><input v-model="form.payment_reference" class="input" /></Field>
                </div>
                <p v-if="waitsForApproval" class="rounded border border-haldi-300 bg-haldi-50 p-2 text-[12.5px] text-haldi-900">
                    {{ Number(approvalAbove) > 0 ? t('Purchases above :amount need a manager’s approval. This one will wait, and the stock is added once it is approved.', { amount: money(approvalAbove) }) : t('Your purchases need a manager’s approval. This one will wait, and the stock is added once it is approved.') }}
                </p>
            </div>

            <p v-for="(e, k) in otherErrors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <Button variant="primary" class="w-full" :disabled="!canSave" :loading="form.processing" @click="submit">{{ purchaseMode ? t('Save purchase') : t('Save and add to stock') }}</Button>
            <p v-if="!form.settlement && form.lines.length && form.supplier_id" class="text-center text-[12.5px] text-ink-500">{{ t('Choose how it was paid to save.') }}</p>
        </section>
    </div>
</template>
