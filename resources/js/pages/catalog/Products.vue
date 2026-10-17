<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Download, Languages, Plus, Printer, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useFilters } from '@/composables/useFilters';
import { money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';
import { can } from '@/lib/perms';

/** Product list with stock in the working store, retail price and (for managers) cost. */
const props = defineProps<{ products: any; filters: Record<string, any>; categories: any[]; brands: any[]; missingUrdu: number }>();
const filters = useFilters('catalog.products.index', { q: props.filters.q ?? '', category_id: props.filters.category_id ?? '', brand_id: props.filters.brand_id ?? '', status: props.filters.status ?? 'active', missing: props.filters.missing ?? '' });

const urduMode = ref(false);
const urdu = ref<Record<number, string>>({});
const selected = ref<number[]>([]);
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
const stockTone = (v: any) => (Number(v.available) <= 0 ? 'text-ledger-red' : 'text-ink-800');
</script>

<template>
    <PageHeader :title="t('Products')">
        <template #actions>
            <Button v-if="can('catalog.labels.print') && selected.length" @click="printLabels"><Printer class="h-4 w-4" />{{ t('Print labels') }} ({{ selected.length }})</Button>
            <Button v-if="can('catalog.products.manage') && !urduMode" @click="startUrdu"><Languages class="h-4 w-4" />{{ t('Type Urdu names') }}</Button>
            <Button v-if="can('catalog.products.import')" :href="route('catalog.import')"><Upload class="h-4 w-4" />{{ t('Import') }}</Button>
            <a v-if="can('catalog.products.export')" :href="route('catalog.export')" class="inline-flex h-10 items-center gap-2 rounded border border-ink-200 bg-white px-4 text-sm font-medium text-ink-800 hover:bg-ink-50"><Download class="h-4 w-4" />{{ t('Export') }}</a>
            <Button v-if="can('catalog.products.manage')" variant="primary" :href="route('catalog.products.create')"><Plus class="h-4 w-4" />{{ t('Add product') }}</Button>
        </template>
    </PageHeader>

    <div class="panel mb-4 flex flex-wrap items-end gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Name, Urdu name, SKU or barcode')" />
        <select v-model="filters.category_id" class="input max-w-[12rem]"><option value="">{{ t('All categories') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <select v-model="filters.brand_id" class="input max-w-[12rem]"><option value="">{{ t('All brands') }}</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="filters.status" class="input max-w-[10rem]"><option value="active">{{ t('Active') }}</option><option value="inactive">{{ t('Inactive') }}</option><option value="all">{{ t('All') }}</option></select>
        <label class="flex items-center gap-2 text-sm text-ink-700"><input v-model="filters.missing" type="checkbox" true-value="urdu" false-value="" class="rounded border-ink-300" />{{ t('Missing Urdu name') }} <span class="text-ink-400">({{ missingUrdu }})</span></label>
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
                        <th class="w-8"></th><th>{{ t('Product') }}</th><th>{{ urduMode ? t('Urdu name') : t('Category') }}</th><th>{{ t('SKU') }}</th><th class="num">{{ t('Available') }}</th><th class="num">{{ t('Retail price') }}</th>
                        <th v-if="showCost" class="num">{{ t('Average cost') }}</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="p in products.data" :key="p.id">
                        <tr v-for="(v, vi) in p.variants" :key="v.id" :class="vi > 0 ? '[&>td]:border-t-0' : ''">
                            <td><input v-model="selected" type="checkbox" :value="v.id" class="rounded border-ink-300" :aria-label="t('Select')" /></td>
                            <td>
                                <template v-if="vi === 0">
                                    <Link :href="route('catalog.products.edit', p.id)" class="font-medium text-ink-900 hover:underline">{{ p.name }}</Link>
                                    <div v-if="p.name_ur && !urduMode" class="urdu text-[13px] leading-6 text-ink-600" dir="rtl" style="text-align: start">{{ p.name_ur }}</div>
                                    <Badge v-if="!p.is_active" status="deactivated" :label="t('Inactive')" class="ms-2" />
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
                            <td class="text-end"><Link v-if="vi === 0" :href="route('catalog.products.edit', p.id)" class="link text-[13px]">{{ t('Edit') }}</Link></td>
                        </tr>
                    </template>
                    <tr v-if="!products.data.length"><td colspan="8" class="py-10 text-center text-ink-500">{{ t('No products match. Add one, or import a list from a CSV file.') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :meta="products" />
    </section>
</template>
