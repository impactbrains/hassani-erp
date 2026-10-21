<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ArrowDownRight, ArrowUpRight, CircleAlert, CircleCheck, ClipboardCheck, ShoppingCart } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import ColumnChart from '@/components/charts/ColumnChart.vue';
import { appTimezone, currentHour, dateTime, money, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';
import { can } from '@/lib/perms';

/**
 * Home. Answers the five daily questions from the POS brief: how much did we
 * sell, what sold best, how did people pay, did every shift close
 * correctly, and do the stock counts match.
 */
const props = defineProps<{ dashboard: any | null }>();
const page = usePage<any>();
const d = computed(() => props.dashboard);
const preset = computed(() => d.value?.filters?.preset ?? 'today');
const showTable = ref(false);

const presets = [
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['this_week', 'This week'],
    ['this_month', 'This month'],
    ['last_30_days', 'Last 30 days'],
    ['last_month', 'Last month'],
] as const;

const compareChoices = [
    ['previous_period', 'The period just before'],
    ['previous_year', 'Same dates last year'],
    ['none', 'Nothing'],
] as const;

// Custom range pickers start on whatever period is showing.
const fromDate = ref<string>(props.dashboard?.period?.from ?? '');
const toDate = ref<string>(props.dashboard?.period?.to ?? '');
watch(() => props.dashboard?.period, (p) => {
    if (p) { fromDate.value = p.from; toDate.value = p.to; }
});
const compare = computed<string>(() => d.value?.filters?.compare ?? 'previous_period');
const rangeError = computed(() => {
    const serverError = (page.props as any).errors?.from ?? (page.props as any).errors?.to;
    if (fromDate.value && toDate.value && toDate.value < fromDate.value) return t('The end date is before the start date.');
    return serverError ?? null;
});

const card = (key: string) => d.value?.sales?.find((s: any) => s.key === key);
const hero = computed(() => card(d.value?.financials ? 'net_sales' : 'transactions'));
const strip = computed(() =>
    [
        { key: 'transactions', label: 'Transactions', fmt: (v: any) => qty(v), invert: false },
        { key: 'average_basket', label: 'Average basket', fmt: (v: any) => money(v), invert: false },
        { key: 'gross_margin', label: 'Gross margin', fmt: (v: any) => money(v), invert: false },
        { key: 'collected', label: 'Money collected', fmt: (v: any) => money(v), invert: false },
        { key: 'khata_extended', label: 'Put on Khata', fmt: (v: any) => money(v), invert: true },
        { key: 'returns', label: 'Returns', fmt: (v: any) => money(v), invert: true },
    ].filter((x) => card(x.key) && (d.value?.financials || x.key !== 'transactions')),
);

const hours = computed(() => {
    const rows: any[] = d.value?.hourly ?? [];
    const byHour = new Map<number, any>(rows.map((h) => [h.hour, h]));
    const used = rows.map((h) => h.hour);
    const from = Math.min(8, ...used);
    const to = Math.max(22, ...used);
    const list: { hour: number; sales: number; transactions: number }[] = [];
    for (let h = from; h <= to; h++) list.push({ hour: h, sales: Number(byHour.get(h)?.sales ?? 0), transactions: Number(byHour.get(h)?.transactions ?? 0) });
    return list;
});
const hourLabel = (h: number) => (h === 0 ? '12am' : h < 12 ? `${h}am` : h === 12 ? '12pm' : `${h - 12}pm`);
const topMax = computed(() => Math.max(1, ...(d.value?.top_products ?? []).map((p: any) => Number(p.sales ?? p.units))));
const payTotal = computed(() => (d.value?.payment_methods ?? []).reduce((s: number, m: any) => s + Math.max(0, Number(m.amount)), 0) || 1);

const ALERTS: Record<string, string> = {
    open_shifts_old: 'Open shifts older than 16 hours',
    variance_review: 'Cash differences to review',
    payments_to_verify: 'Wallet/card payments to verify',
    adjustments_to_approve: 'Stock adjustments to approve',
    counts_to_review: 'Stock counts to review',
    low_stock: 'Products at reorder level',
    near_expiry: 'Batches expiring within 30 days',
    pos_to_approve: 'Purchase orders to approve',
    leave_to_review: 'Leave requests to review',
    orders_to_confirm: 'Orders to confirm',
    cash_with_riders: 'Riders holding cash',
    overdue_khata: 'Customers with overdue Khata',
};

const greeting = computed(() => {
    const h = currentHour();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
});
const periodText = computed(() => {
    if (!d.value) return null;
    const fmt = (s: string) => new Date(s + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
    return d.value.period.from === d.value.period.to ? fmt(d.value.period.from) : `${fmt(d.value.period.from)} – ${fmt(d.value.period.to)}`;
});

function visit(query: Record<string, string>) {
    router.get(route('dashboard'), query, { preserveScroll: true, preserveState: true, only: ['dashboard', 'errors'] });
}
function setPreset(p: string) {
    visit({ preset: p, compare: compare.value });
}
function applyRange() {
    if (!fromDate.value || !toDate.value || rangeError.value) return;
    visit({ preset: 'custom', from: fromDate.value, to: toDate.value, compare: compare.value });
}
function setCompare(c: string) {
    visit(preset.value === 'custom' ? { preset: 'custom', from: fromDate.value, to: toDate.value, compare: c } : { preset: preset.value, compare: c });
}
const shortDate = (s: string) => new Date(s + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
/** What the arrows on the cards are measured against, with the real dates. */
const compareText = computed(() => {
    const p = d.value?.period;
    if (!p?.compare_from) return null;
    const range = p.compare_from === p.compare_to ? shortDate(p.compare_from) : `${shortDate(p.compare_from)} – ${shortDate(p.compare_to)}`;
    return `${t(compare.value === 'previous_year' ? 'vs same dates last year' : 'vs previous period')} (${range})`;
});
function change(c: any, invert = false) {
    if (c?.change === null || c?.change === undefined) return null;
    const n = Number(c.change);
    return { n: Math.abs(n), up: n >= 0, good: invert ? n <= 0 : n >= 0 };
}
</script>

<template>
    <PageHeader :title="`${t(greeting)}, ${page.props.auth.user.name.split(' ')[0]}`" :subtitle="periodText">
        <template #actions>
            <div v-if="d" class="flex rounded border border-ink-200 bg-white p-0.5 text-[13px]" role="group">
                <button
                    v-for="[key, label] in presets"
                    :key="key"
                    class="rounded px-2.5 py-1.5 font-medium"
                    :class="preset === key ? 'bg-ink-800 text-white' : 'text-ink-600 hover:bg-ink-50'"
                    @click="setPreset(key)"
                >
                    {{ t(label) }}
                </button>
            </div>
            <Button v-if="can('pos.access')" variant="primary" :href="route('pos.screen')"><ShoppingCart class="h-4 w-4" />{{ t('Open POS') }}</Button>
        </template>
    </PageHeader>

    <section v-if="d" class="panel mb-4 flex flex-wrap items-end gap-x-4 gap-y-3 p-3" :aria-label="t('Choose dates')">
        <div>
            <label for="dash-from" class="mb-1 block text-[12.5px] font-medium text-ink-600">{{ t('From date') }}</label>
            <input id="dash-from" v-model="fromDate" type="date" class="input h-10" :max="toDate || undefined" />
        </div>
        <div>
            <label for="dash-to" class="mb-1 block text-[12.5px] font-medium text-ink-600">{{ t('To date') }}</label>
            <input id="dash-to" v-model="toDate" type="date" class="input h-10" :min="fromDate || undefined" />
        </div>
        <Button variant="primary" :disabled="!fromDate || !toDate || !!rangeError" @click="applyRange">{{ t('Show these dates') }}</Button>
        <div class="ms-auto">
            <label for="dash-compare" class="mb-1 block text-[12.5px] font-medium text-ink-600">{{ t('Compare with') }}</label>
            <select id="dash-compare" class="input h-10" :value="compare" @change="setCompare(($event.target as HTMLSelectElement).value)">
                <option v-for="[key, label] in compareChoices" :key="key" :value="key">{{ t(label) }}</option>
            </select>
        </div>
        <p v-if="rangeError" class="w-full text-[13px] text-ledger-red" role="alert">{{ rangeError }}</p>
    </section>

    <div v-if="!d" class="panel p-8 text-center text-ink-600">
        <p class="text-base">{{ t('Your dashboard appears here once the owner gives you access to it.') }}</p>
        <div class="mt-4 flex justify-center gap-2">
            <Button :href="route('me')">{{ t('My work') }}</Button>
            <Button v-if="can('pos.access')" variant="primary" :href="route('pos.screen')">{{ t('Open POS') }}</Button>
        </div>
    </div>

    <template v-else>
        <!-- How much did we sell -->
        <section class="panel mb-5 grid overflow-hidden lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <div class="bg-ink-800 px-6 py-5 text-white">
                <div class="text-sm text-ink-200">{{ t(d.financials ? 'Net sales' : 'Transactions') }}</div>
                <div class="mt-1.5 text-[2.75rem] font-semibold leading-none tracking-tight tabular-nums">
                    {{ d.financials ? money(hero?.value) : qty(hero?.value) }}
                </div>
                <div v-if="change(hero)" class="mt-3 flex flex-wrap items-center gap-1.5 text-sm">
                    <span class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 font-medium" :class="change(hero)!.good ? 'bg-ledger-green/30 text-[#9BE6C1]' : 'bg-ledger-red/30 text-[#FFB9AE]'">
                        <ArrowUpRight v-if="change(hero)!.up" class="h-4 w-4" />
                        <ArrowDownRight v-else class="h-4 w-4" />
                        {{ change(hero)!.n }}%
                    </span>
                    <span class="text-ink-300">{{ compareText }} · {{ d.financials ? money(hero?.previous) : qty(hero?.previous) }}</span>
                </div>
                <p v-if="d.financials && !d.cost_complete" class="mt-3 text-[13px] text-haldi-300">{{ t('Some product costs are unknown, so margin is overstated.') }}</p>
            </div>
            <dl class="grid grid-cols-2 sm:grid-cols-3">
                <div v-for="item in strip" :key="item.key" class="border-b border-e border-ink-100 px-5 py-4">
                    <dt class="text-[13px] text-ink-500">{{ t(item.label) }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-ink-900">{{ item.fmt(card(item.key)?.value) }}</dd>
                    <dd v-if="change(card(item.key), item.invert)" class="mt-0.5 text-[12.5px] tabular-nums" :class="change(card(item.key), item.invert)!.good ? 'text-ledger-green' : 'text-ledger-red'">
                        {{ change(card(item.key))!.up ? '▲' : '▼' }} {{ change(card(item.key))!.n }}%
                    </dd>
                </div>
            </dl>
        </section>

        <div class="mb-5 grid gap-5 xl:grid-cols-3">
            <section class="panel xl:col-span-2">
                <div class="panel-head">
                    <div>
                        <h2>{{ t('Sales by hour') }}</h2>
                        <p class="text-[12px] font-normal text-ink-500">{{ t('Times are shown in :zone', { zone: (d.timezone ?? appTimezone()).replace('_', ' ') }) }}</p>
                    </div>
                    <button v-if="d.hourly.length" class="text-[13px] text-ink-500 underline-offset-2 hover:underline" @click="showTable = !showTable">{{ showTable ? t('Show chart') : t('Show table') }}</button>
                </div>
                <div class="p-4">
                    <p v-if="!d.hourly.length" class="py-12 text-center text-sm text-ink-500">{{ t('No sales yet in this period.') }}</p>
                    <ColumnChart
                        v-else-if="!showTable"
                        :labels="hours.map((h) => hourLabel(h.hour))"
                        :values="hours.map((h) => (d.financials ? h.sales : h.transactions))"
                        :format="(v: number) => (d.financials ? money(v) : qty(v))"
                        :aria-label="t('Sales by hour')"
                    />
                    <table v-else class="table-base">
                        <thead>
                            <tr><th>{{ t('Hour') }}</th><th class="num">{{ t('Transactions') }}</th><th v-if="d.financials" class="num">{{ t('Net sales') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in hours.filter((x) => x.transactions)" :key="h.hour">
                                <td>{{ hourLabel(h.hour) }}</td>
                                <td class="num">{{ h.transactions }}</td>
                                <td v-if="d.financials" class="num">{{ money(h.sales) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>{{ t('Needs attention') }}</h2></div>
                <ul v-if="d.alerts.length" class="divide-y divide-ink-100">
                    <li v-for="alert in d.alerts" :key="alert.key">
                        <Link :href="alert.href" class="flex items-center gap-3 px-4 py-2.5 hover:bg-ink-50/60">
                            <span
                                class="flex h-7 min-w-[1.75rem] items-center justify-center rounded px-1.5 text-[13px] font-semibold tabular-nums"
                                :class="{ danger: 'bg-ledger-redSoft text-ledger-red', warning: 'bg-haldi-50 text-haldi-800', info: 'bg-ink-50 text-ink-700' }[alert.tone as string]"
                            >{{ alert.count }}</span>
                            <span class="text-sm text-ink-800">{{ t(ALERTS[alert.key] ?? alert.key) }}</span>
                        </Link>
                    </li>
                </ul>
                <div v-else class="flex items-center gap-2 px-4 py-6 text-sm text-ledger-green"><CircleCheck class="h-5 w-5" />{{ t('Nothing needs attention right now.') }}</div>
            </section>
        </div>

        <div class="mb-5 grid gap-5 lg:grid-cols-2">
            <!-- What sold best -->
            <section class="panel">
                <div class="panel-head">
                    <h2>{{ t('Best-selling products') }}</h2>
                    <Link v-if="can('reports.products')" :href="route('reports.show', 'products.sales')" class="link text-[13px]">{{ t('View all') }}</Link>
                </div>
                <ol v-if="d.top_products.length" class="px-4 py-2">
                    <li v-for="(p, i) in d.top_products" :key="i" class="py-2">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate"><span class="me-2 inline-block w-4 tabular-nums text-ink-400">{{ i + 1 }}</span><span class="font-medium text-ink-900">{{ localName(p) }}</span></span>
                            <span class="shrink-0 tabular-nums text-ink-600">
                                {{ qty(p.units) }} {{ t('sold') }}<template v-if="d.financials">, <span class="font-medium text-ink-900">{{ money(p.sales) }}</span></template>
                            </span>
                        </div>
                        <div class="ms-6 mt-1.5 h-1.5 rounded-full bg-ink-50">
                            <div class="h-1.5 rounded-full bg-[#3E5C9A]" :style="{ width: `${(Number(p.sales ?? p.units) / topMax) * 100}%` }" />
                        </div>
                    </li>
                </ol>
                <p v-else class="px-4 py-10 text-center text-sm text-ink-500">{{ t('No sales yet in this period.') }}</p>
            </section>

            <!-- How did people pay -->
            <section class="panel">
                <div class="panel-head">
                    <h2>{{ t('How customers paid') }}</h2>
                    <Link v-if="can('reports.sales')" :href="route('reports.show', 'payments.by_method')" class="link text-[13px]">{{ t('View all') }}</Link>
                </div>
                <ul v-if="d.payment_methods.length" class="px-4 py-2">
                    <li v-for="m in d.payment_methods" :key="m.name" class="py-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="font-medium text-ink-900">{{ localName(m) }}</span>
                            <span class="flex items-center gap-2">
                                <Badge v-if="Number(m.pending) > 0" status="pending" :label="`${money(m.pending)} ${t('to verify')}`" />
                                <span class="tabular-nums">{{ money(m.amount) }}</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-ink-50">
                            <div class="h-1.5 rounded-full" :class="m.type === 'credit' ? 'bg-ledger-red/70' : 'bg-[#3E5C9A]'" :style="{ width: `${(Math.max(0, Number(m.amount)) / payTotal) * 100}%` }" />
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-10 text-center text-sm text-ink-500">{{ d.financials ? t('No payments in this period.') : t('Payment figures are visible to managers.') }}</p>
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Did shifts close correctly -->
            <section class="panel">
                <div class="panel-head">
                    <h2>{{ t('Did every shift close correctly?') }}</h2>
                    <Link :href="route('pos.shifts.index')" class="link text-[13px]">{{ t('Shifts') }}</Link>
                </div>
                <div v-if="d.shifts.length" class="overflow-x-auto">
                    <table class="table-base">
                        <thead>
                            <tr><th>{{ t('Shift') }}</th><th>{{ t('Cashier') }}</th><th>{{ t('Closed') }}</th><th class="num">{{ t('Short / over') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in d.shifts" :key="s.id">
                                <td><Link :href="route('pos.shifts.show', s.id)" class="link">{{ s.number }}</Link><div class="text-[12px] text-ink-500">{{ s.register }}</div></td>
                                <td>{{ s.cashier }}</td>
                                <td class="text-ink-600">{{ s.closed_at ? dateTime(s.closed_at) : '—' }}</td>
                                <td class="num" :class="s.variance && Number(s.variance) < 0 ? 'text-ledger-red' : ''">{{ s.variance === null ? '—' : money(s.variance) }}</td>
                                <td class="text-end">
                                    <Badge v-if="s.status === 'open'" status="open" />
                                    <Badge v-else-if="s.ok" tone="good" :label="t('Closed correctly')" />
                                    <Badge v-else status="pending_review" :label="t('Under review')" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-10 text-center text-sm text-ink-500">{{ t('No shifts in this period.') }}</p>
            </section>

            <!-- Do stock counts match -->
            <section class="panel">
                <div class="panel-head">
                    <h2>{{ t('Do the stock counts match?') }}</h2>
                    <Link v-if="can('inventory.counts.count')" :href="route('inventory.counts.index')" class="link text-[13px]">{{ t('Counts') }}</Link>
                </div>
                <ul v-if="d.counts.length" class="divide-y divide-ink-100">
                    <li v-for="c in d.counts" :key="c.id" class="flex items-center gap-3 px-4 py-3 text-sm">
                        <ClipboardCheck class="h-5 w-5 shrink-0" :class="c.accuracy === 100 ? 'text-ledger-green' : c.accuracy === null ? 'text-ink-300' : 'text-haldi-600'" />
                        <div class="min-w-0 flex-1">
                            <Link :href="route('inventory.counts.show', c.id)" class="link">{{ c.number }}</Link>
                            <div class="text-[12.5px] text-ink-500">
                                {{ c.matched }}/{{ c.lines }} {{ t('lines match') }}<template v-if="c.variance_value !== null">, {{ t('difference') }} {{ money(c.variance_value) }}</template>
                            </div>
                        </div>
                        <span v-if="c.accuracy !== null" class="text-base font-semibold tabular-nums" :class="c.accuracy === 100 ? 'text-ledger-green' : 'text-ink-900'">{{ c.accuracy }}%</span>
                        <Badge :status="c.status" />
                    </li>
                </ul>
                <div v-else class="flex items-center gap-2 px-4 py-8 text-sm text-ink-500"><CircleAlert class="h-5 w-5 text-ink-300" />{{ t('No stock counts yet.') }}</div>
            </section>
        </div>
    </template>
</template>
