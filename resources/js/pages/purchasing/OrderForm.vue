<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import LineEditor from '@/components/app/LineEditor.vue';
import { round2 } from '@/lib/format';
import { t } from '@/lib/i18n';

/** A new line starts at the product's Purchase price (or what was paid last time), for the pack chosen. */
const startingCost = (p: any, u: any) => {
    const perUnit = p.purchase_price ?? p.last_cost?.per_unit ?? null;
    return perUnit !== null ? String(round2(Number(perUnit) * Number(u?.factor ?? 1))) : '';
};

/** prefill (from Reorder): { supplier?: {id,name}, lines: [{variant_id, variant_unit_id, quantity, unit_price?, name, sku, unit_name}] } */
const props = defineProps<{ order: any | null; prefill: any }>();
const o = props.order;
const pre = Array.isArray(props.prefill) ? { lines: props.prefill } : (props.prefill ?? {});
const supplier = ref<any>(o?.supplier ?? pre.supplier ?? null);
const form = useForm<any>({
    supplier_id: o?.supplier_id ?? pre.supplier?.id ?? null,
    expected_date: o?.expected_date ? String(o.expected_date).slice(0, 10) : '',
    notes: o?.notes ?? '',
    source: o?.source ?? (pre.lines?.length ? 'replenishment' : 'manual'),
    lines: o ? o.lines.map((l: any) => ({ variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, quantity: l.quantity, unit_price: l.unit_price, tax_rate: l.tax_rate ?? '0', name: l.variant?.display_name, sku: l.variant?.sku, unit_name: l.variant_unit?.unit?.code }))
        : (pre.lines ?? []).map((l: any) => ({ tax_rate: '0', unit_price: '', ...l })),
});
watch(supplier, (s) => (form.supplier_id = s?.id ?? null));
function submit() {
    form.transform((d: any) => ({ ...d, expected_date: d.expected_date || null, lines: d.lines.map((l: any) => ({ variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, quantity: l.quantity, unit_price: l.unit_price || 0, tax_rate: l.tax_rate || 0 })) }));
    o ? form.put(route('purchasing.orders.update', o.id)) : form.post(route('purchasing.orders.store'));
}
</script>

<template>
    <PageHeader :title="o ? `${t('Edit')} ${o.number}` : t('New purchase order')" :subtitle="t('Type the cost price per pack you agreed with the supplier.')" />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-3">
        <Field label="Supplier" :error="form.errors.supplier_id" required><ContactPicker v-model="supplier" type="supplier" /></Field>
        <Field label="Expected delivery"><input v-model="form.expected_date" type="date" class="input" /></Field>
        <Field label="Notes"><input v-model="form.notes" class="input" /></Field>
    </section>
    <LineEditor v-model="form.lines" purpose="purchase" price-field="unit_price" :default-price="startingCost" :errors="form.errors" :fields="[{ key: 'unit_price', label: 'Cost per pack', type: 'number', width: 'w-28' }, { key: 'tax_rate', label: 'Tax %', type: 'number', width: 'w-20', default: '0' }]" />
    <div class="mt-4 flex items-center gap-2">
        <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        <div class="ms-auto flex gap-2"><Button :href="o ? route('purchasing.orders.show', o.id) : route('purchasing.orders.index')">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!form.lines.length || !form.supplier_id" :loading="form.processing" @click="submit">{{ t('Save') }}</Button></div>
    </div>
</template>
