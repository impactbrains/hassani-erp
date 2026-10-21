<script setup lang="ts">
import { computed, ref } from 'vue';
import { Plus, Trash2, X } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { money } from '@/lib/format';
import { localName, t } from '@/lib/i18n';
import { blankCondition, CONDITION_TYPES, CUSTOMER_TYPES, describeRule, OPS, type Condition, type Rule, type RuleOptions } from '@/lib/priceRules';

/**
 * Edits one price rule: "When [all / any] of these are true … charge [wholesale / a fixed price / % off]".
 * On a product (`packs` given) a fixed price can be set per pack; shared rules (category, brand, all
 * products) can only use the wholesale price or a percentage off.
 */
const props = withDefaults(defineProps<{
    modelValue: Rule;
    options: RuleOptions;
    /** The product's packs ({ unit_id, code, name, factor }); empty for shared rules. */
    packs?: { unit_id: number; code: string; name: string; factor: number }[];
    baseUnit?: string;
    /** Purchase price per base unit, to warn when a fixed price is below it. */
    purchasePerBase?: number | null;
    readonly?: boolean;
    errors?: string | null;
}>(), { packs: () => [], baseUnit: '', purchasePerBase: null, readonly: false, errors: null });
const emit = defineEmits<{ remove: [] }>();
const rule = computed(() => props.modelValue);
const onProduct = computed(() => props.packs.length > 0);
const types = computed(() => CONDITION_TYPES);
const quantityUnits = computed(() => (onProduct.value ? props.packs.map((p) => ({ id: p.unit_id, code: p.code, name: p.name })) : props.options.units));
// Names of customers picked in this session, so they show before the page is reloaded.
const pickedCustomers = ref<any[]>([]);
const customerName = (id: number) => localName([...props.options.customers, ...pickedCustomers.value].find((c) => c.id === id)) || `#${id}`;

function setType(c: Condition, type: Condition['type']) {
    Object.keys(c).forEach((k) => delete (c as any)[k]);
    Object.assign(c, blankCondition(type));
}
function toggle(list: any[], value: any) {
    const i = list.indexOf(value);
    i === -1 ? list.push(value) : list.splice(i, 1);
}
function addCustomer(c: Condition, contact: any) {
    if (!contact) return;
    c.ids ??= [];
    if (!c.ids.includes(contact.id)) c.ids.push(contact.id);
    pickedCustomers.value.push(contact);
}
const customerPick = ref<any>(null);

/** A fixed price below what you pay for it. */
const fixedBelowCost = computed(() => {
    if (rule.value.action !== 'fixed' || !props.purchasePerBase || !rule.value.value) return false;
    const factor = props.packs.find((p) => p.unit_id === rule.value.unit_id)?.factor ?? 1;
    return Number(rule.value.value) < props.purchasePerBase * factor;
});
</script>

<template>
    <div class="rounded-lg border bg-white" :class="rule.is_active ? 'border-ink-200' : 'border-dashed border-ink-200 opacity-75'">
        <div class="flex flex-wrap items-start gap-3 border-b border-ink-100 px-3 py-2">
            <p class="min-w-0 flex-1 text-sm font-medium text-ink-900">{{ describeRule(rule, options, baseUnit) }}</p>
            <label v-if="!readonly" class="flex items-center gap-1.5 text-[13px] text-ink-600"><input v-model="rule.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('On') }}</label>
            <button v-if="!readonly" type="button" class="inline-flex items-center gap-1 text-[13px] text-ledger-red" @click="emit('remove')"><Trash2 class="h-3.5 w-3.5" />{{ t('Remove rule') }}</button>
        </div>

        <fieldset v-if="!readonly" class="space-y-3 p-3 text-sm">
            <!-- When -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ t('Use this rule when') }}</span>
                <select v-model="rule.match" class="input w-auto py-1 pe-9 text-sm">
                    <option value="all">{{ t('all of these are true') }}</option>
                    <option value="any">{{ t('any one of these is true') }}</option>
                </select>
            </div>
            <p v-if="!rule.conditions.length" class="text-[13px] text-ink-500">{{ t('No conditions: this rule always applies.') }}</p>
            <div v-for="(c, ci) in rule.conditions" :key="ci" class="flex flex-wrap items-start gap-2 rounded bg-ink-50 p-2">
                <select :value="c.type" class="input w-auto py-1 pe-9 text-sm" :aria-label="t('Condition')" @change="setType(c, ($event.target as HTMLSelectElement).value as any)">
                    <option v-for="x in types" :key="x.type" :value="x.type">{{ t(x.label) }}</option>
                </select>

                <template v-if="c.type === 'quantity' || c.type === 'line_value' || c.type === 'bill_total'">
                    <select v-model="c.op" class="input w-auto py-1 pe-9 text-sm" :aria-label="t('Comparison')"><option v-for="(label, op) in OPS" :key="op" :value="op">{{ t(label) }}</option></select>
                    <span v-if="c.type !== 'quantity'" class="py-1.5 text-ink-500">Rs</span>
                    <input v-model="c.value" type="number" min="0" step="any" class="input w-24 py-1 text-end text-sm tabular-nums" :aria-label="t('Value')" />
                    <select v-if="c.type === 'quantity'" v-model="c.unit_id" class="input w-auto py-1 pe-9 text-sm" :aria-label="t('Counted in')">
                        <option :value="null">{{ baseUnit || t('base unit') }}</option>
                        <option v-for="u in quantityUnits.filter((x: any) => !onProduct || x.code !== baseUnit)" :key="u.id" :value="u.id">{{ u.code }}</option>
                    </select>
                </template>

                <div v-else-if="c.type === 'customer_type'" class="flex flex-wrap gap-x-3 gap-y-1 py-1">
                    <label v-for="(label, key) in CUSTOMER_TYPES" :key="key" class="flex items-center gap-1.5"><input type="checkbox" class="rounded border-ink-300" :checked="c.values?.includes(key)" @change="toggle(c.values!, key)" />{{ t(label) }}</label>
                </div>

                <div v-else-if="c.type === 'customer'" class="min-w-[16rem] flex-1 space-y-1.5">
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="id in c.ids" :key="id" class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[13px] ring-1 ring-ink-200">{{ customerName(id) }}<button type="button" :aria-label="t('Remove')" @click="c.ids!.splice(c.ids!.indexOf(id), 1)"><X class="h-3 w-3" /></button></span>
                    </div>
                    <ContactPicker v-model="customerPick" type="customer" :placeholder="t('Search customers to add')" @update:model-value="(v: any) => { addCustomer(c, v); customerPick = null; }" />
                </div>

                <div v-else-if="c.type === 'price_list'" class="flex flex-wrap gap-x-3 gap-y-1 py-1">
                    <label v-for="b in options.priceBooks" :key="b.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded border-ink-300" :checked="c.ids?.includes(b.id)" @change="toggle(c.ids!, b.id)" />{{ localName(b) }}</label>
                </div>

                <div v-else-if="c.type === 'store'" class="flex flex-wrap gap-x-3 gap-y-1 py-1">
                    <label v-for="s in options.stores" :key="s.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded border-ink-300" :checked="c.ids?.includes(s.id)" @change="toggle(c.ids!, s.id)" />{{ localName(s) }}</label>
                </div>

                <div v-else-if="c.type === 'pack'" class="flex flex-wrap gap-x-3 gap-y-1 py-1">
                    <label v-for="u in quantityUnits" :key="u.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded border-ink-300" :checked="c.unit_ids?.includes(u.id)" @change="toggle(c.unit_ids!, u.id)" />{{ u.code }}</label>
                </div>

                <div v-else-if="c.type === 'date'" class="flex flex-wrap items-center gap-2">
                    <span class="text-ink-500">{{ t('from') }}</span><input v-model="c.from" type="date" class="input w-auto py-1 text-sm" />
                    <span class="text-ink-500">{{ t('to') }}</span><input v-model="c.to" type="date" class="input w-auto py-1 text-sm" />
                    <span class="text-[12.5px] text-ink-500">{{ t('Leave one empty for no start or no end.') }}</span>
                </div>

                <button type="button" class="ms-auto rounded p-1 text-ink-400 hover:text-ledger-red" :aria-label="t('Remove condition')" @click="rule.conditions.splice(ci, 1)"><X class="h-4 w-4" /></button>
            </div>
            <Button size="sm" @click="rule.conditions.push(blankCondition('quantity'))"><Plus class="h-4 w-4" />{{ t('Add a condition') }}</Button>

            <!-- Then -->
            <div class="space-y-1.5 border-t border-ink-100 pt-3">
                <p class="font-medium">{{ t('Charge') }}</p>
                <label class="flex items-center gap-2"><input v-model="rule.action" type="radio" value="wholesale" />{{ t('The wholesale price') }}</label>
                <label v-if="onProduct" class="flex flex-wrap items-center gap-2">
                    <input v-model="rule.action" type="radio" value="fixed" />{{ t('A fixed price') }}
                    <template v-if="rule.action === 'fixed'">
                        <span class="text-ink-500">Rs</span><input v-model="rule.value" type="number" min="0" step="any" class="input w-28 py-1 text-end text-sm tabular-nums" :aria-label="t('Fixed price')" />
                        <span class="text-ink-500">{{ t('per') }}</span>
                        <select v-model="rule.unit_id" class="input w-auto py-1 pe-9 text-sm" :aria-label="t('Pack')"><option :value="null">{{ baseUnit }}</option><option v-for="p in packs.filter((x) => x.code !== baseUnit)" :key="p.unit_id" :value="p.unit_id">{{ p.code }}</option></select>
                    </template>
                </label>
                <label class="flex flex-wrap items-center gap-2">
                    <input v-model="rule.action" type="radio" value="percent_off" />{{ t('A percentage off the In-store price') }}
                    <template v-if="rule.action === 'percent_off'"><input v-model="rule.value" type="number" min="0" max="99.99" step="any" class="input w-20 py-1 text-end text-sm tabular-nums" :aria-label="t('Percent off')" /><span>%</span></template>
                </label>
                <p v-if="fixedBelowCost" class="text-[12.5px] font-medium text-haldi-800">{{ t('This price is below the purchase price (:price per :unit).', { price: money(purchasePerBase), unit: baseUnit }) }}</p>
            </div>
            <label class="block"><span class="field-label">{{ t('Name (optional)') }}</span><input v-model="rule.name" class="input py-1 text-sm" maxlength="120" :placeholder="t('For example: Wholesale from 6')" /></label>
            <p v-if="errors" class="text-[13px] text-ledger-red">{{ errors }}</p>
        </fieldset>
    </div>
</template>
