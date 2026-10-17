<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Field from '@/components/form/Field.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { money, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';
import { can } from '@/lib/perms';

/**
 * One form for every kind of adjustment. Quantities are typed as positive
 * numbers; a write-off or quarantine takes them out, opening stock adds
 * them, and a correction can be + or −.
 */
const props = defineProps<{ kind: string }>();
const kind = ref(props.kind);
const tabs = [
    { key: 'opening', label: 'Opening stock' }, { key: 'write_off', label: 'Write-off' }, { key: 'adjustment', label: 'Correction' },
    { key: 'quarantine', label: 'Quarantine' }, { key: 'release', label: 'Release from quarantine' },
];
const help: Record<string, string> = {
    opening: 'Stock you already had before using Hassani ERP. Give what each unit cost you, so profit reports are right.',
    write_off: 'Damaged, expired, leaked or missing stock. It is taken out of stock and booked as a loss.',
    adjustment: 'Fix a wrong quantity. Type a minus number to reduce, a plus number to add.',
    quarantine: 'Hold stock that must not be sold until checked (e.g. a suspected bad batch).',
    release: 'Put quarantined stock back on sale.',
};
const reasonType = computed(() => (kind.value === 'write_off' ? 'write_off' : 'adjustment'));
const options = useOptions(['reasons:write_off', 'reasons:adjustment']);
const reasons = computed<any[]>(() => options[`reasons:${reasonType.value}`] ?? []);
const { here: locations } = useStoreLocations();
const defaultLocation = computed(() => locations.value.find((l: any) => l.is_sellable)?.id ?? locations.value[0]?.id ?? null);

const form = useForm<{ kind: string; reason_code_id: number | null; note: string; lines: any[] }>({ kind: kind.value, reason_code_id: null, note: '', lines: [] });
watch(kind, (k) => {
    form.kind = k;
    form.reason_code_id = null;
    router.replace({ url: route('inventory.adjustments.create', { kind: k }), preserveState: true });
});
const showCost = can('catalog.costs.view');

function add(p: any) {
    const existing = form.lines.find((l) => l.variant_id === p.variant_id);
    if (existing) { existing.quantity = String(Number(existing.quantity || 0) + 1); return; }
    form.lines.push({ variant_id: p.variant_id, name: p.name, sku: p.sku, unit: p.units?.find((u: any) => u.is_base)?.code ?? p.units?.[0]?.code ?? '', available: p.available, location_id: defaultLocation.value, quantity: '1', unit_cost: p.cost ?? '', batch_no: '', expiry_date: '', note: '' });
}
const total = computed(() => form.lines.reduce((s, l) => s + Math.abs(Number(l.quantity || 0)) * Number(l.unit_cost || 0), 0));

function submit() {
    form.transform((d) => ({
        ...d,
        lines: d.lines.map((l: any) => ({
            variant_id: l.variant_id, location_id: l.location_id, quantity: l.quantity,
            unit_cost: d.kind === 'opening' || (d.kind === 'adjustment' && Number(l.quantity) > 0) ? l.unit_cost || null : null,
            batch_no: l.batch_no || null, expiry_date: l.expiry_date || null, note: l.note || null,
        })),
    })).post(route('inventory.adjustments.store'));
}
</script>

<template>
    <PageHeader :title="t('New stock adjustment')" />
    <Tabs v-model="kind" :tabs="tabs" />
    <p class="mb-4 text-sm text-ink-600">{{ t(help[kind]) }}</p>

    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-2">
        <Field :label="kind === 'write_off' || kind === 'adjustment' ? 'Reason' : 'Reason (optional)'" :error="form.errors.reason_code_id" :required="kind === 'write_off' || kind === 'adjustment'">
            <select v-model="form.reason_code_id" class="input"><option :value="null">—</option><option v-for="r in reasons" :key="r.id" :value="r.id">{{ localName(r) }}</option></select>
        </Field>
        <Field label="Note" :error="form.errors.note"><input v-model="form.note" class="input" :placeholder="t('What happened? Who found it?')" /></Field>
    </section>

    <section class="panel">
        <div class="border-b border-ink-100 p-3"><ProductPicker purpose="any" @pick="add" /></div>
        <table class="table-base">
            <thead>
                <tr>
                    <th>{{ t('Product') }}</th><th>{{ t('Location') }}</th><th class="w-32">{{ t('Quantity') }}</th>
                    <th v-if="kind === 'opening' || kind === 'adjustment'" class="w-32">{{ t('Cost per unit') }}</th>
                    <th v-if="kind === 'opening'">{{ t('Batch / expiry (optional)') }}</th>
                    <th class="w-10"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(l, i) in form.lines" :key="i">
                    <td><div class="font-medium">{{ l.name }}</div><div class="text-[12px] text-ink-500">{{ l.sku }} · {{ t('in stock') }} {{ qty(l.available) }}</div></td>
                    <td><select v-model="l.location_id" class="input py-1 text-sm"><option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.name }}</option></select></td>
                    <td><input v-model="l.quantity" type="number" step="any" class="input py-1 text-end" :min="kind === 'adjustment' ? undefined : 0" /><div class="text-[11px] text-ink-500">{{ l.unit }}</div></td>
                    <td v-if="kind === 'opening' || kind === 'adjustment'"><input v-if="showCost || kind === 'opening'" v-model="l.unit_cost" type="number" step="any" min="0" class="input py-1 text-end" :required="kind === 'opening'" /></td>
                    <td v-if="kind === 'opening'" class="space-y-1"><input v-model="l.batch_no" class="input py-1 text-sm" :placeholder="t('Batch no.')" /><input v-model="l.expiry_date" type="date" class="input py-1 text-sm" /></td>
                    <td><button type="button" class="text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="form.lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                </tr>
                <tr v-if="!form.lines.length"><td colspan="6" class="py-8 text-center text-ink-500">{{ t('Scan or search products above to add them.') }}</td></tr>
            </tbody>
        </table>
        <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 p-3">
            <span v-if="showCost && total" class="text-sm text-ink-600">{{ t('Value') }}: <b class="tabular-nums">{{ money(total) }}</b></span>
            <span v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</span>
            <div class="ms-auto flex gap-2">
                <Button :href="route('inventory.adjustments.index')">{{ t('Cancel') }}</Button>
                <Button variant="primary" :disabled="!form.lines.length" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
            </div>
        </div>
    </section>
</template>
