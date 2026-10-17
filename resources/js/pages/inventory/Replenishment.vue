<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { qty } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ suggestions: any[]; policies: any; filters: Record<string, any> }>();
const tab = ref('suggestions');
const showAll = ref(props.filters.all === '1');
const editing = ref(false);
const productName = ref('');
const form = useForm({ variant_id: null as number | null, reorder_point: '', min_quantity: '', max_quantity: '', safety_stock: '', lead_time_days: 3 as number | null, order_multiple: '', is_active: true });

function toggleAll() {
    router.get(route('inventory.replenishment'), showAll.value ? { all: '1' } : {}, { preserveState: true, preserveScroll: true, only: ['suggestions', 'filters'] });
}
function edit(p: any | null, name = '') {
    form.reset();
    form.clearErrors();
    if (p) {
        Object.assign(form, { variant_id: p.variant_id, reorder_point: p.reorder_point, min_quantity: p.min_quantity ?? '', max_quantity: p.max_quantity, safety_stock: p.safety_stock ?? '', lead_time_days: p.lead_time_days, order_multiple: p.order_multiple ?? '', is_active: p.is_active });
        productName.value = p.variant?.display_name ?? name;
    } else {
        productName.value = '';
    }
    editing.value = true;
}
function fromSuggestion(s: any) {
    edit(null);
    form.variant_id = s.variant_id;
    productName.value = s.name;
    form.reorder_point = s.reorder_point;
    form.safety_stock = s.safety_stock;
    form.lead_time_days = s.lead_time_days;
    form.max_quantity = String(Math.ceil(Number(s.reorder_point) + Number(s.suggested_quantity)));
}
const picked = ref<number[]>([]);
/** Opens a purchase order with the ticked suggestions (one supplier at a time). */
function makeOrder() {
    const rows = props.suggestions.filter((s) => picked.value.includes(s.variant_id));
    const first = rows.find((r) => r.supplier_id);
    const lines = rows.map((r) => {
        const factor = Number(r.purchase_factor || 1);
        return { variant_id: r.variant_id, variant_unit_id: r.purchase_unit_id, quantity: String(Math.max(1, Math.ceil(Number(r.suggested_quantity) / factor))), unit_price: '', name: r.name, sku: r.sku, unit_name: r.purchase_unit ?? r.unit };
    }).filter((l) => l.variant_unit_id);
    router.visit(route('purchasing.orders.create', { prefill: JSON.stringify({ supplier: first ? { id: first.supplier_id, name: first.supplier_name } : null, lines }) }));
}
function save() {
    form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]))).post(route('inventory.replenishment.save'), { onSuccess: () => (editing.value = false), preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('Reorder')" :subtitle="t('What to buy, based on recent sales, lead time and what is already on order.')" />
    <Tabs v-model="tab" :tabs="[{ key: 'suggestions', label: 'Suggestions', count: suggestions.length }, { key: 'rules', label: 'Reorder rules', count: policies.total }]" />

    <template v-if="tab === 'suggestions'">
        <div class="mb-3 flex items-center gap-3">
            <label class="flex items-center gap-2 text-sm"><input v-model="showAll" type="checkbox" class="rounded border-ink-300" @change="toggleAll" />{{ t('Show all products, not only those to reorder') }}</label>
            <Button v-if="picked.length" class="ms-auto" variant="primary" @click="makeOrder">{{ t('Make purchase order') }} ({{ picked.length }})</Button>
        </div>
        <section class="panel">
            <table class="table-base">
                <thead><tr><th class="w-8"></th><th>{{ t('Product') }}</th><th class="num">{{ t('Available') }}</th><th class="num">{{ t('On order') }}</th><th class="num">{{ t('Sells per day') }}</th><th class="num">{{ t('Days of cover') }}</th><th class="num">{{ t('Reorder at') }}</th><th class="num">{{ t('Suggested order') }}</th><th>{{ t('Supplier') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="s in suggestions" :key="s.variant_id">
                        <td><input v-model="picked" type="checkbox" :value="s.variant_id" class="rounded border-ink-300" :disabled="!s.purchase_unit_id" :title="s.purchase_unit_id ? '' : t('Link a supplier to this product first')" /></td>
                        <td><div class="font-medium">{{ s.name }}</div><div class="text-[12px] text-ink-500">{{ s.sku }} <Badge v-if="s.stockout_risk" tone="bad" :label="t('May run out')" /></div></td>
                        <td class="num">{{ qty(s.available) }} {{ s.unit }}</td>
                        <td class="num">{{ qty(s.on_order) }}</td>
                        <td class="num">{{ qty(s.daily_demand) }}<div class="text-[11px] text-ink-500">{{ t(s.demand_source) }}</div></td>
                        <td class="num" :class="s.stockout_risk ? 'font-semibold text-ledger-red' : ''">{{ s.days_of_cover ?? '—' }}</td>
                        <td class="num">{{ qty(s.reorder_point) }}</td>
                        <td class="num font-semibold">{{ qty(s.suggested_quantity) }}<div v-if="s.purchase_unit && Number(s.purchase_factor) > 1" class="text-[11px] font-normal text-ink-500">= {{ qty(Number(s.suggested_quantity) / Number(s.purchase_factor)) }} {{ s.purchase_unit }}</div></td>
                        <td>{{ s.supplier_name ?? '—' }}</td>
                        <td><Button size="sm" @click="fromSuggestion(s)">{{ t('Set rule') }}</Button></td>
                    </tr>
                    <tr v-if="!suggestions.length"><td colspan="10" class="py-8 text-center text-ink-500">{{ t('Nothing needs reordering right now.') }}</td></tr>
                </tbody>
            </table>
        </section>
    </template>

    <template v-else>
        <div class="mb-3"><Button variant="primary" @click="edit(null)">{{ t('Add reorder rule') }}</Button></div>
        <section class="panel">
            <table class="table-base">
                <thead><tr><th>{{ t('Product') }}</th><th class="num">{{ t('Reorder at') }}</th><th class="num">{{ t('Fill up to') }}</th><th class="num">{{ t('Safety stock') }}</th><th class="num">{{ t('Lead time (days)') }}</th><th>{{ t('Supplier') }}</th><th>{{ t('Active') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="p in policies.data" :key="p.id">
                        <td>{{ p.variant?.display_name }}</td><td class="num">{{ qty(p.reorder_point) }}</td><td class="num">{{ qty(p.max_quantity) }}</td><td class="num">{{ qty(p.safety_stock ?? 0) }}</td>
                        <td class="num">{{ p.lead_time_days ?? '—' }}</td><td>{{ p.preferred_supplier?.name ?? '—' }}</td><td>{{ p.is_active ? t('Yes') : t('No') }}</td>
                        <td><Button size="sm" @click="edit(p)">{{ t('Edit') }}</Button></td>
                    </tr>
                    <tr v-if="!policies.data.length"><td colspan="8" class="py-8 text-center text-ink-500">{{ t('No rules yet. Without a rule, suggestions use recent sales.') }}</td></tr>
                </tbody>
            </table>
            <Pagination :meta="policies" />
        </section>
    </template>

    <Modal :open="editing" :title="t('Reorder rule')" size="md" @close="editing = false">
        <div class="space-y-3">
            <div v-if="productName" class="font-medium">{{ productName }}</div>
            <ProductPicker v-else purpose="any" @pick="(p: any) => { form.variant_id = p.variant_id; productName = p.name; }" />
            <div class="grid grid-cols-2 gap-3">
                <Field label="Reorder when stock falls to" :error="form.errors.reorder_point" required><input v-model="form.reorder_point" type="number" min="0" step="any" class="input" /></Field>
                <Field label="Fill up to" :error="form.errors.max_quantity" required><input v-model="form.max_quantity" type="number" min="0" step="any" class="input" /></Field>
                <Field label="Safety stock" :error="form.errors.safety_stock"><input v-model="form.safety_stock" type="number" min="0" step="any" class="input" /></Field>
                <Field label="Lead time (days)" :error="form.errors.lead_time_days"><input v-model="form.lead_time_days" type="number" min="0" class="input" /></Field>
                <Field label="Order in multiples of" :error="form.errors.order_multiple"><input v-model="form.order_multiple" type="number" min="0" step="any" class="input" /></Field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('Active') }}</label>
        </div>
        <template #footer><Button @click="editing = false">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!form.variant_id" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
