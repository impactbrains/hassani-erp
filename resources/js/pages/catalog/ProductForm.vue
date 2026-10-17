<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import { dateTime, money } from '@/lib/format';
import { t } from '@/lib/i18n';
import { can } from '@/lib/perms';

/**
 * Add or edit a product: its names (English and the Urdu name you type in
 * — nothing is machine-translated), pack sizes (piece, box, carton ...),
 * barcodes per pack, and a price per pack in each price book.
 */
const props = defineProps<{
    product: any | null;
    prefill?: { id: number; name: string; barcode: string | null; last_price: string | null } | null;
    priceHistory?: any[];
    categories: any[];
    brands: any[];
    units: any[];
    taxCategories: any[];
    priceBooks: any[];
}>();

const pcs = props.units.find((u) => u.code === 'pcs')?.id ?? props.units[0]?.id;
const retail = props.priceBooks.find((b) => b.is_default)?.id ?? props.priceBooks[0]?.id;

function variantFromServer(v: any) {
    const unitIdByVariantUnit: Record<number, number> = Object.fromEntries((v.units ?? []).map((u: any) => [u.id, u.unit_id]));
    return {
        id: v.id, sku: v.sku, name: v.name, name_ur: v.name_ur ?? '', base_unit_id: v.base_unit_id, is_loose: !!v.is_loose, mrp: v.mrp ?? '', is_active: !!v.is_active,
        units: (v.units ?? []).map((u: any) => ({ unit_id: u.unit_id, factor: String(Number(u.factor)), can_sell: !!u.can_sell, can_purchase: !!u.can_purchase, is_default_sale: !!u.is_default_sale, is_default_purchase: !!u.is_default_purchase })),
        barcodes: (v.barcodes ?? []).map((b: any) => ({ code: b.code, unit_id: b.variant_unit_id ? unitIdByVariantUnit[b.variant_unit_id] : null })),
        prices: (v.prices ?? []).map((p: any) => ({ price_book_id: p.price_book_id, unit_id: unitIdByVariantUnit[p.variant_unit_id], price: String(Number(p.price)), min_quantity: String(Number(p.min_quantity)) })),
    };
}
function blankVariant(name = '') {
    return {
        id: null, sku: '', name, name_ur: '', base_unit_id: pcs, is_loose: false, mrp: '', is_active: true,
        units: [{ unit_id: pcs, factor: '1', can_sell: true, can_purchase: true, is_default_sale: true, is_default_purchase: true }],
        barcodes: props.prefill?.barcode ? [{ code: props.prefill.barcode, unit_id: null }] : ([] as any[]),
        prices: [{ price_book_id: retail, unit_id: pcs, price: props.prefill?.last_price ? String(Number(props.prefill.last_price)) : '', min_quantity: '1' }],
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
const unitName = (id: number) => props.units.find((u) => u.id === id)?.name ?? '';

function save(another = false) {
    (form as any).add_another = another;
    // A single-SKU product uses the product name for the SKU unless one was typed.
    form.variants.forEach((v: any) => { if (!v.name) v.name = form.name; });
    if (props.product) form.put(route('catalog.products.update', props.product.id), { preserveScroll: true });
    else form.post(route('catalog.products.store'), { preserveScroll: true });
}
function addUnit(v: any) {
    v.units.push({ unit_id: props.units.find((u) => !v.units.some((x: any) => x.unit_id === u.id))?.id, factor: '', can_sell: true, can_purchase: true, is_default_sale: false, is_default_purchase: false });
    ensurePrices(v);
}
function setDefault(v: any, unitId: number, key: 'is_default_sale' | 'is_default_purchase') {
    v.units.forEach((u: any) => (u[key] = u.unit_id === unitId));
}
/** Every pack gets a (possibly empty) price row per price book, so the grid can bind to it. */
function ensurePrices(v: any) {
    for (const u of v.units) {
        for (const b of props.priceBooks) {
            if (!v.prices.some((x: any) => x.price_book_id === b.id && x.unit_id === u.unit_id && Number(x.min_quantity || 1) === 1)) {
                v.prices.push({ price_book_id: b.id, unit_id: u.unit_id, price: '', min_quantity: '1' });
            }
        }
    }
}
form.variants.forEach(ensurePrices);
function priceFor(v: any, bookId: number, unitId: number) {
    return v.prices.find((x: any) => x.price_book_id === bookId && x.unit_id === unitId && Number(x.min_quantity || 1) === 1) ?? { price: '' };
}
const err = (path: string) => (form.errors as Record<string, string>)[path];
</script>

<template>
    <PageHeader :title="product ? product.name : t('Add product')" :subtitle="prefill ? t('From an item sold at the POS that was not in the list: “:name”', { name: prefill.name }) : null">
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
                    <button v-if="form.variants.length > 1" type="button" class="text-sm text-ledger-red" @click="form.variants.splice(vi, 1)">{{ t('Remove variant') }}</button>
                </div>
                <div class="space-y-5 p-4">
                    <div class="grid gap-4 md:grid-cols-4">
                        <Field label="SKU" :error="err(`variants.${vi}.sku`)" hint="Leave empty to number it automatically."><input v-model="v.sku" class="input" /></Field>
                        <Field v-if="form.variants.length > 1" label="Variant name (e.g. 1 kg, 5 kg)" class="md:col-span-2"><input v-model="v.name" class="input" /></Field>
                        <Field label="Base unit" hint="The smallest unit stock is counted in.">
                            <select v-model="v.base_unit_id" class="input" @change="v.units[0] && (v.units[0].unit_id = v.base_unit_id)"><option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                        </Field>
                        <Field label="Printed price (MRP)" :error="err(`variants.${vi}.mrp`)" hint="Per base unit. Sales above it are blocked if that setting is on."><input v-model="v.mrp" type="number" step="0.01" min="0" class="input text-end" /></Field>
                    </div>

                    <div>
                        <h3 class="mb-2">{{ t('Pack sizes') }}</h3>
                        <div class="overflow-x-auto rounded border border-ink-100">
                            <table class="table-base">
                                <thead>
                                    <tr><th>{{ t('Pack') }}</th><th class="text-end">{{ t('Contains (base units)') }}</th><th>{{ t('Sell') }}</th><th>{{ t('Buy') }}</th><th>{{ t('Default at POS') }}</th><th>{{ t('Default when buying') }}</th>
                                        <th v-for="b in priceBooks" :key="b.id" class="text-end">{{ t('Price') }} · {{ b.name }}</th><th /></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(u, ui) in v.units" :key="ui">
                                        <td><select v-model="u.unit_id" class="input py-1 text-sm" :disabled="ui === 0" @change="ensurePrices(v)"><option v-for="x in units" :key="x.id" :value="x.id">{{ x.name }}</option></select></td>
                                        <td><input v-model="u.factor" type="number" step="any" min="0" class="input w-24 py-1 text-end" :disabled="u.unit_id === v.base_unit_id" /></td>
                                        <td><input v-model="u.can_sell" type="checkbox" class="rounded border-ink-300" /></td>
                                        <td><input v-model="u.can_purchase" type="checkbox" class="rounded border-ink-300" /></td>
                                        <td><input type="radio" :name="`sale-${vi}`" :checked="u.is_default_sale" @change="setDefault(v, u.unit_id, 'is_default_sale')" /></td>
                                        <td><input type="radio" :name="`buy-${vi}`" :checked="u.is_default_purchase" @change="setDefault(v, u.unit_id, 'is_default_purchase')" /></td>
                                        <td v-for="b in priceBooks" :key="b.id"><input v-model="priceFor(v, b.id, u.unit_id).price" type="number" step="0.01" min="0" class="input w-28 py-1 text-end tabular-nums" :disabled="!canPrice" :placeholder="b.is_default ? '' : t('same as retail')" /></td>
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
                    <thead><tr><th>{{ t('From') }}</th><th>{{ t('Price book') }}</th><th>{{ t('Pack') }}</th><th class="num">{{ t('Price') }}</th><th>{{ t('Until') }}</th><th>{{ t('Changed by') }}</th></tr></thead>
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
