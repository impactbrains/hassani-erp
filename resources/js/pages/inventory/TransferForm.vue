<script setup lang="ts">
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { qty } from '@/lib/format';
import { t } from '@/lib/i18n';

const page = usePage<any>();
const { all, here } = useStoreLocations();
const storeName = (id: number) => page.props.auth?.stores?.find((s: any) => s.id === id)?.name ?? '';
const destinations = computed(() => all.value.filter((l: any) => l.type !== 'transit'));
const form = useForm<{ from_location_id: number | null; to_location_id: number | null; note: string; lines: any[] }>({ from_location_id: null, to_location_id: null, note: '', lines: [] });

function add(p: any) {
    const existing = form.lines.find((l) => l.variant_id === p.variant_id);
    if (existing) { existing.quantity = String(Number(existing.quantity || 0) + 1); return; }
    form.lines.push({ variant_id: p.variant_id, name: p.name, sku: p.sku, available: p.available, quantity: '1' });
}
function submit() {
    form.transform((d) => ({ ...d, lines: d.lines.map((l: any) => ({ variant_id: l.variant_id, quantity: l.quantity })) })).post(route('inventory.transfers.store'));
}
</script>

<template>
    <PageHeader :title="t('New transfer')" :subtitle="t('Within the same store the stock moves straight away; to another store it goes “in transit” until received there.')" />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-3">
        <Field label="From" :error="form.errors.from_location_id" required>
            <select v-model="form.from_location_id" class="input"><option :value="null">—</option><option v-for="l in here" :key="l.id" :value="l.id">{{ l.name }}</option></select>
        </Field>
        <Field label="To" :error="form.errors.to_location_id" required>
            <select v-model="form.to_location_id" class="input"><option :value="null">—</option><option v-for="l in destinations" :key="l.id" :value="l.id" :disabled="l.id === form.from_location_id">{{ storeName(l.store_id) }} · {{ l.name }}</option></select>
        </Field>
        <Field label="Note"><input v-model="form.note" class="input" /></Field>
    </section>
    <section class="panel">
        <div class="border-b border-ink-100 p-3"><ProductPicker purpose="any" @pick="add" /></div>
        <table class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('In stock') }}</th><th class="w-32">{{ t('Quantity') }}</th><th class="w-10"></th></tr></thead>
            <tbody>
                <tr v-for="(l, i) in form.lines" :key="i">
                    <td><div class="font-medium">{{ l.name }}</div><div class="text-[12px] text-ink-500">{{ l.sku }}</div></td>
                    <td class="num">{{ qty(l.available) }}</td>
                    <td><input v-model="l.quantity" type="number" min="0" step="any" class="input py-1 text-end" /></td>
                    <td><button type="button" class="text-ink-400 hover:text-ledger-red" @click="form.lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                </tr>
                <tr v-if="!form.lines.length"><td colspan="4" class="py-8 text-center text-ink-500">{{ t('Scan or search products above to add them.') }}</td></tr>
            </tbody>
        </table>
        <div class="flex items-center gap-3 border-t border-ink-100 p-3">
            <span v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</span>
            <div class="ms-auto flex gap-2"><Button :href="route('inventory.transfers.index')">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!form.lines.length" :loading="form.processing" @click="submit">{{ t('Create transfer') }}</Button></div>
        </div>
    </section>
</template>
