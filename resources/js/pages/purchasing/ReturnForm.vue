<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { date, money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Send goods back to a supplier (damaged, expired, wrong item). Stock goes out at what it cost and the supplier owes you that amount. */
const props = defineProps<{ supplierId: number | null; supplier?: any | null; lines: any[]; reasons: any[] }>();
const { here: locations } = useStoreLocations();
const supplier = ref<any>(props.supplier ?? null);
watch(supplier, (s) => { if (s?.id && s.id !== props.supplierId) router.get(route('purchasing.returns.create'), { supplier_id: s.id }); });
const returnable = (l: any) => Number(l.base_quantity_accepted) - Number(l.base_quantity_returned ?? 0);
const qtys = ref<Record<number, string>>({});
const locs = ref<Record<number, number | null>>({});
const defaultLoc = computed(() => (locations.value.find((l: any) => l.type === 'damaged') ?? locations.value.find((l: any) => l.is_sellable) ?? locations.value[0])?.id ?? null);
const form = useForm<any>({ supplier_id: props.supplierId, goods_receipt_id: null, reason_code_id: props.reasons[0]?.id ?? null, notes: '', lines: [] });
const chosen = computed(() => props.lines.filter((l) => Number(qtys.value[l.id]) > 0));
const value = computed(() => chosen.value.reduce((s, l) => s + Number(qtys.value[l.id]) * Number(l.base_unit_cost || 0), 0));
function submit() {
    form.lines = chosen.value.map((l) => ({ goods_receipt_line_id: l.id, location_id: locs.value[l.id] ?? defaultLoc.value, base_quantity: qtys.value[l.id] }));
    form.post(route('purchasing.returns.store'));
}
</script>

<template>
    <PageHeader :title="t('Return goods to supplier')" />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-3">
        <Field label="Supplier" required><ContactPicker v-model="supplier" type="supplier" /></Field>
        <Field label="Reason"><select v-model="form.reason_code_id" class="input"><option :value="null">—</option><option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.label }}</option></select></Field>
        <Field label="Notes"><input v-model="form.notes" class="input" /></Field>
    </section>
    <section v-if="supplierId" class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th>{{ t('Received on') }}</th><th>{{ t('Batch') }}</th><th class="num">{{ t('Can return (units)') }}</th><th class="num">{{ t('Cost per unit') }}</th><th class="w-28">{{ t('Return (units)') }}</th><th class="w-40">{{ t('Take from') }}</th></tr></thead>
            <tbody>
                <tr v-for="l in lines.filter((x) => returnable(x) > 0)" :key="l.id">
                    <td><div class="font-medium">{{ l.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ l.variant?.sku }}</div></td>
                    <td>{{ l.receipt?.number }} · {{ date(l.receipt?.business_date) }}</td><td>{{ l.batch?.batch_no ?? l.batch_no ?? '—' }}</td>
                    <td class="num">{{ qty(returnable(l)) }}</td><td class="num">{{ money(l.base_unit_cost) }}</td>
                    <td><input v-model="qtys[l.id]" type="number" min="0" :max="returnable(l)" step="any" class="input py-1 text-end" /></td>
                    <td><select v-model="locs[l.id]" class="input py-1 text-sm"><option :value="undefined">{{ locations.find((x: any) => x.id === defaultLoc)?.name }}</option><option v-for="x in locations" :key="x.id" :value="x.id">{{ x.name }}</option></select></td>
                </tr>
                <tr v-if="!lines.length"><td colspan="7" class="py-8 text-center text-ink-500">{{ t('Nothing received from this supplier in the last 180 days.') }}</td></tr>
            </tbody>
        </table>
        <div class="flex items-center gap-3 border-t border-ink-100 p-3">
            <span class="text-sm">{{ t('Value') }}: <b class="tabular-nums">{{ money(value) }}</b></span>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <Button class="ms-auto" variant="primary" :disabled="!chosen.length" :loading="form.processing" @click="submit">{{ t('Post return') }}</Button>
        </div>
    </section>
    <p v-else class="panel p-6 text-center text-ink-500">{{ t('Choose the supplier first.') }}</p>
</template>
