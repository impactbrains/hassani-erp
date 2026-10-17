<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { date, money, qty, round2, today } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Enter the supplier's bill. Received goods not yet billed are listed; tick them and check the prices match. Other charges go to an expense account. */
const props = defineProps<{ supplierId: number | null; supplier?: any | null; unbilled: any[] }>();
const options = useOptions(['accounts']);
const supplier = ref<any>(props.supplier ?? null);
watch(supplier, (s) => {
    if (s?.id && s.id !== props.supplierId) router.get(route('purchasing.bills.create'), { supplier_id: s.id });
});
const remaining = (l: any) => Number(l.quantity_received) - Number(l.base_quantity_billed) / Number(l.unit_factor || 1);
const form = useForm<any>({
    supplier_id: props.supplierId, supplier_invoice_no: '', bill_date: today(), due_date: '', notes: '',
    lines: props.unbilled.map((l: any) => ({ pick: true, goods_receipt_line_id: l.id, account_id: null, description: `${l.variant?.display_name} (${l.receipt?.number})`, quantity: String(remaining(l)), unit_price: l.unit_price, tax_amount: '', unit: l.variant_unit?.unit?.code, grn_price: l.unit_price, receipt: l.receipt })),
});
const expenseAccounts = computed(() => (options.accounts ?? []).filter((a: any) => ['expense', 'asset'].includes(a.type)));
const chosen = computed(() => form.lines.filter((l: any) => l.pick));
const total = computed(() => round2(chosen.value.reduce((s: number, l: any) => s + Number(l.quantity || 0) * Number(l.unit_price || 0) + Number(l.tax_amount || 0), 0)));
function submit() {
    form.transform((d: any) => ({ ...d, due_date: d.due_date || null, lines: d.lines.filter((l: any) => l.pick).map((l: any) => ({ goods_receipt_line_id: l.goods_receipt_line_id, account_id: l.account_id, description: l.description, quantity: l.quantity, unit_price: l.unit_price, tax_amount: l.tax_amount || 0 })) })).post(route('purchasing.bills.store'));
}
</script>

<template>
    <PageHeader :title="t('Enter supplier bill')" />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-5">
        <Field label="Supplier" :error="form.errors.supplier_id" required><ContactPicker v-model="supplier" type="supplier" /></Field>
        <Field label="Supplier invoice no."><input v-model="form.supplier_invoice_no" class="input" /></Field>
        <Field label="Bill date" :error="form.errors.bill_date"><input v-model="form.bill_date" type="date" class="input" /></Field>
        <Field label="Due date" :error="form.errors.due_date"><input v-model="form.due_date" type="date" class="input" /></Field>
        <Field label="Notes"><input v-model="form.notes" class="input" /></Field>
    </section>
    <section v-if="supplierId" class="panel mb-4">
        <table class="table-base">
            <thead><tr><th class="w-8"></th><th>{{ t('Item / charge') }}</th><th class="w-28">{{ t('Quantity') }}</th><th class="w-32">{{ t('Price on bill') }}</th><th class="w-28">{{ t('Tax') }}</th><th class="num">{{ t('Amount') }}</th><th class="w-8"></th></tr></thead>
            <tbody>
                <tr v-for="(l, i) in form.lines" :key="i" :class="l.pick ? '' : 'opacity-50'">
                    <td><input v-model="l.pick" type="checkbox" class="rounded border-ink-300" /></td>
                    <td>
                        <template v-if="l.goods_receipt_line_id"><div class="font-medium">{{ l.description }}</div><div class="text-[12px] text-ink-500">{{ date(l.receipt?.business_date) }} · {{ l.unit }}</div>
                            <div v-if="Number(l.unit_price) !== Number(l.grn_price)" class="text-[12px] font-medium text-haldi-800">{{ t('Different from received price') }} {{ money(l.grn_price) }}</div></template>
                        <div v-else class="flex gap-2"><select v-model="l.account_id" class="input w-56 py-1 text-sm"><option :value="null">{{ t('Expense account') }}</option><option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option></select><input v-model="l.description" class="input flex-1 py-1 text-sm" :placeholder="t('Description')" /></div>
                    </td>
                    <td><input v-model="l.quantity" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                    <td><input v-model="l.unit_price" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                    <td><input v-model="l.tax_amount" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                    <td class="num">{{ money(Number(l.quantity || 0) * Number(l.unit_price || 0) + Number(l.tax_amount || 0)) }}</td>
                    <td><button v-if="!l.goods_receipt_line_id" class="text-ink-400 hover:text-ledger-red" @click="form.lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                </tr>
                <tr v-if="!unbilled.length"><td colspan="7" class="py-4 text-center text-sm text-ink-500">{{ t('Nothing received from this supplier is waiting for a bill.') }} {{ qty(0) && '' }}</td></tr>
            </tbody>
            <tfoot><tr><td colspan="5" class="px-3 py-2 text-end font-semibold">{{ t('Bill total') }}</td><td class="num px-3 py-2 text-base font-semibold">{{ money(total) }}</td><td></td></tr></tfoot>
        </table>
        <div class="flex items-center gap-3 border-t border-ink-100 p-3">
            <Button size="sm" @click="form.lines.push({ pick: true, goods_receipt_line_id: null, account_id: null, description: '', quantity: '1', unit_price: '', tax_amount: '' })"><Plus class="h-4 w-4" />{{ t('Add other charge') }}</Button>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <Button class="ms-auto" variant="primary" :disabled="!chosen.length" :loading="form.processing" @click="submit">{{ t('Post bill') }}</Button>
        </div>
    </section>
    <p v-else class="panel p-6 text-center text-ink-500">{{ t('Choose the supplier first.') }}</p>
</template>
