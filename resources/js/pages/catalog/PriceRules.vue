<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Copy, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import PriceRuleEditor from '@/components/app/PriceRuleEditor.vue';
import { localName, t } from '@/lib/i18n';
import { blankRule, describeRule, describeScope, normalizeRule, type Rule, type RuleOptions } from '@/lib/priceRules';

/**
 * Pricing rules. "Rules for many products" are for a category, a brand or every product, checked in the
 * order shown here. "Rules on one product" lists every product's own rules (edited on the product page),
 * with "Copy to other products".
 */
const props = defineProps<{ tab: 'shared' | 'products'; shared: any[]; productRules: any[]; canManage: boolean; ruleOptions: RuleOptions }>();
const tab = ref(props.tab);
const tabs = computed(() => [
    { key: 'shared', label: 'Rules for many products', count: props.shared.length },
    { key: 'products', label: 'Rules on one product', count: props.productRules.length },
]);

// ----- Shared rules: add, edit, order -----
const editing = ref<(Rule & { scope: string }) | null>(null);
const editingId = ref<number | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);
function startNew() {
    editing.value = { ...blankRule('all'), scope: 'all', category_id: null, brand_id: null, conditions: [{ type: 'quantity', op: 'gte', value: '12', unit_id: null }] } as any;
    editingId.value = null;
    error.value = null;
}
function startEdit(r: any) {
    editing.value = normalizeRule(JSON.parse(JSON.stringify({ id: r.id, name: r.name, scope: r.scope, category_id: r.category_id, brand_id: r.brand_id, match: r.match, conditions: r.conditions, action: r.action, value: r.value, unit_id: null, is_active: !!r.is_active }))) as any;
    editingId.value = r.id;
    error.value = null;
}
function saveRule() {
    if (!editing.value) return;
    const data = { ...editing.value, value: editing.value.value === '' ? null : editing.value.value };
    const options = {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onSuccess: () => { editing.value = null; editingId.value = null; },
        onError: (errors: Record<string, string>) => (error.value = errors.business ?? Object.values(errors)[0] ?? null),
    };
    editingId.value ? router.put(route('catalog.price-rules.update', editingId.value), data as any, options) : router.post(route('catalog.price-rules.store'), data as any, options);
}
function remove(r: any) {
    if (!window.confirm(t('Remove this rule?'))) return;
    router.delete(route('catalog.price-rules.destroy', r.id), { preserveScroll: true });
}
function move(index: number, by: -1 | 1) {
    const ids = props.shared.map((r) => r.id);
    const [id] = ids.splice(index, 1);
    ids.splice(index + by, 0, id);
    router.post(route('catalog.price-rules.reorder'), { ids }, { preserveScroll: true });
}
function toggleActive(r: any) {
    router.put(route('catalog.price-rules.update', r.id), { name: r.name, scope: r.scope, category_id: r.category_id, brand_id: r.brand_id, match: r.match, conditions: r.conditions, action: r.action, value: r.value, is_active: !r.is_active }, { preserveScroll: true });
}

// ----- Product rules: copy to other products -----
const copying = ref<any | null>(null);
const targets = ref<any[]>([]);
function startCopy(r: any) {
    copying.value = r;
    targets.value = [];
}
function addTarget(p: any) {
    if (p.variant_id !== copying.value?.variant_id && !targets.value.some((x) => x.variant_id === p.variant_id)) targets.value.push(p);
}
function copy() {
    router.post(route('catalog.price-rules.copy', copying.value.id), { variant_ids: targets.value.map((x) => x.variant_id) }, { preserveScroll: true, onSuccess: () => (copying.value = null) });
}
const productName = (r: any) => [localName(r.variant?.product), r.variant?.name && r.variant.name !== r.variant?.product?.name ? r.variant.name : null].filter(Boolean).join(' · ');
const baseCode = (r: any) => r.variant?.base_unit?.code ?? '';
</script>

<template>
    <PageHeader :title="t('Pricing rules')" :subtitle="t('Lower prices that apply by themselves when something is true, for example the wholesale price from 6 pieces. The first rule that fits is used, and rules never add up.')">
        <template #actions>
            <Button :href="route('catalog.products.index')">{{ t('Back to products') }}</Button>
            <Button v-if="canManage && tab === 'shared'" variant="primary" @click="startNew"><Plus class="h-4 w-4" />{{ t('Add a rule') }}</Button>
        </template>
    </PageHeader>
    <Tabs v-model="tab" :tabs="tabs" />

    <template v-if="tab === 'shared'">
        <div class="mb-4 space-y-1 rounded-lg border border-ink-100 bg-white px-4 py-3 text-[13px] text-ink-700">
            <p>{{ t('Order of checking: a product’s own rules first, then category and brand rules, then rules for all products. Inside this list, from the top.') }}</p>
            <p>{{ t('Customers with their own price list (for example Wholesale) keep that list’s prices. A rule applies to them only if it names them, their customer type or their price list.') }}</p>
        </div>

        <section v-if="editing && !editingId" class="panel mb-4 space-y-3 p-4">
            <h2>{{ t('New rule') }}</h2>
            <div class="flex flex-wrap items-end gap-3 text-sm">
                <label><span class="field-label">{{ t('Applies to') }}</span>
                    <select v-model="editing.scope" class="input w-auto pe-9"><option value="all">{{ t('All products') }}</option><option value="category">{{ t('A category') }}</option><option value="brand">{{ t('A brand') }}</option></select>
                </label>
                <label v-if="editing.scope === 'category'"><span class="field-label">{{ t('Category') }}</span><select v-model="editing.category_id" class="input w-auto pe-9"><option :value="null">—</option><option v-for="c in ruleOptions.categories" :key="c.id" :value="c.id">{{ localName(c) }}</option></select></label>
                <label v-if="editing.scope === 'brand'"><span class="field-label">{{ t('Brand') }}</span><select v-model="editing.brand_id" class="input w-auto pe-9"><option :value="null">—</option><option v-for="b in ruleOptions.brands" :key="b.id" :value="b.id">{{ localName(b) }}</option></select></label>
            </div>
            <PriceRuleEditor :model-value="editing" :options="ruleOptions" :errors="error" @remove="editing = null" />
            <div class="flex justify-end gap-2"><Button @click="editing = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="saving" @click="saveRule">{{ t('Save rule') }}</Button></div>
        </section>

        <section class="panel">
            <p v-if="!shared.length" class="p-8 text-center text-sm text-ink-500">{{ t('No rules for many products yet. Add one to give, for example, the wholesale price to anyone buying 12 or more of any item.') }}</p>
            <ol v-else class="divide-y divide-ink-100">
                <li v-for="(r, i) in shared" :key="r.id" class="p-3">
                    <div v-if="editingId === r.id && editing" class="space-y-3">
                        <div class="flex flex-wrap items-end gap-3 text-sm">
                            <label><span class="field-label">{{ t('Applies to') }}</span>
                                <select v-model="editing.scope" class="input w-auto pe-9"><option value="all">{{ t('All products') }}</option><option value="category">{{ t('A category') }}</option><option value="brand">{{ t('A brand') }}</option></select>
                            </label>
                            <label v-if="editing.scope === 'category'"><span class="field-label">{{ t('Category') }}</span><select v-model="editing.category_id" class="input w-auto pe-9"><option v-for="c in ruleOptions.categories" :key="c.id" :value="c.id">{{ localName(c) }}</option></select></label>
                            <label v-if="editing.scope === 'brand'"><span class="field-label">{{ t('Brand') }}</span><select v-model="editing.brand_id" class="input w-auto pe-9"><option v-for="b in ruleOptions.brands" :key="b.id" :value="b.id">{{ localName(b) }}</option></select></label>
                        </div>
                        <PriceRuleEditor :model-value="editing" :options="ruleOptions" :errors="error" @remove="remove(r)" />
                        <div class="flex justify-end gap-2"><Button @click="editing = null; editingId = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="saving" @click="saveRule">{{ t('Save rule') }}</Button></div>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-3">
                        <span class="w-6 text-end text-sm tabular-nums text-ink-400">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[12.5px] font-medium text-ink-500">{{ describeScope(r, ruleOptions) }}<template v-if="r.name"> · {{ r.name }}</template></div>
                            <div class="text-sm" :class="r.is_active ? 'text-ink-900' : 'text-ink-400 line-through'">{{ describeRule(r, ruleOptions) }}</div>
                        </div>
                        <Badge :status="r.is_active ? 'active' : 'deactivated'" :label="r.is_active ? t('On') : t('Off')" />
                        <template v-if="canManage">
                            <button type="button" class="rounded p-1 text-ink-500 hover:bg-ink-50 disabled:opacity-30" :disabled="i === 0" :aria-label="t('Move up')" :title="t('Move up')" @click="move(i, -1)"><ArrowUp class="h-4 w-4" /></button>
                            <button type="button" class="rounded p-1 text-ink-500 hover:bg-ink-50 disabled:opacity-30" :disabled="i === shared.length - 1" :aria-label="t('Move down')" :title="t('Move down')" @click="move(i, 1)"><ArrowDown class="h-4 w-4" /></button>
                            <Button size="sm" @click="toggleActive(r)">{{ r.is_active ? t('Turn off') : t('Turn on') }}</Button>
                            <Button size="sm" @click="startEdit(r)"><Pencil class="h-3.5 w-3.5" />{{ t('Edit') }}</Button>
                            <button type="button" class="rounded p-1 text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="remove(r)"><Trash2 class="h-4 w-4" /></button>
                        </template>
                    </div>
                </li>
            </ol>
        </section>
    </template>

    <section v-else class="panel">
        <p v-if="!productRules.length" class="p-8 text-center text-sm text-ink-500">{{ t('No product has its own rules yet. Add them on a product’s page, under Price rules.') }}</p>
        <table v-else class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th>{{ t('Rule') }}</th><th>{{ t('Status') }}</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="r in productRules" :key="r.id">
                    <td><Link :href="route('catalog.products.edit', r.variant?.product_id)" class="link font-medium">{{ productName(r) }}</Link><div class="text-[12px] text-ink-500">{{ r.variant?.sku }}</div></td>
                    <td class="text-sm">{{ describeRule(r, ruleOptions, baseCode(r)) }}<span v-if="r.name" class="block text-[12px] text-ink-500">{{ r.name }}</span></td>
                    <td><Badge :status="r.is_active ? 'active' : 'deactivated'" :label="r.is_active ? t('On') : t('Off')" /></td>
                    <td class="whitespace-nowrap"><Button v-if="canManage" size="sm" @click="startCopy(r)"><Copy class="h-3.5 w-3.5" />{{ t('Copy to other products') }}</Button></td>
                </tr>
            </tbody>
        </table>
    </section>

    <Modal :open="!!copying" :title="t('Copy to other products')" size="md" @close="copying = null">
        <div v-if="copying" class="space-y-3 text-sm">
            <p class="rounded bg-ink-50 p-2">{{ describeRule(copying, ruleOptions, baseCode(copying)) }}</p>
            <ProductPicker purpose="any" :show-stock="false" :placeholder="t('Search products to copy the rule to')" @pick="addTarget" />
            <ul v-if="targets.length" class="divide-y divide-ink-100 rounded border border-ink-100">
                <li v-for="(p, i) in targets" :key="p.variant_id" class="flex items-center gap-2 px-3 py-1.5"><span class="flex-1">{{ p.name }} <span class="text-[12px] text-ink-400">{{ p.sku }}</span></span><button type="button" :aria-label="t('Remove')" @click="targets.splice(i, 1)"><X class="h-4 w-4 text-ink-400" /></button></li>
            </ul>
            <p v-else class="text-ink-500">{{ t('Add the products that should get the same rule. Their own existing rules stay.') }}</p>
            <p v-if="copying.action === 'fixed'" class="text-[12.5px] text-ink-500">{{ t('A fixed price is copied as it is. Products without the same pack are skipped.') }}</p>
        </div>
        <template #footer><Button @click="copying = null">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!targets.length" @click="copy">{{ targets.length === 1 ? t('Copy to 1 product') : t('Copy to :n products', { n: targets.length }) }}</Button></template>
    </Modal>
</template>
