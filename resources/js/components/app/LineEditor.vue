<script setup lang="ts">
import { computed } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { money, qty, round2 } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Product lines for documents (orders, purchase orders, stock adjustments,
 * transfers, goods received). Each line keeps the product card it was
 * picked from so the pack-size list and price are at hand. Extra columns
 * come from `fields`; `priceField` names the field that multiplies with
 * quantity for the line total.
 */
interface LineField { key: string; label: string; type: 'number' | 'text' | 'date' | 'select'; options?: { value: any; label: string }[]; width?: string; step?: string; default?: any }
const props = withDefaults(defineProps<{
    modelValue: any[];
    fields?: LineField[];
    showUnit?: boolean;
    priceField?: string | null;
    purpose?: 'sale' | 'purchase' | 'any';
    customerId?: number | null;
    errors?: Record<string, string>;
    readonly?: boolean;
    defaultPrice?: (product: any, unit: any) => string | null;
}>(), { fields: () => [], showUnit: true, priceField: null, purpose: 'sale', customerId: null, errors: () => ({}), readonly: false, defaultPrice: undefined });
const emit = defineEmits<{ 'update:modelValue': [any[]] }>();

const lines = computed(() => props.modelValue);

function add(product: any) {
    const unit = product.units.find((u: any) => u.is_default) ?? product.units[0];
    const existing = lines.value.find((l) => l.variant_id === product.variant_id && l.variant_unit_id === unit?.id);
    if (existing) {
        existing.quantity = String(round2(Number(existing.quantity || 0) + 1));
        emit('update:modelValue', [...lines.value]);
        return;
    }
    const line: any = { variant_id: product.variant_id, variant_unit_id: unit?.id, quantity: '1', product };
    for (const f of props.fields) line[f.key] = f.default ?? '';
    if (props.priceField) line[props.priceField] = props.defaultPrice ? (props.defaultPrice(product, unit) ?? '') : (unit?.price ?? '');
    emit('update:modelValue', [...lines.value, line]);
}
function remove(i: number) {
    const next = [...lines.value];
    next.splice(i, 1);
    emit('update:modelValue', next);
}
const lineTotal = (l: any) => (props.priceField ? round2(Number(l.quantity || 0) * Number(l[props.priceField] || 0)) : null);
const total = computed(() => round2(lines.value.reduce((s, l) => s + (lineTotal(l) ?? 0), 0)));
const err = (i: number, key: string) => props.errors[`lines.${i}.${key}`];
</script>

<template>
    <div class="rounded-lg border border-ink-100 bg-white">
        <div v-if="!readonly" class="border-b border-ink-100 p-3"><ProductPicker :purpose="purpose" :customer-id="customerId" @pick="add" /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th class="w-8">#</th><th>{{ t('Product') }}</th><th v-if="showUnit" class="w-36">{{ t('Pack') }}</th><th class="w-28 text-end">{{ t('Quantity') }}</th>
                        <th v-for="f in fields" :key="f.key" :class="[f.type === 'number' ? 'text-end' : '', f.width]">{{ t(f.label) }}</th>
                        <th v-if="priceField" class="text-end">{{ t('Amount') }}</th><th v-if="!readonly" class="w-10" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in lines" :key="i">
                        <td class="tabular-nums text-ink-400">{{ i + 1 }}</td>
                        <td>
                            <div class="font-medium">{{ l.product?.name ?? l.name }}</div>
                            <div class="text-[12px] text-ink-500">{{ l.product?.sku ?? l.sku }}<template v-if="l.product?.available !== undefined"> · {{ qty(l.product.available) }} {{ t('in stock') }}</template></div>
                            <p v-if="err(i, 'variant_id')" class="text-[12.5px] text-ledger-red">{{ err(i, 'variant_id') }}</p>
                        </td>
                        <td v-if="showUnit">
                            <select v-if="l.product?.units?.length" v-model="l.variant_unit_id" class="input py-1 text-sm" :disabled="readonly">
                                <option v-for="u in l.product.units" :key="u.id" :value="u.id">{{ u.name }}{{ Number(u.factor) !== 1 ? ` (${qty(u.factor)})` : '' }}</option>
                            </select>
                            <span v-else class="text-sm text-ink-600">{{ l.unit_name ?? '—' }}</span>
                        </td>
                        <td><input v-model="l.quantity" type="number" step="any" class="input py-1 text-end tabular-nums" :class="err(i, 'quantity') ? 'border-ledger-red' : ''" :disabled="readonly" /></td>
                        <td v-for="f in fields" :key="f.key">
                            <select v-if="f.type === 'select'" v-model="l[f.key]" class="input py-1 text-sm" :disabled="readonly"><option :value="null">—</option><option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option></select>
                            <input v-else v-model="l[f.key]" :type="f.type" :step="f.step ?? 'any'" class="input py-1 text-sm" :class="[f.type === 'number' ? 'text-end tabular-nums' : '', err(i, f.key) ? 'border-ledger-red' : '']" :disabled="readonly" />
                        </td>
                        <td v-if="priceField" class="num font-medium">{{ money(lineTotal(l)) }}</td>
                        <td v-if="!readonly"><button type="button" class="rounded p-1 text-ink-400 hover:bg-ledger-redSoft hover:text-ledger-red" :aria-label="t('Remove')" @click="remove(i)"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                    <tr v-if="!lines.length"><td :colspan="5 + fields.length" class="py-8 text-center text-ink-500">{{ t('Add products using the search above.') }}</td></tr>
                </tbody>
                <tfoot v-if="priceField && lines.length">
                    <tr><td :colspan="(showUnit ? 4 : 3) + fields.length" class="px-3 py-2 text-end font-medium">{{ t('Total') }}</td><td class="num px-3 py-2 text-base font-semibold">{{ money(total) }}</td><td v-if="!readonly" /></tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
