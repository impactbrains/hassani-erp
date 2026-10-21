<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Download, Languages, Plus, Printer, RotateCcw, Trash2, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Modal from '@/components/ui/Modal.vue';
import { useFilters } from '@/composables/useFilters';
import { money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';
import { can } from '@/lib/perms';

/** Product list with stock in the working store, In-store price and (for managers) cost. */
const props = defineProps<{ products: any; filters: Record<string, any>; categories: any[]; brands: any[]; missingUrdu: number; tab: 'active' | 'deactivated' | 'trash'; counts: { active: number; deactivated: number; trash: number }; trashDays: number; isOwner: boolean }>();
const filters = useFilters('catalog.products.index', { q: props.filters.q ?? '', category_id: props.filters.category_id ?? '', brand_id: props.filters.brand_id ?? '', tab: props.tab, missing: props.filters.missing ?? '' });

const urduMode = ref(false);
const urdu = ref<Record<number, string>>({});
const selected = ref<number[]>([]); // variant ids (label printing)
const selectedProducts = ref<number[]>([]); // product ids (delete)
const toDelete = ref<{ ids: number[]; names: string[] } | null>(null);
const toPurge = ref<any | null>(null);
const deleting = ref(false);
const canManage = computed(() => can('catalog.products.manage'));
const allOnPage = computed(() => props.products.data.length > 0 && props.products.data.every((p: any) => selectedProducts.value.includes(p.id)));
const showCost = computed(() => can('catalog.costs.view'));

function startUrdu() {
    urdu.value = Object.fromEntries(props.products.data.map((p: any) => [p.id, p.name_ur ?? '']));
    urduMode.value = true;
}
function saveUrdu() {
    router.put(route('catalog.products.urdu'), { names: Object.entries(urdu.value).map(([id, name_ur]) => ({ id: Number(id), name_ur })) }, { preserveScroll: true, onSuccess: () => (urduMode.value = false) });
}
function printLabels() {
    router.get(route('catalog.labels'), { variant_ids: selected.value });
}
function toggleProduct(p: any, on: boolean) {
    const variantIds: number[] = p.variants.map((v: any) => v.id);
    selectedProducts.value = on ? [...new Set([...selectedProducts.value, p.id])] : selectedProducts.value.filter((id) => id !== p.id);
    selected.value = on ? [...new Set([...selected.value, ...variantIds])] : selected.value.filter((id) => !variantIds.includes(id));
}
function toggleAll(on: boolean) {
    props.products.data.forEach((p: any) => toggleProduct(p, on));
}
function clearSelection() {
    selected.value = [];
    selectedProducts.value = [];
}
function askDelete(list: any[]) {
    toDelete.value = { ids: list.map((p) => p.id), names: list.map((p) => p.name) };
}
function askDeleteSelected() {
    askDelete(props.products.data.filter((p: any) => selectedProducts.value.includes(p.id)));
}
function confirmDelete() {
    if (!toDelete.value) return;
    const ids = toDelete.value.ids;
    deleting.value = true;
    const done = { preserveScroll: true, onSuccess: () => { toDelete.value = null; clearSelection(); }, onFinish: () => (deleting.value = false) };
    if (ids.length === 1) router.delete(route('catalog.products.destroy', ids[0]), done);
    else router.post(route('catalog.products.bulk-destroy'), { ids }, done);
}
const tabs = computed(() => [
    { key: 'active', label: t('Active'), n: props.counts.active },
    { key: 'deactivated', label: t('Deactivated'), n: props.counts.deactivated },
    { key: 'trash', label: t('Trash'), n: props.counts.trash },
] as const);
function restore(ids: number[]) {
    if (ids.length === 1) router.post(route('catalog.products.restore', ids[0]), {}, { preserveScroll: true, onSuccess: clearSelection });
    else router.post(route('catalog.products.bulk-restore'), { ids }, { preserveScroll: true, onSuccess: clearSelection });
}
function restoreSelected() { restore([...selectedProducts.value]); }
function confirmPurge() {
    if (!toPurge.value) return;
    deleting.value = true;
    router.delete(route('catalog.products.force-destroy', toPurge.value.id), { preserveScroll: true, onSuccess: () => (toPurge.value = null), onFinish: () => (deleting.value = false) });
}
const emptyText = computed(() => ({
    active: t('No products match. Add one, or import a list from a CSV file.'),
    deactivated: t('Nothing is deactivated. Products with stock or sales history that you delete are kept here.'),
    trash: t('The Trash is empty. Deleted products that never had stock or sales wait here for :n days.', { n: props.trashDays }),
}[props.tab]));
const stockTone = (v: any) => (Number(v.available) <= 0 ? 'text-ledger-red' : 'text-ink-800');
</script>

<template>
    <PageHeader :title="t('Products')">
        <template #actions>
            <Button v-if="can('catalog.labels.print') && selected.length" @click="printLabels"><Printer class="h-4 w-4" />{{ t('Print labels') }} ({{ selected.length }})</Button>
            <Button v-if="can('catalog.products.manage') && !urduMode && tab === 'active'" @click="startUrdu"><Languages class="h-4 w-4" />{{ t('Type Urdu names') }}</Button>
            <Button v-if="can('catalog.products.import')" :href="route('catalog.import')"><Upload class="h-4 w-4" />{{ t('Import') }}</Button>
            <a v-if="can('catalog.products.export')" :href="route('catalog.export')" class="inline-flex h-10 items-center gap-2 rounded border border-ink-200 bg-white px-4 text-sm font-medium text-ink-800 hover:bg-ink-50"><Download class="h-4 w-4" />{{ t('Export') }}</a>
            <Button v-if="can('catalog.products.manage') && tab === 'active'" variant="primary" :href="route('catalog.products.create')"><Plus class="h-4 w-4" />{{ t('Add product') }}</Button>
        </template>
    </PageHeader>

    <div class="mb-3 flex gap-1 border-b border-ink-200" role="tablist">
        <button
            v-for="x in tabs"
            :key="x.key"
            type="button"
            role="tab"
            :aria-selected="tab === x.key"
            class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
            :class="tab === x.key ? 'border-ink-800 text-ink-900' : 'border-transparent text-ink-500 hover:text-ink-800'"
            @click="filters.tab = x.key; clearSelection()"
        >{{ x.label }} <span class="ms-1 text-[12px] text-ink-400">{{ x.n }}</span></button>
    </div>

    <div class="panel mb-4 flex flex-wrap items-end gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Name, Urdu name, SKU or barcode')" />
        <select v-model="filters.category_id" class="input max-w-[12rem]"><option value="">{{ t('All categories') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <select v-model="filters.brand_id" class="input max-w-[12rem]"><option value="">{{ t('All brands') }}</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <label class="flex items-center gap-2 text-sm text-ink-700"><input v-model="filters.missing" type="checkbox" true-value="urdu" false-value="" class="rounded border-ink-300" />{{ t('Missing Urdu name') }} <span class="text-ink-400">({{ missingUrdu }})</span></label>
    </div>

    <div v-if="selectedProducts.length && canManage" class="mb-3 flex items-center justify-between rounded-lg border border-ink-200 bg-ink-50 px-4 py-2 text-sm">
        <span>{{ t(':n selected', { n: selectedProducts.length }) }}</span>
        <span class="flex gap-2">
            <Button size="sm" @click="clearSelection">{{ t('Clear') }}</Button>
            <Button v-if="tab !== 'active'" size="sm" variant="primary" @click="restoreSelected"><RotateCcw class="h-4 w-4" />{{ t('Restore selected') }}</Button>
            <Button v-if="tab !== 'trash'" size="sm" variant="danger" @click="askDeleteSelected"><Trash2 class="h-4 w-4" />{{ t('Delete selected') }}</Button>
        </span>
    </div>

    <div v-if="urduMode" class="mb-3 flex items-center justify-between rounded-lg border border-haldi-300 bg-haldi-50 px-4 py-2 text-sm">
        <span>{{ t('Type the Urdu name next to each product on this page, then save. Names are used exactly as you type them.') }}</span>
        <span class="flex gap-2"><Button size="sm" @click="urduMode = false">{{ t('Cancel') }}</Button><Button size="sm" variant="primary" @click="saveUrdu">{{ t('Save Urdu names') }}</Button></span>
    </div>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th class="w-8"><input v-if="canManage" type="checkbox" class="rounded border-ink-300" :checked="allOnPage" :aria-label="t('Select all')" @change="toggleAll(($event.target as HTMLInputElement).checked)" /></th><th>{{ t('Product') }}</th><th>{{ urduMode ? t('Urdu name') : t('Category') }}</th><th>{{ t('SKU') }}</th><th class="num">{{ t('Available') }}</th><th class="num">{{ t('In-store price') }}</th>
                        <th v-if="showCost" class="num">{{ t('Average cost') }}</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="p in products.data" :key="p.id">
                        <tr v-for="(v, vi) in p.variants" :key="v.id" :class="vi > 0 ? '[&>td]:border-t-0' : ''">
                            <td>
                                <input v-if="vi === 0" type="checkbox" class="rounded border-ink-300" :checked="selectedProducts.includes(p.id)" :aria-label="t('Select')" @change="toggleProduct(p, ($event.target as HTMLInputElement).checked)" />
                                <input v-else v-model="selected" type="checkbox" :value="v.id" class="ms-3 rounded border-ink-300" :aria-label="t('Select')" />
                            </td>
                            <td>
                                <template v-if="vi === 0">
                                    <Link :href="route('catalog.products.edit', p.id)" class="font-medium text-ink-900 hover:underline">{{ p.name }}</Link>
                                    <div v-if="p.name_ur && !urduMode" class="urdu text-[13px] leading-6 text-ink-600" dir="rtl" style="text-align: start">{{ p.name_ur }}</div>
                                    <Badge v-if="!p.is_active && tab === 'active'" status="deactivated" :label="t('Inactive')" class="ms-2" />
                                    <div v-if="tab === 'trash'" class="text-[12.5px] text-ink-500">{{ t('Removed for good in :n days', { n: p.trash_days_left }) }}</div>
                                    <div v-else-if="tab === 'deactivated'" class="text-[12.5px] text-ink-500">{{ t('Hidden from the POS; kept for its history') }}</div>
                                </template>
                                <div v-if="p.variants.length > 1" class="text-[13px] text-ink-600">{{ v.name }}</div>
                            </td>
                            <td>
                                <input v-if="urduMode && vi === 0" v-model="urdu[p.id]" class="input urdu py-1 text-right" dir="rtl" lang="ur" />
                                <span v-else-if="vi === 0" class="text-ink-600">{{ p.category?.name ?? '—' }}</span>
                            </td>
                            <td class="text-[13px] text-ink-600">{{ v.sku }}<div v-if="v.barcodes?.length" class="text-[12px] text-ink-400">{{ v.barcodes[0].code }}</div></td>
                            <td class="num" :class="stockTone(v)">{{ qty(v.available) }} <span class="text-[12px] text-ink-400">{{ v.base_unit?.code }}</span></td>
                            <td class="num">{{ money(v.retail_price) }}</td>
                            <td v-if="showCost" class="num text-ink-600">{{ money(v.average_cost) }}</td>
                            <td class="whitespace-nowrap text-end">
                                <template v-if="vi === 0">
                                    <template v-if="tab === 'trash'">
                                        <button v-if="canManage" type="button" class="link inline-flex items-center gap-1 text-[13px]" @click="restore([p.id])"><RotateCcw class="h-3.5 w-3.5" />{{ t('Restore') }}</button>
                                        <button v-if="canManage && isOwner" type="button" class="ms-3 inline-flex align-middle text-ink-400 hover:text-ledger-red" :title="t('Delete permanently')" :aria-label="t('Delete permanently')" @click="toPurge = p"><Trash2 class="h-4 w-4" /></button>
                                    </template>
                                    <template v-else>
                                        <Link :href="route('catalog.products.edit', p.id)" class="link text-[13px]">{{ t('Edit') }}</Link>
                                        <button v-if="canManage && tab === 'deactivated'" type="button" class="link ms-3 inline-flex items-center gap-1 text-[13px]" @click="restore([p.id])"><RotateCcw class="h-3.5 w-3.5" />{{ t('Restore') }}</button>
                                        <button v-if="canManage && tab === 'active'" type="button" class="ms-3 inline-flex align-middle text-ink-400 hover:text-ledger-red" :title="t('Delete')" :aria-label="t('Delete')" @click="askDelete([p])"><Trash2 class="h-4 w-4" /></button>
                                    </template>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!products.data.length"><td colspan="8" class="py-10 text-center text-ink-500">{{ emptyText }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :meta="products" />
    </section>

    <Modal :open="!!toDelete" :title="toDelete && toDelete.ids.length > 1 ? t('Delete :n products?', { n: toDelete.ids.length }) : t('Delete product?')" size="sm" @close="toDelete = null">
        <ul class="max-h-40 list-disc space-y-0.5 overflow-y-auto ps-5 text-sm text-ink-800"><li v-for="(n, i) in toDelete?.names ?? []" :key="i">{{ n }}</li></ul>
        <p class="mt-3 text-[13px] text-ink-600">{{ t('Products that never had stock or sales go to the Trash and can be restored for :n days. Products with stock or sales history move to the Deactivated tab and can be restored any time. Their SKUs and barcodes stay reserved.', { n: trashDays }) }}</p>
        <template #footer>
            <Button @click="toDelete = null">{{ t('Cancel') }}</Button>
            <Button variant="danger" :loading="deleting" @click="confirmDelete">{{ t('Delete') }}</Button>
        </template>
    </Modal>

    <Modal :open="!!toPurge" :title="t('Delete permanently?')" size="sm" @close="toPurge = null">
        <p class="text-sm text-ink-800">{{ toPurge?.name }}</p>
        <p class="mt-3 text-[13px] text-ink-600">{{ t('This removes the product now and frees its SKU and barcodes. It cannot be undone.') }}</p>
        <template #footer>
            <Button @click="toPurge = null">{{ t('Cancel') }}</Button>
            <Button variant="danger" :loading="deleting" @click="confirmPurge">{{ t('Delete permanently') }}</Button>
        </template>
    </Modal>
</template>
