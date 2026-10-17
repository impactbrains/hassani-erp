<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { money, qty, round2 } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Goods received note. With a PO, the ordered lines are listed; type what actually arrived. */
const props = defineProps<{ order: any | null; showCost: boolean }>();
const { here: locations } = useStoreLocations();
const supplier = ref<any>(props.order?.supplier ?? null);
const outstanding = (l: any) => Math.max(0, (Number(l.base_quantity) - Number(l.base_quantity_received) - Number(l.base_quantity_cancelled)) / Number(l.unit_factor || 1));
const form = useForm<any>({
    supplier_id: props.order?.supplier_id ?? null, purchase_order_id: props.order?.id ?? null, location_id: null, supplier_reference: '', notes: '', is_blind: false,
    lines: (props.order?.lines ?? []).filter((l: any) => outstanding(l) > 0).map((l: any) => ({
        variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, purchase_order_line_id: l.id, name: l.variant?.display_name, sku: l.variant?.sku, unit: l.variant_unit?.unit?.code,
        expected: outstanding(l), quantity_received: String(outstanding(l)), quantity_damaged: '', unit_price: l.unit_price, batch_no: '', expiry_date: '', quality_status: 'accepted', discrepancy_note: '',
    })),
    landed_costs: [] as any[],
});
watch(supplier, (s) => (form.supplier_id = s?.id ?? null));
watch(locations, (l) => { if (!form.location_id && l.length) form.location_id = (l.find((x: any) => x.type === 'warehouse' || x.type === 'godown') ?? l.find((x: any) => x.is_sellable) ?? l[0]).id; }, { immediate: true });
function add(p: any) {
    const u = p.units.find((x: any) => x.is_default) ?? p.units[0];
    form.lines.push({ variant_id: p.variant_id, variant_unit_id: u?.id, units: p.units, purchase_order_line_id: null, name: p.name, sku: p.sku, unit: u?.code, expected: null, quantity_received: '1', quantity_damaged: '', unit_price: '', batch_no: '', expiry_date: '', quality_status: 'accepted', discrepancy_note: '', track_batches: p.track_batches });
}
const goods = computed(() => round2(form.lines.reduce((s: number, l: any) => s + Number(l.quantity_received || 0) * Number(l.unit_price || 0), 0)));
const extra = computed(() => round2(form.landed_costs.reduce((s: number, c: any) => s + Number(c.amount || 0), 0)));
function submit() {
    form.transform((d: any) => ({
        ...d,
        lines: d.lines.map((l: any) => ({ variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, purchase_order_line_id: l.purchase_order_line_id, quantity_received: l.quantity_received || 0, quantity_damaged: l.quantity_damaged || 0, unit_price: l.unit_price || 0, batch_no: l.batch_no || null, expiry_date: l.expiry_date || null, quality_status: l.quality_status, discrepancy_note: l.discrepancy_note || null })),
    })).post(route('purchasing.receipts.store'));
}
</script>

<template>
    <PageHeader :title="order ? `${t('Receive goods for')} ${order.number}` : t('Receive goods')" :subtitle="t('Count what arrived. Damaged items are kept separately and not added to sellable stock.')" />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-4">
        <Field label="Supplier" :error="form.errors.supplier_id" required><div v-if="order" class="py-2 font-medium">{{ order.supplier?.name }}</div><ContactPicker v-else v-model="supplier" type="supplier" /></Field>
        <Field label="Put stock in"><select v-model="form.location_id" class="input"><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></Field>
        <Field label="Supplier bill / delivery note no."><input v-model="form.supplier_reference" class="input" /></Field>
        <Field label="Notes"><input v-model="form.notes" class="input" /></Field>
    </section>
    <section class="panel mb-4">
        <div v-if="!order" class="border-b border-ink-100 p-3"><ProductPicker purpose="purchase" @pick="add" /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr><th>{{ t('Product') }}</th><th v-if="order" class="num">{{ t('Expected') }}</th><th class="w-24">{{ t('Received') }}</th><th class="w-24">{{ t('Damaged') }}</th><th class="w-28">{{ t('Cost per pack') }}</th><th class="w-32">{{ t('Batch') }}</th><th class="w-36">{{ t('Expiry') }}</th><th class="w-32">{{ t('Quality') }}</th><th class="num">{{ t('Amount') }}</th><th class="w-8"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in form.lines" :key="i">
                        <td><div class="font-medium">{{ l.name }}</div><div class="text-[12px] text-ink-500">{{ l.sku }} · <template v-if="l.units?.length > 1"><select v-model="l.variant_unit_id" class="rounded border-ink-200 py-0 text-[12px]"><option v-for="u in l.units" :key="u.id" :value="u.id">{{ u.name }}</option></select></template><template v-else>{{ l.unit }}</template></div>
                            <input v-if="l.expected !== null && Number(l.quantity_received) !== l.expected" v-model="l.discrepancy_note" class="input mt-1 py-1 text-[12.5px]" :placeholder="t('Why different?')" /></td>
                        <td v-if="order" class="num">{{ l.expected !== null ? qty(l.expected) : '—' }}</td>
                        <td><input v-model="l.quantity_received" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                        <td><input v-model="l.quantity_damaged" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                        <td><input v-model="l.unit_price" type="number" min="0" step="any" class="input py-1 text-end" :readonly="!showCost && !!order" /></td>
                        <td><input v-model="l.batch_no" class="input py-1 text-sm" /></td>
                        <td><input v-model="l.expiry_date" type="date" class="input py-1 text-sm" /></td>
                        <td><select v-model="l.quality_status" class="input py-1 text-sm"><option value="accepted">{{ t('OK') }}</option><option value="quarantined">{{ t('Hold to check') }}</option></select></td>
                        <td class="num">{{ money(Number(l.quantity_received || 0) * Number(l.unit_price || 0)) }}</td>
                        <td><button class="text-ink-400 hover:text-ledger-red" @click="form.lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                    <tr v-if="!form.lines.length"><td colspan="10" class="py-8 text-center text-ink-500">{{ t('Add products using the search above.') }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <section class="panel p-4">
            <div class="mb-2 flex items-center justify-between"><h3>{{ t('Freight and other costs') }}</h3><Button size="sm" @click="form.landed_costs.push({ type: 'freight', amount: '', description: '', allocation_method: 'value' })"><Plus class="h-4 w-4" />{{ t('Add cost') }}</Button></div>
            <p class="mb-2 text-[12.5px] text-ink-500">{{ t('Spread over the products so their cost includes delivery. Pay these in Accounting if paid to someone other than the supplier.') }}</p>
            <div v-for="(c, i) in form.landed_costs" :key="i" class="mb-2 flex gap-2">
                <select v-model="c.type" class="input w-32 py-1"><option value="freight">{{ t('Freight') }}</option><option value="loading">{{ t('Loading') }}</option><option value="customs">{{ t('Customs') }}</option><option value="other">{{ t('Other') }}</option></select>
                <input v-model="c.description" class="input flex-1 py-1" :placeholder="t('Description')" />
                <input v-model="c.amount" type="number" min="0" class="input w-28 py-1 text-end" />
                <select v-model="c.allocation_method" class="input w-36 py-1"><option value="value">{{ t('By value') }}</option><option value="quantity">{{ t('By quantity') }}</option></select>
                <button class="text-ink-400 hover:text-ledger-red" @click="form.landed_costs.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
            </div>
        </section>
        <section class="panel space-y-1 p-4 text-sm">
            <div class="flex justify-between"><span>{{ t('Goods') }}</span><span class="tabular-nums">{{ money(goods) }}</span></div>
            <div class="flex justify-between"><span>{{ t('Extra costs') }}</span><span class="tabular-nums">{{ money(extra) }}</span></div>
            <div class="flex justify-between border-t border-ink-100 pt-1 text-base font-semibold"><span>{{ t('Total') }}</span><span class="tabular-nums">{{ money(goods + extra) }}</span></div>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <Button variant="primary" class="mt-2 w-full" :disabled="!form.lines.length || !form.supplier_id" :loading="form.processing" @click="submit">{{ t('Save and add to stock') }}</Button>
        </section>
    </div>
</template>
