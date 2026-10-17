<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Download, Info, RefreshCw, Star } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import SeriesChart from '@/components/charts/SeriesChart.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { date, dateTime, money, percent, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * One page renders every report: filters, comparison, summary cards, chart,
 * insights, the table and the definition. Changing a filter reloads from the
 * server so the table, totals and export always agree.
 */
const props = defineProps<{
    report: any; columns: Record<string, { label: string; type: string; sortable?: boolean }>; rows: any[]; pagination: { total: number; page: number; last_page: number; per_page: number };
    summary: any[]; chart: { type: string; labels: string[]; series: { name: string; data: any[] }[] } | null; insights: any[]; filters: Record<string, any>;
    stores: { id: number; name: string }[]; views: any[]; activeView: number | null; favorite: boolean; canExport: boolean; canRunForecast: boolean;
}>();

const presets: Record<string, string> = { today: 'Today', yesterday: 'Yesterday', this_week: 'This week', last_week: 'Last week', this_month: 'This month', last_month: 'Last month', this_quarter: 'This quarter', last_quarter: 'Last quarter', year_to_date: 'Year to date', last_year: 'Last year', last_7_days: 'Last 7 days', last_30_days: 'Last 30 days', custom: 'Custom dates' };
const f = reactive<Record<string, any>>({
    preset: props.filters.preset ?? 'this_month', from: props.filters.from ?? '', to: props.filters.to ?? '', compare: props.filters.compare ?? 'previous_period',
    store_ids: props.filters.store_ids ?? props.stores.map((s) => s.id), group_by: props.filters.group_by ?? Object.keys(props.report.group_by ?? {})[0] ?? '', search: props.filters.search ?? '',
    sort: props.filters.sort ?? '', direction: props.filters.direction ?? 'asc', per_page: props.filters.per_page ?? 50,
    product_id: props.filters.product_id ?? '', variant_id: props.filters.variant_id ?? '', cashier_id: props.filters.cashier_id ?? '', payment_method_id: props.filters.payment_method_id ?? '',
    category_id: props.filters.category_id ?? '', brand_id: props.filters.brand_id ?? '', customer_id: props.filters.customer_id ?? '', supplier_id: props.filters.supplier_id ?? '', account_id: props.filters.account_id ?? '',
});
const customer = ref<any>(props.filters.customer_id ? { id: props.filters.customer_id, name: t('Chosen customer') } : null);
const supplier = ref<any>(props.filters.supplier_id ? { id: props.filters.supplier_id, name: t('Chosen supplier') } : null);
watch(customer, (c) => { if ((c?.id ?? '') !== f.customer_id) { f.customer_id = c?.id ?? ''; apply(); } });
watch(supplier, (c) => { if ((c?.id ?? '') !== f.supplier_id) { f.supplier_id = c?.id ?? ''; apply(); } });
const wants = (k: string) => (props.report.filters ?? []).includes(k);
const options = useOptions([...(wants('cashier') ? ['users'] : []), ...(wants('payment_method') ? ['payment_methods'] : []), ...(wants('category') ? ['categories'] : []), ...(wants('brand') ? ['brands'] : []), ...(wants('account') ? ['accounts'] : [])]);
const productLabel = ref<string>(props.filters.product_id || props.filters.variant_id ? t('Chosen product') : '');

function query(extra: Record<string, any> = {}) {
    const q: Record<string, any> = { ...f, ...extra };
    if (q.preset !== 'custom') { delete q.from; delete q.to; }
    if (!props.report.comparison) delete q.compare;
    if (q.store_ids?.length === props.stores.length) delete q.store_ids;
    return Object.fromEntries(Object.entries(q).filter(([, v]) => v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && !v.length)));
}
function apply(extra: Record<string, any> = {}) {
    router.get(route('reports.show', props.report.key), query({ page: 1, ...extra }), { preserveScroll: true, preserveState: true, replace: true });
}
let searchTimer: ReturnType<typeof setTimeout> | undefined;
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => apply(), 350);
}
function sortBy(key: string) {
    if (props.columns[key]?.sortable === false) return;
    f.direction = f.sort === key && f.direction === 'asc' ? 'desc' : 'asc';
    f.sort = key;
    apply();
}
function goPage(p: number) {
    router.get(route('reports.show', props.report.key), query({ page: p }), { preserveScroll: false, preserveState: true });
}
function toggleStore(id: number) {
    f.store_ids = f.store_ids.includes(id) ? f.store_ids.filter((s: number) => s !== id) : [...f.store_ids, id];
    if (f.store_ids.length) apply();
}

const visible = computed(() => Object.entries(props.columns).filter(([, c]) => c.type !== 'hidden'));
function cell(type: string, v: any) {
    if (v === null || v === undefined || v === '') return '—';
    switch (type) {
        case 'money': return money(v);
        case 'qty': case 'decimal': return qty(v);
        case 'int': return Number(v).toLocaleString('en-PK');
        case 'percent': return percent(v);
        case 'date': return date(v);
        case 'datetime': return dateTime(v);
        default: return typeof v === 'string' ? t(v) : v;
    }
}
const numeric = (type: string) => ['money', 'qty', 'int', 'decimal', 'percent'].includes(type);
function card(c: any) {
    return c.type === 'text' ? t(String(c.value ?? '—')) : cell(c.type, c.value ?? 0);
}

/** Clicking the key column opens the underlying document. */
const drillOk = computed(() => !!props.report.drill);
function drillHref(row: any) {
    const d = props.report.drill;
    const id = row[d.param] ?? row.id;
    return id ? String(route(d.route, id)) : null;
}

const chartFormat = computed(() => {
    const moneyish = props.summary[0]?.type === 'money' || /sales|value|amount|cost|margin|profit|cash/i.test(props.chart?.series?.[0]?.name ?? '');
    return (v: number) => (moneyish ? money(v) : qty(v));
});

const showDefinition = ref(false);
const saving = ref(false);
const viewForm = useForm({ name: '', is_default: false, filters: {} as Record<string, any> });
function saveView() {
    viewForm.filters = query();
    viewForm.post(route('reports.views.store', props.report.key), { onSuccess: () => (saving.value = false), preserveScroll: true });
}
function openView(id: string) {
    router.get(route('reports.show', props.report.key), id ? { view: id } : { preset: 'this_month' });
}
function exportCsv() {
    router.post(route('reports.export', props.report.key), query());
}
function star() {
    router.post(route('reports.favorite', props.report.key), {}, { preserveScroll: true, only: ['favorite'] });
}
const forecastBusy = ref(false);
function runForecast() {
    forecastBusy.value = true;
    router.post(route('reports.forecasts.run'), {}, { preserveScroll: true, onFinish: () => (forecastBusy.value = false) });
}
function exportFile(): string {
    // Inertia's POST follows the redirect to the download; a plain link is used so the browser saves the file.
    const params = new URLSearchParams();
    Object.entries(query()).forEach(([k, v]) => (Array.isArray(v) ? v.forEach((x) => params.append(`${k}[]`, String(x))) : params.append(k, String(v))));
    return params.toString();
}
const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
</script>

<template>
    <PageHeader :title="localName(report)" :subtitle="t(report.description)">
        <template #actions>
            <button type="button" class="text-ink-400 hover:text-haldi-600" :class="favorite ? 'text-haldi-500' : ''" :aria-label="t('Star')" @click="star"><Star class="h-5 w-5" :fill="favorite ? 'currentColor' : 'none'" /></button>
            <Button @click="showDefinition = true"><Info class="h-4 w-4" />{{ t('How it is calculated') }}</Button>
            <Button v-if="canRunForecast" :loading="forecastBusy" @click="runForecast"><RefreshCw class="h-4 w-4" />{{ t('Recalculate forecast') }}</Button>
            <form v-if="canExport" method="post" :action="route('reports.export', report.key) + '?' + exportFile()" class="inline">
                <input type="hidden" name="_token" :value="csrf" />
                <Button type="submit" variant="primary"><Download class="h-4 w-4" />{{ t('Download CSV') }}</Button>
            </form>
        </template>
    </PageHeader>

    <section class="panel mb-4 p-3">
        <div class="flex flex-wrap items-end gap-3">
            <label v-if="wants('period')"><span class="field-label">{{ t('Period') }}</span>
                <select v-model="f.preset" class="input w-44" @change="f.preset !== 'custom' && apply()"><option v-for="(l, k) in presets" :key="k" :value="k">{{ t(l) }}</option></select>
            </label>
            <template v-if="wants('period') && f.preset === 'custom'">
                <label><span class="field-label">{{ t('From') }}</span><input v-model="f.from" type="date" class="input w-40" @change="f.from && f.to && apply()" /></label>
                <label><span class="field-label">{{ t('To') }}</span><input v-model="f.to" type="date" class="input w-40" @change="f.from && f.to && apply()" /></label>
            </template>
            <label v-if="report.comparison"><span class="field-label">{{ t('Compare with') }}</span>
                <select v-model="f.compare" class="input w-44" @change="apply()"><option value="previous_period">{{ t('Previous period') }}</option><option value="previous_year">{{ t('Same time last year') }}</option><option value="none">{{ t('No comparison') }}</option></select>
            </label>
            <label v-if="Object.keys(report.group_by ?? {}).length"><span class="field-label">{{ t('Group by') }}</span>
                <select v-model="f.group_by" class="input w-36" @change="apply()"><option v-for="(l, k) in report.group_by" :key="k" :value="k">{{ t(l as string) }}</option></select>
            </label>
            <label v-if="wants('cashier')"><span class="field-label">{{ t('Cashier') }}</span>
                <select v-model="f.cashier_id" class="input w-40" @change="apply()"><option value="">{{ t('Everyone') }}</option><option v-for="u in options.users ?? []" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </label>
            <label v-if="wants('payment_method')"><span class="field-label">{{ t('Payment method') }}</span>
                <select v-model="f.payment_method_id" class="input w-40" @change="apply()"><option value="">{{ t('All') }}</option><option v-for="m in options.payment_methods ?? []" :key="m.id" :value="m.id">{{ localName(m) }}</option></select>
            </label>
            <label v-if="wants('category')"><span class="field-label">{{ t('Category') }}</span>
                <select v-model="f.category_id" class="input w-44" @change="apply()"><option value="">{{ t('All') }}</option><option v-for="c in options.categories ?? []" :key="c.id" :value="c.id">{{ localName(c) }}</option></select>
            </label>
            <label v-if="wants('brand')"><span class="field-label">{{ t('Brand') }}</span>
                <select v-model="f.brand_id" class="input w-40" @change="apply()"><option value="">{{ t('All') }}</option><option v-for="b in options.brands ?? []" :key="b.id" :value="b.id">{{ localName(b) }}</option></select>
            </label>
            <label v-if="wants('account')"><span class="field-label">{{ t('Account') }}</span>
                <select v-model="f.account_id" class="input w-52" @change="apply()"><option value="">{{ t('All') }}</option><option v-for="a in options.accounts ?? []" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option></select>
            </label>
            <div v-if="wants('customer')" class="w-56"><span class="field-label">{{ t('Customer') }}</span><ContactPicker v-model="customer" type="customer" /></div>
            <div v-if="wants('supplier')" class="w-56"><span class="field-label">{{ t('Supplier') }}</span><ContactPicker v-model="supplier" type="supplier" /></div>
            <div v-if="wants('product')" class="min-w-[14rem]"><span class="field-label">{{ t('Product') }}</span>
                <div v-if="productLabel" class="flex items-center gap-2 rounded border border-ink-200 px-2 py-1.5 text-sm">{{ productLabel }}<button class="link ms-auto" @click="productLabel = ''; f.product_id = ''; f.variant_id = ''; apply()">{{ t('Clear') }}</button></div>
                <ProductPicker v-else purpose="any" :show-stock="false" @pick="(p: any) => { productLabel = p.name; f.variant_id = p.variant_id; apply(); }" />
            </div>
            <label v-if="wants('search')" class="min-w-[12rem] flex-1"><span class="field-label">{{ t('Search') }}</span><input v-model="f.search" type="search" class="input" @input="onSearch" /></label>
            <div class="ms-auto flex items-end gap-2">
                <label v-if="views.length"><span class="field-label">{{ t('Saved views') }}</span>
                    <select class="input w-40" :value="activeView ?? ''" @change="openView(($event.target as HTMLSelectElement).value)"><option value="">—</option><option v-for="v in views" :key="v.id" :value="v.id">{{ v.name }}{{ v.is_default ? ' ★' : '' }}</option></select>
                </label>
                <Button size="sm" @click="saving = true">{{ t('Save view') }}</Button>
            </div>
        </div>
        <div v-if="wants('stores') && stores.length > 1" class="mt-3 flex flex-wrap items-center gap-3 border-t border-ink-100 pt-3 text-sm">
            <span class="text-ink-600">{{ t('Stores') }}:</span>
            <label v-for="s in stores" :key="s.id" class="flex items-center gap-1.5"><input type="checkbox" :checked="f.store_ids.includes(s.id)" class="rounded border-ink-300" @change="toggleStore(s.id)" />{{ s.name }}</label>
        </div>
        <p v-if="wants('period')" class="mt-2 text-[12.5px] text-ink-500">
            {{ date(filters.from) }} – {{ date(filters.to) }}<span v-if="filters.compare_from"> · {{ t('compared with') }} {{ date(filters.compare_from) }} – {{ date(filters.compare_to) }}</span>
        </p>
    </section>

    <div v-if="summary.length" class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <div v-for="c in summary" :key="c.key" class="panel p-3">
            <div class="text-[12.5px] text-ink-500">{{ t(c.label) }}</div>
            <div class="mt-0.5 text-xl font-semibold tabular-nums text-ink-900">{{ card(c) }}</div>
            <div v-if="c.change !== null && c.change !== undefined" class="mt-0.5 flex items-center gap-1 text-[12px]" :class="Number(c.change) >= 0 ? 'text-ledger-green' : 'text-ledger-red'">
                <component :is="Number(c.change) >= 0 ? ArrowUp : ArrowDown" class="h-3 w-3" />{{ percent(Math.abs(Number(c.change))) }}
                <span class="text-ink-500">{{ t('vs') }} {{ cell(c.type, c.previous) }}</span>
            </div>
            <div v-if="c.note" class="mt-0.5 text-[12px] text-ink-500">{{ t(c.note) }}</div>
        </div>
    </div>

    <div class="mb-4 grid gap-4" :class="insights.length && chart ? 'xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : ''">
        <section v-if="chart && chart.labels.length" class="panel p-4"><SeriesChart :type="chart.type" :labels="chart.labels" :series="chart.series" :format="chartFormat" /></section>
        <section v-if="insights.length" class="panel p-4">
            <h3 class="mb-2">{{ t('What stands out') }}</h3>
            <ul class="space-y-3">
                <li v-for="(i, n) in insights" :key="n" class="text-sm">
                    <div class="flex gap-2"><Badge :tone="i.kind === 'warning' ? 'warn' : 'info'" :label="i.kind === 'warning' ? t('Check') : t('Note')" /><span class="text-ink-800">{{ i.text }}</span></div>
                    <div v-if="i.evidence" class="ms-1 mt-0.5 text-[12px] text-ink-500">{{ i.evidence }}</div>
                </li>
            </ul>
        </section>
    </div>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th v-for="[k, c] in visible" :key="k" :class="[numeric(c.type) ? 'num' : '', c.sortable === false ? '' : 'cursor-pointer select-none hover:text-ink-900']" @click="sortBy(k)">
                            {{ t(c.label) }}<span v-if="f.sort === k" class="ms-1">{{ f.direction === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i">
                        <td v-for="[k, c] in visible" :key="k" :class="numeric(c.type) ? 'num' : ''">
                            <a v-if="drillOk && report.drill.column === k && drillHref(r)" :href="drillHref(r)!" class="link">{{ cell(c.type, r[k]) }}</a>
                            <Badge v-else-if="c.type === 'status'" :status="r[k]" />
                            <span v-else :class="numeric(c.type) && Number(r[k]) < 0 ? 'text-ledger-red' : ''">{{ cell(c.type, r[k]) }}</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td :colspan="visible.length" class="py-10 text-center text-ink-500">{{ t('No data for these filters.') }}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 px-4 py-2.5 text-sm text-ink-600">
            <span>{{ t('Rows') }}: <b class="tabular-nums">{{ pagination.total.toLocaleString('en-PK') }}</b></span>
            <select v-model="f.per_page" class="input w-24 py-1" @change="apply()"><option v-for="n in [25, 50, 100, 250]" :key="n" :value="n">{{ n }}</option></select>
            <div class="ms-auto flex items-center gap-2">
                <Button size="sm" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">‹</Button>
                <span class="tabular-nums">{{ pagination.page }} / {{ pagination.last_page }}</span>
                <Button size="sm" :disabled="pagination.page >= pagination.last_page" @click="goPage(pagination.page + 1)">›</Button>
            </div>
        </div>
    </section>

    <Modal :open="showDefinition" :title="t('How it is calculated')" size="lg" @close="showDefinition = false">
        <dl class="space-y-3 text-sm">
            <div v-if="report.definition?.source"><dt class="field-label">{{ t('Data source') }}</dt><dd>{{ report.definition.source }}</dd></div>
            <div v-if="report.definition?.date_basis"><dt class="field-label">{{ t('Dates are based on') }}</dt><dd>{{ report.definition.date_basis }}</dd></div>
            <div v-if="report.definition?.definitions"><dt class="field-label">{{ t('Terms') }}</dt>
                <dd><ul class="space-y-1"><li v-for="(v, k) in report.definition.definitions" :key="k"><b>{{ k }}:</b> {{ v }}</li></ul></dd>
            </div>
            <div v-if="report.definition?.limitations?.length"><dt class="field-label">{{ t('Limitations') }}</dt><dd><ul class="list-disc space-y-1 ps-5"><li v-for="(l, n) in report.definition.limitations" :key="n">{{ l }}</li></ul></dd></div>
            <div v-if="report.sensitive" class="rounded bg-haldi-50 px-3 py-2">{{ t('This report holds sensitive figures. Only people with the matching permission can open or download it, and each download is logged.') }}</div>
        </dl>
    </Modal>
    <Modal :open="saving" :title="t('Save view')" size="sm" @close="saving = false">
        <div class="space-y-3">
            <label class="block"><span class="field-label">{{ t('Name') }}</span><input v-model="viewForm.name" class="input" :placeholder="t('e.g. Main store, this month')" /></label>
            <label class="flex items-center gap-2 text-sm"><input v-model="viewForm.is_default" type="checkbox" class="rounded border-ink-300" />{{ t('Open this view by default') }}</label>
            <p v-if="viewForm.errors.name" class="text-sm text-ledger-red">{{ viewForm.errors.name }}</p>
        </div>
        <template #footer>
            <Button v-if="activeView" variant="danger" @click="router.delete(route('reports.views.destroy', activeView))">{{ t('Delete current view') }}</Button>
            <Button @click="saving = false">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!viewForm.name" :loading="viewForm.processing" @click="saveView">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
