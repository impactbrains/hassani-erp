<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Copy, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import PriceRuleEditor from '@/components/app/PriceRuleEditor.vue';
import { dateTime, money } from '@/lib/format';
import { localName, t } from '@/lib/i18n';
import { can } from '@/lib/perms';
import { blankRule, describeRule, describeScope, normalizeRule, type Rule, type RuleOptions } from '@/lib/priceRules';
import { qtyStep, stepOnArrow } from '@/lib/stepper';

/**
 * Add or edit a product: its names (English and the Urdu name you type in
 * — nothing is machine-translated), pack sizes (piece, box, carton ...),
 * barcodes per pack, and its four prices:
 *  - Purchase price: what you usually pay, per base unit (updated when stock is received).
 *  - Market price (MRP): the price printed on the pack, per base unit; empty for loose items.
 *  - In-store price and Wholesale price: per pack, on the price lists of the same names.
 * Price rules decide when a lower price applies (for example the wholesale price from 6 pieces).
 */
const props = defineProps<{
    /** Previous / next record, from the Products list the page was opened from. */
    pager?: any;
    product: any | null;
    prefill?: { id: number; name: string; barcode: string | null; last_price: string | null } | null;
    priceHistory?: any[];
    categories: any[];
    brands: any[];
    units: any[];
    taxCategories: any[];
    priceBooks: any[];
    wholesaleBookId: number | null;
    canSeeCosts: boolean;
    ruleOptions: RuleOptions;
    sharedRules: any[];
    /** Present only when creating: the store the stock will be added to and what the user may do. */
    openingStock?: { store: string; allowed: boolean; needs_approval: boolean; show_cost: boolean } | null;
}>();

const pcs = props.units.find((u) => u.code === 'pcs')?.id ?? props.units[0]?.id;
const inStore = props.priceBooks.find((b) => b.is_default)?.id ?? props.priceBooks[0]?.id;
const bookLabel = (b: any) => (b.is_default ? t('In-store price') : b.id === props.wholesaleBookId ? t('Wholesale price') : t(':name price', { name: localName(b) }));

let uidSeq = 0;
const nextUid = () => ++uidSeq;
/** Variants whose In-store price was typed or loaded: the Market price shortcut leaves those alone. */
const inStoreEdited = new Set<number>();
/** Variants whose opening cost was typed: the Purchase price shortcut leaves those alone. */
const openingCostEdited = new Set<number>();

const ruleFromServer = (r: any): Rule => normalizeRule({ id: r.id, name: r.name, scope: 'variant', match: r.match, conditions: r.conditions, action: r.action, value: r.value, unit_id: r.unit_id, is_active: !!r.is_active });

function variantFromServer(v: any) {
    const unitIdByVariantUnit: Record<number, number> = Object.fromEntries((v.units ?? []).map((u: any) => [u.id, u.unit_id]));
    return {
        _uid: nextUid(), id: v.id, sku: v.sku, name: v.name, name_ur: v.name_ur ?? '', base_unit_id: v.base_unit_id, is_loose: !!v.is_loose, mrp: v.mrp ?? '', is_active: !!v.is_active,
        purchase_price: v.purchase_price !== null && v.purchase_price !== undefined ? String(Number(v.purchase_price)) : '',
        units: (v.units ?? []).map((u: any) => ({ unit_id: u.unit_id, factor: String(Number(u.factor)), can_sell: !!u.can_sell, can_purchase: !!u.can_purchase, is_default_sale: !!u.is_default_sale, is_default_purchase: !!u.is_default_purchase })),
        barcodes: (v.barcodes ?? []).map((b: any) => ({ code: b.code, unit_id: b.variant_unit_id ? unitIdByVariantUnit[b.variant_unit_id] : null })),
        prices: (v.prices ?? []).map((p: any) => ({ price_book_id: p.price_book_id, unit_id: unitIdByVariantUnit[p.variant_unit_id], price: String(Number(p.price)), min_quantity: String(Number(p.min_quantity)), saved: true })),
        price_rules: (v.price_rules ?? []).map(ruleFromServer),
    };
}
function blankVariant(name = '') {
    return {
        _uid: nextUid(), id: null, sku: '', name, name_ur: '', base_unit_id: pcs, is_loose: false, mrp: '', is_active: true, purchase_price: '',
        opening_quantity: '', opening_cost: '', opening_batch_no: '', opening_expiry_date: '',
        units: [{ unit_id: pcs, factor: '1', can_sell: true, can_purchase: true, is_default_sale: true, is_default_purchase: true }],
        barcodes: props.prefill?.barcode ? [{ code: props.prefill.barcode, unit_id: null }] : ([] as any[]),
        prices: [{ price_book_id: inStore, unit_id: pcs, price: props.prefill?.last_price ? String(Number(props.prefill.last_price)) : '', min_quantity: '1', saved: false }],
        price_rules: [] as Rule[],
    };
}

const p = props.product;
const form = useForm({
    name: p?.name ?? props.prefill?.name ?? '',
    name_ur: p?.name_ur ?? '',
    category_id: p?.category_id ?? null,
    brand_id: p?.brand_id ?? null,
    manufacturer: p?.manufacturer ?? '',
    description: p?.description ?? '',
    description_ur: p?.description_ur ?? '',
    type: p?.type ?? 'stocked',
    tax_category_id: p?.tax_category_id ?? null,
    track_batches: !!p?.track_batches,
    track_expiry: !!p?.track_expiry,
    shelf_life_days: p?.shelf_life_days ?? null,
    is_online: p ? !!p.is_online : true,
    is_active: p ? !!p.is_active : true,
    variants: p ? p.variants.map(variantFromServer) : [blankVariant(props.prefill?.name ?? '')],
    pending_product_id: props.prefill?.id ?? null,
    add_another: false,
});
const canPrice = computed(() => can('catalog.prices.manage'));
/** Opening stock is only asked for when creating a stock item, never when editing (so it cannot be counted twice). */
const showOpening = computed(() => !props.product && !!props.openingStock && form.type === 'stocked');
const tracksLots = computed(() => form.track_batches || form.track_expiry);
const unitName = (id: number) => props.units.find((u) => u.id === id)?.name ?? '';
const unitCode = (id: number) => props.units.find((u) => u.id === id)?.code ?? '';
const packsOf = (v: any) => v.units.map((u: any) => ({ unit_id: u.unit_id, code: unitCode(u.unit_id), name: unitName(u.unit_id), factor: Number(u.factor) || 1 }));

function addUnit(v: any) {
    v.units.push({ unit_id: props.units.find((u) => !v.units.some((x: any) => x.unit_id === u.id))?.id, factor: '', can_sell: true, can_purchase: true, is_default_sale: false, is_default_purchase: false });
    ensurePrices(v);
}
function setDefault(v: any, unitId: number, key: 'is_default_sale' | 'is_default_purchase') {
    v.units.forEach((u: any) => (u[key] = u.unit_id === unitId));
}
/** Every pack gets a (possibly empty) price row per price list, so the grid can bind to it. */
function ensurePrices(v: any) {
    for (const u of v.units) {
        for (const b of props.priceBooks) {
            if (!v.prices.some((x: any) => x.price_book_id === b.id && x.unit_id === u.unit_id && Number(x.min_quantity || 1) === 1)) {
                v.prices.push({ price_book_id: b.id, unit_id: u.unit_id, price: '', min_quantity: '1', saved: false });
            }
        }
    }
}
form.variants.forEach(ensurePrices);
// An In-store price that already has a value (saved earlier, or carried over from an unlisted POS item) is never overwritten.
form.variants.forEach((v: any) => {
    if (priceFor(v, inStore, v.base_unit_id).price !== '') inStoreEdited.add(v._uid);
});
function priceFor(v: any, bookId: number, unitId: number) {
    return v.prices.find((x: any) => x.price_book_id === bookId && x.unit_id === unitId && Number(x.min_quantity || 1) === 1) ?? { price: '' };
}
/**
 * Typing the Market price (MRP) fills the In-store price of the base unit, until someone types in that
 * In-store price themselves. Other prices are never filled automatically.
 */
function onMrpInput(v: any, value: string) {
    if (!canPrice.value || inStoreEdited.has(v._uid)) return;
    const row = priceFor(v, inStore, v.base_unit_id);
    row.price = value === '' || !Number.isFinite(Number(value)) ? '' : String(Number(value));
}
function onPriceTyped(v: any, bookId: number, unitId: number) {
    if (bookId === inStore && unitId === v.base_unit_id) inStoreEdited.add(v._uid);
}
/** Typing the Purchase price fills the opening stock cost, until someone types that cost themselves. */
function onPurchaseInput(v: any) {
    if (!showOpening.value || openingCostEdited.has(v._uid)) return;
    v.opening_cost = v.purchase_price;
}
/** Up/Down arrows move a price or quantity by 1 (weighed units by 0.125). */
function arrow(e: KeyboardEvent, obj: any, key: string, step = 1, after?: () => void) {
    const next = stepOnArrow(e, obj[key], step);
    if (next !== null) {
        obj[key] = next;
        after?.();
    }
}
const allowsFraction = (unitId: number) => !!props.units.find((u) => u.id === unitId)?.allows_fraction;

// ----- Margins and warnings -----
const factorOf = (v: any, unitId: number) => Number(v.units.find((u: any) => u.unit_id === unitId)?.factor) || 1;
const purchaseFor = (v: any, unitId: number) => (props.canSeeCosts && Number(v.purchase_price) > 0 ? Number(v.purchase_price) * factorOf(v, unitId) : null);
/** Margin on the selling price, as a whole percent; null when there is no price or purchase price. */
function marginOf(v: any, unitId: number, price: string): number | null {
    const cost = purchaseFor(v, unitId);
    const sell = Number(price);
    if (cost === null || !(sell > 0)) return null;
    return Math.round(((sell - cost) / sell) * 1000) / 10;
}
/** Packs that can be sold but have no Wholesale price, while a rule would use the wholesale price. */
function missingWholesale(v: any): string[] {
    const usesWholesale = v.price_rules.some((r: Rule) => r.is_active && r.action === 'wholesale') || props.sharedRules.some((r: any) => r.action === 'wholesale');
    if (!props.wholesaleBookId || !usesWholesale) return [];
    return v.units.filter((u: any) => u.can_sell && priceFor(v, props.wholesaleBookId!, u.unit_id).price === '').map((u: any) => unitCode(u.unit_id));
}
/** Every selling price or fixed rule price that is below the purchase price, for the warning on save. */
function belowCost(): string[] {
    const list: string[] = [];
    form.variants.forEach((v: any) => {
        const label = form.variants.length > 1 ? v.name || form.name : form.name;
        for (const u of v.units) {
            for (const b of props.priceBooks) {
                const price = priceFor(v, b.id, u.unit_id).price;
                const cost = purchaseFor(v, u.unit_id);
                if (price !== '' && cost !== null && Number(price) < cost) list.push(`${label} · ${unitCode(u.unit_id)} · ${bookLabel(b)} ${money(price)} (${t('purchase')} ${money(cost)})`);
            }
        }
        for (const r of v.price_rules as Rule[]) {
            const cost = purchaseFor(v, r.unit_id ?? v.base_unit_id);
            if (r.is_active && r.action === 'fixed' && r.value && cost !== null && Number(r.value) < cost) list.push(`${label} · ${t('price rule')} ${money(r.value)} (${t('purchase')} ${money(cost)})`);
        }
    });
    return list;
}

function save(another = false) {
    const low = belowCost();
    if (low.length && !window.confirm(`${t('These prices are below the purchase price:')}\n\n${low.join('\n')}\n\n${t('Save anyway?')}`)) return;
    (form as any).add_another = another;
    // A single-SKU product uses the product name for the SKU unless one was typed.
    form.variants.forEach((v: any) => { if (!v.name) v.name = form.name; });
    form.transform((d: any) => ({
        ...d,
        variants: d.variants.map((v: any) => {
            const out: any = {
                ...v,
                // Emptying a saved price on another list (Wholesale ...) removes it. The In-store price is never removed this way.
                prices: v.prices.map((x: any) => ({ price_book_id: x.price_book_id, unit_id: x.unit_id, price: x.price, min_quantity: x.min_quantity, clear: x.saved && x.price === '' && x.price_book_id !== inStore })),
                price_rules: v.price_rules.map((r: Rule) => ({ ...r, value: r.value === '' ? null : r.value })),
            };
            if (!props.canSeeCosts) delete out.purchase_price;
            if (!canPrice.value) delete out.price_rules;
            return out;
        }),
    }));
    if (props.product) form.put(route('catalog.products.update', props.product.id), { preserveScroll: true });
    else form.post(route('catalog.products.store'), { preserveScroll: true });
}

/** A copy of a variant right below it: same packs, prices, rules and Market price; the name gets "(copy)"; SKU, barcodes and opening stock start empty. */
function copyVariant(vi: number) {
    const src: any = form.variants[vi];
    if (!src.name) src.name = form.name;
    const copy = JSON.parse(JSON.stringify(src));
    Object.assign(copy, { _uid: nextUid(), id: null, sku: '', name: `${src.name} (copy)`, barcodes: [], opening_quantity: '', opening_cost: '', opening_batch_no: '', opening_expiry_date: '' });
    copy.prices.forEach((x: any) => (x.saved = false));
    copy.price_rules.forEach((r: Rule) => (r.id = null));
    form.variants.splice(vi + 1, 0, copy);
    inStoreEdited.add(copy._uid);
}
const err = (path: string) => (form.errors as Record<string, string>)[path];
</script>

<template>
    <PageHeader :pager="pager" :confirm-leave="form.isDirty" :title="product ? product.name : t('Add product')" :subtitle="prefill ? t('From an item sold at the POS that was not in the list: “:name”', { name: prefill.name }) : null">
        <template #actions>
            <Button :href="route('catalog.products.index')">{{ t('Back to products') }}</Button>
            <Button v-if="!product" :loading="form.processing" @click="save(true)">{{ t('Save and add another') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="save()">{{ t('Save') }}</Button>
        </template>
    </PageHeader>

    <div v-if="Object.keys(form.errors).length" class="mb-4 rounded-lg border border-ledger-red/30 bg-ledger-redSoft px-4 py-3 text-sm text-ledger-red" role="alert">
        {{ (form.errors as any).business ?? t('Please check the highlighted fields.') }}
    </div>

    <form class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]" @submit.prevent="save()">
        <div class="space-y-5">
            <section class="panel p-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <Field label="Product name" :error="err('name')" required><input v-model="form.name" class="input" required /></Field>
                    <Field label="Urdu name" :error="err('name_ur')" hint="Typed by you; printed on Urdu receipts and shelf labels.">
                        <input v-model="form.name_ur" class="input urdu text-right text-lg" dir="rtl" lang="ur" />
                    </Field>
                    <Field label="Category">
                        <select v-model="form.category_id" class="input"><option :value="null">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </Field>
                    <Field label="Brand">
                        <select v-model="form.brand_id" class="input"><option :value="null">—</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                    </Field>
                    <Field label="Manufacturer / company"><input v-model="form.manufacturer" class="input" /></Field>
                    <Field label="Kind">
                        <select v-model="form.type" class="input">
                            <option value="stocked">{{ t('Stock item (counted in inventory)') }}</option>
                            <option value="service">{{ t('Service / not counted (e.g. delivery fee)') }}</option>
                            <option value="bundle">{{ t('Bundle (made of other products)') }}</option>
                        </select>
                    </Field>
                </div>
            </section>

            <section v-for="(v, vi) in form.variants" :key="vi" class="panel">
                <div class="panel-head">
                    <h2>{{ form.variants.length > 1 ? `${t('Variant')} ${vi + 1}` : t('SKU, packs and prices') }}</h2>
                    <span class="flex items-center gap-4">
                        <button v-if="form.type !== 'bundle'" type="button" class="link inline-flex items-center gap-1 text-sm" @click="copyVariant(vi)"><Copy class="h-3.5 w-3.5" />{{ t('Copy variant') }}</button>
                        <button v-if="form.variants.length > 1" type="button" class="text-sm text-ledger-red" @click="form.variants.splice(vi, 1)">{{ t('Remove variant') }}</button>
                    </span>
                </div>
                <div class="space-y-5 p-4">
                    <div class="grid gap-4 md:grid-cols-4">
                        <Field label="SKU" :error="err(`variants.${vi}.sku`)" hint="Leave empty to number it automatically."><input v-model="v.sku" class="input" /></Field>
                        <Field v-if="form.variants.length > 1" label="Variant name (e.g. 1 kg, 5 kg)" class="md:col-span-3"><input v-model="v.name" class="input" /></Field>
                        <Field label="Base unit" hint="The smallest unit stock is counted in.">
                            <select v-model="v.base_unit_id" class="input" @change="v.units[0] && (v.units[0].unit_id = v.base_unit_id); ensurePrices(v)"><option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                        </Field>
                        <Field v-if="canSeeCosts" :label="t('Purchase price (per :unit)', { unit: unitCode(v.base_unit_id) })" :error="err(`variants.${vi}.purchase_price`)" hint="What you usually pay. It changes each time stock is received, and fills purchase orders.">
                            <input v-model="v.purchase_price" type="number" step="any" min="0" class="input text-end tabular-nums" @input="onPurchaseInput(v)" @keydown="arrow($event, v, 'purchase_price', 1, () => onPurchaseInput(v))" />
                        </Field>
                        <Field :label="t('Market price (MRP) (per :unit)', { unit: unitCode(v.base_unit_id) })" :error="err(`variants.${vi}.mrp`)" hint="The price printed on the pack. Leave it empty for loose items. Typing it fills the In-store price until you type that yourself.">
                            <input v-model="v.mrp" type="number" step="0.01" min="0" class="input text-end tabular-nums" @input="onMrpInput(v, ($event.target as HTMLInputElement).value)" @keydown="arrow($event, v, 'mrp', 1, () => onMrpInput(v, v.mrp))" />
                        </Field>
                    </div>

                    <div>
                        <h3 class="mb-1">{{ t('Pack sizes and prices') }}</h3>
                        <p class="mb-2 text-[12.5px] text-ink-500">{{ t('In-store price is what customers pay at the counter. Wholesale price is given by price rules and to customers on the Wholesale price list. Prices are per pack.') }}</p>
                        <div class="overflow-x-auto rounded border border-ink-100">
                            <table class="table-base [&_td]:px-2 [&_th]:whitespace-normal [&_th]:px-2">
                                <thead>
                                    <tr><th>{{ t('Pack') }}</th><th class="text-end">{{ t('Contains (base units)') }}</th><th>{{ t('Sell') }}</th><th>{{ t('Buy') }}</th><th>{{ t('Default at POS') }}</th><th>{{ t('Default when buying') }}</th>
                                        <th v-for="b in priceBooks" :key="b.id" class="text-end">{{ bookLabel(b) }}</th><th /></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(u, ui) in v.units" :key="ui">
                                        <td><select v-model="u.unit_id" class="input min-w-[7.5rem] py-1 pe-8 text-sm" :disabled="ui === 0" @change="ensurePrices(v)"><option v-for="x in units" :key="x.id" :value="x.id">{{ x.name }}</option></select></td>
                                        <td><input v-model="u.factor" type="number" step="any" min="0" class="input w-20 py-1 text-end" :disabled="u.unit_id === v.base_unit_id" /></td>
                                        <td><input v-model="u.can_sell" type="checkbox" class="rounded border-ink-300" /></td>
                                        <td><input v-model="u.can_purchase" type="checkbox" class="rounded border-ink-300" /></td>
                                        <td><input type="radio" :name="`sale-${vi}`" :checked="u.is_default_sale" @change="setDefault(v, u.unit_id, 'is_default_sale')" /></td>
                                        <td><input type="radio" :name="`buy-${vi}`" :checked="u.is_default_purchase" @change="setDefault(v, u.unit_id, 'is_default_purchase')" /></td>
                                        <td v-for="b in priceBooks" :key="b.id" class="align-top">
                                            <input v-model="priceFor(v, b.id, u.unit_id).price" type="number" step="0.01" min="0" class="input w-24 py-1 text-end tabular-nums" :disabled="!canPrice" :placeholder="b.is_default ? '' : b.id === wholesaleBookId ? t('none') : t('same as In-store')" @input="onPriceTyped(v, b.id, u.unit_id)" @keydown="arrow($event, priceFor(v, b.id, u.unit_id), 'price', 1, () => onPriceTyped(v, b.id, u.unit_id))" />
                                            <template v-if="marginOf(v, u.unit_id, priceFor(v, b.id, u.unit_id).price) !== null">
                                                <div v-if="marginOf(v, u.unit_id, priceFor(v, b.id, u.unit_id).price)! < 0" class="mt-0.5 text-end text-[11.5px] font-medium text-ledger-red">{{ t('Below purchase price') }}</div>
                                                <div v-else class="mt-0.5 text-end text-[11.5px] text-ink-500">{{ t(':pct% margin', { pct: marginOf(v, u.unit_id, priceFor(v, b.id, u.unit_id).price)! }) }}</div>
                                            </template>
                                        </td>
                                        <td><button v-if="ui > 0" type="button" class="rounded p-1 text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="v.units.splice(ui, 1)"><Trash2 class="h-4 w-4" /></button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <Button size="sm" @click="addUnit(v)"><Plus class="h-4 w-4" />{{ t('Add pack size (box, carton ...)') }}</Button>
                            <span v-if="!canPrice" class="text-[12.5px] text-ink-500">{{ t('Only people allowed to change prices can edit prices.') }}</span>
                            <span v-if="err(`variants.${vi}.units`) || err('factor')" class="text-[12.5px] text-ledger-red">{{ err(`variants.${vi}.units`) ?? err('factor') }}</span>
                        </div>
                        <p v-if="missingWholesale(v).length" class="mt-2 rounded border border-haldi-300 bg-haldi-50 px-3 py-2 text-[12.5px] text-haldi-900">
                            {{ t('No Wholesale price for :packs. Where a rule gives the wholesale price, these will sell at the In-store price.', { packs: missingWholesale(v).join(', ') }) }}
                        </p>
                    </div>

                    <div v-if="form.type !== 'bundle'">
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <h3>{{ t('Price rules') }}</h3>
                            <Link :href="route('catalog.price-rules.index')" class="link text-[13px]">{{ t('Rules for many products') }}</Link>
                        </div>
                        <p class="mb-2 text-[12.5px] text-ink-500">{{ t('Give a lower price when something is true, for example the wholesale price when someone buys more than 5. Rules are checked from the top, and the first one that fits is used.') }}</p>
                        <div class="space-y-2">
                            <template v-if="canPrice">
                                <PriceRuleEditor v-for="(r, ri) in v.price_rules" :key="ri" :model-value="r" :options="ruleOptions" :packs="packsOf(v)" :base-unit="unitCode(v.base_unit_id)" :purchase-per-base="canSeeCosts && Number(v.purchase_price) > 0 ? Number(v.purchase_price) : null" @remove="v.price_rules.splice(ri, 1)" />
                                <Button size="sm" @click="v.price_rules.push(blankRule('variant'))"><Plus class="h-4 w-4" />{{ t('Add a price rule') }}</Button>
                            </template>
                            <template v-else>
                                <p v-for="(r, ri) in v.price_rules" :key="ri" class="text-sm">{{ describeRule(r, ruleOptions, unitCode(v.base_unit_id)) }}</p>
                                <p v-if="!v.price_rules.length" class="text-sm text-ink-500">{{ t('No price rules for this product.') }}</p>
                            </template>
                        </div>
                        <div v-if="sharedRules.length" class="mt-3 rounded border border-ink-100 bg-ink-50 px-3 py-2 text-[13px]">
                            <p class="mb-1 font-medium">{{ t('Shared rules that also apply here (checked after this product’s own rules):') }}</p>
                            <ul class="list-disc space-y-0.5 ps-5 text-ink-700">
                                <li v-for="r in sharedRules" :key="r.id">{{ describeScope(r, ruleOptions) }}: {{ describeRule(r, ruleOptions, unitCode(v.base_unit_id)) }}</li>
                            </ul>
                        </div>
                    </div>

                    <div v-if="showOpening">
                        <h3 class="mb-1">{{ t('Opening stock') }}</h3>
                        <p class="mb-2 text-[12.5px] text-ink-500">
                            {{ t('Stock you already have of this item in :store, counted in :unit. For other stores or later changes, use Stock → Adjustments.', { store: openingStock!.store, unit: unitName(v.base_unit_id) }) }}
                        </p>
                        <p v-if="!openingStock!.allowed" class="text-[12.5px] text-ink-500">{{ t('You are not allowed to add stock. Ask someone with stock adjustment permission.') }}</p>
                        <div v-else class="grid gap-4 md:grid-cols-4">
                            <Field label="Quantity on hand" :error="err(`variants.${vi}.opening_quantity`)"><input v-model="v.opening_quantity" type="number" step="any" min="0" class="input text-end tabular-nums" placeholder="0" @keydown="arrow($event, v, 'opening_quantity', qtyStep(allowsFraction(v.base_unit_id)))" /></Field>
                            <Field label="Cost per unit" :error="err(`variants.${vi}.opening_cost`)" hint="What each unit cost you. Needed for correct profit and stock value."><input v-model="v.opening_cost" type="number" step="any" min="0" class="input text-end tabular-nums" :required="Number(v.opening_quantity) > 0" @input="openingCostEdited.add(v._uid)" @keydown="arrow($event, v, 'opening_cost', 1, () => openingCostEdited.add(v._uid))" /></Field>
                            <template v-if="tracksLots">
                                <Field label="Batch no." :error="err(`variants.${vi}.opening_batch_no`)"><input v-model="v.opening_batch_no" class="input" /></Field>
                                <Field label="Expiry date"><input v-model="v.opening_expiry_date" type="date" class="input" /></Field>
                            </template>
                        </div>
                        <p v-if="openingStock!.allowed && openingStock!.needs_approval" class="mt-2 text-[12.5px] text-ink-500">{{ t('Opening stock from you is sent for approval before it counts.') }}</p>
                    </div>

                    <div>
                        <h3 class="mb-2">{{ t('Barcodes') }}</h3>
                        <div class="space-y-2">
                            <div v-for="(b, bi) in v.barcodes" :key="bi" class="flex flex-wrap items-center gap-2">
                                <input v-model="b.code" class="input max-w-xs" :placeholder="t('Scan or type the barcode')" />
                                <select v-model="b.unit_id" class="input max-w-[12rem]"><option :value="null">{{ t('Default pack') }}</option><option v-for="u in v.units" :key="u.unit_id" :value="u.unit_id">{{ unitName(u.unit_id) }}</option></select>
                                <button type="button" class="rounded p-1 text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="v.barcodes.splice(bi, 1)"><Trash2 class="h-4 w-4" /></button>
                            </div>
                            <Button size="sm" @click="v.barcodes.push({ code: '', unit_id: null })"><Plus class="h-4 w-4" />{{ t('Add barcode') }}</Button>
                            <p v-if="err('barcodes')" class="text-[12.5px] text-ledger-red">{{ err('barcodes') }}</p>
                        </div>
                    </div>
                </div>
            </section>
            <Button v-if="form.type !== 'bundle'" @click="form.variants.push(blankVariant()); ensurePrices(form.variants[form.variants.length - 1])"><Plus class="h-4 w-4" />{{ t('Add a variant (another size or flavour)') }}</Button>

            <section v-if="priceHistory?.length" class="panel">
                <div class="panel-head"><h2>{{ t('Price history') }}</h2></div>
                <table class="table-base">
                    <thead><tr><th>{{ t('From') }}</th><th>{{ t('Price list') }}</th><th>{{ t('Pack') }}</th><th class="num">{{ t('Price') }}</th><th>{{ t('Until') }}</th><th>{{ t('Changed by') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="h in priceHistory" :key="h.id">
                            <td>{{ dateTime(h.effective_from) }}</td><td>{{ h.price_book?.name }}</td><td>{{ h.variant_unit?.unit?.code }}</td><td class="num">{{ money(h.price) }}</td><td>{{ h.effective_to ? dateTime(h.effective_to) : t('current') }}</td><td>{{ h.creator?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="panel space-y-3 p-4">
                <h2>{{ t('Selling') }}</h2>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('Active (can be sold and ordered)') }}</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_online" type="checkbox" class="rounded border-ink-300" />{{ t('Show on the website / app') }}</label>
                <Field label="Tax category"><select v-model="form.tax_category_id" class="input"><option :value="null">{{ t('Default') }}</option><option v-for="c in taxCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
            </section>
            <section class="panel space-y-3 p-4">
                <h2>{{ t('Batches and expiry') }}</h2>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.track_batches" type="checkbox" class="rounded border-ink-300" />{{ t('Track batch numbers') }}</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.track_expiry" type="checkbox" class="rounded border-ink-300" />{{ t('Track expiry dates (sell oldest first)') }}</label>
                <Field v-if="form.track_expiry" label="Usual shelf life (days)"><input v-model="form.shelf_life_days" type="number" min="0" class="input" /></Field>
            </section>
            <section class="panel space-y-3 p-4">
                <h2>{{ t('Description') }}</h2>
                <textarea v-model="form.description" rows="3" class="input" :placeholder="t('For the website / app (optional)')" />
                <textarea v-model="form.description_ur" rows="3" class="input urdu text-right" dir="rtl" :placeholder="t('Urdu description (optional)')" />
            </section>
            <p v-if="product" class="text-[13px] text-ink-500">
                <Link :href="route('catalog.labels', { variant_ids: product.variants.map((x: any) => x.id) })" class="link">{{ t('Print shelf labels') }}</Link>
            </p>
        </aside>
    </form>
</template>
