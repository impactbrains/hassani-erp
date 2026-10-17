<script setup lang="ts">
import { dateTime, money } from '@/lib/format';
import { t } from '@/lib/i18n';

/** X report (mid-shift, nothing changes) or Z report (the frozen close-out). */
defineProps<{ report: any; kind: 'X' | 'Z'; variance?: string | null; varianceStatus?: string | null }>();
const EXCEPTIONS: Record<string, string> = {
    line_removals: 'Items removed before payment', price_overrides: 'Price changes', discounted_sales: 'Sales with a discount', khata_limit_exceptions: 'Khata over the limit',
    discarded_carts: 'Carts cleared without a sale', failed_pin_attempts: 'Wrong manager PINs', no_sale_openings: 'Drawer opened without a sale', payouts: 'Payouts',
};
</script>

<template>
    <div class="space-y-4 text-sm">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <div class="text-base font-semibold">{{ kind }} {{ t('report') }} · {{ report.shift.number }}</div>
                <div class="text-ink-500">{{ report.shift.register }} · {{ report.shift.cashier }} · {{ t('opened') }} {{ dateTime(report.shift.opened_at) }}</div>
            </div>
            <div class="text-[12.5px] text-ink-500">{{ t('Printed') }} {{ dateTime(report.shift.generated_at) }}</div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <section class="rounded border border-ink-100">
                <h3 class="border-b border-ink-100 px-3 py-2">{{ t('Sales') }}</h3>
                <dl class="divide-y divide-ink-50 px-3">
                    <div class="flex justify-between py-1.5"><dt>{{ t('Receipts') }}</dt><dd class="tabular-nums">{{ report.sales.count }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Gross sales') }}</dt><dd class="tabular-nums">{{ money(report.sales.gross) }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Discounts') }}</dt><dd class="tabular-nums">−{{ money(report.sales.discounts) }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Returns') }} ({{ report.sales.returns_count }})</dt><dd class="tabular-nums">−{{ money(report.sales.returns) }}</dd></div>
                    <div class="flex justify-between py-1.5 font-semibold"><dt>{{ t('Net') }}</dt><dd class="tabular-nums">{{ money(report.sales.net) }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Put on Khata') }}</dt><dd class="tabular-nums">{{ money(report.sales.khata_extended) }}</dd></div>
                </dl>
            </section>
            <section class="rounded border border-ink-100">
                <h3 class="border-b border-ink-100 px-3 py-2">{{ t('Cash in the drawer') }}</h3>
                <dl class="divide-y divide-ink-50 px-3">
                    <div class="flex justify-between py-1.5"><dt>{{ t('Opening cash') }}</dt><dd class="tabular-nums">{{ money(report.cash.opening_float) }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Cash received') }}</dt><dd class="tabular-nums">{{ money(report.cash.cash_in) }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Cash refunds') }}</dt><dd class="tabular-nums">−{{ money(report.cash.cash_refunds) }}</dd></div>
                    <div class="flex justify-between py-1.5"><dt>{{ t('Payouts and drops') }}</dt><dd class="tabular-nums">−{{ money(Number(report.cash.payouts) + Number(report.cash.drops)) }}</dd></div>
                    <div class="flex justify-between py-1.5 font-semibold"><dt>{{ t('Expected cash') }}</dt><dd class="tabular-nums">{{ money(report.cash.expected) }}</dd></div>
                    <div v-if="kind === 'Z' && variance !== undefined" class="flex justify-between py-1.5 font-semibold" :class="Number(variance) < 0 ? 'text-ledger-red' : Number(variance) > 0 ? 'text-haldi-700' : 'text-ledger-green'">
                        <dt>{{ t('Short / over') }}</dt><dd class="tabular-nums">{{ money(variance) }}</dd>
                    </div>
                </dl>
            </section>
        </div>

        <section class="rounded border border-ink-100">
            <h3 class="border-b border-ink-100 px-3 py-2">{{ t('Payment methods') }}</h3>
            <table class="table-base">
                <thead><tr><th>{{ t('Method') }}</th><th class="num">{{ t('Sales') }}</th><th class="num">{{ t('Wasooli') }}</th><th class="num">{{ t('Refunds') }}</th><th class="num">{{ t('Waiting verification') }}</th></tr></thead>
                <tbody>
                    <tr v-for="row in report.tenders" :key="row.method + (row.terminal ?? '')">
                        <td>{{ row.method }}<span v-if="row.terminal" class="text-ink-400"> · {{ row.terminal }}</span></td>
                        <td class="num">{{ money(row.sales) }}</td><td class="num">{{ money(row.collections) }}</td><td class="num">{{ money(row.refunds) }}</td><td class="num">{{ money(row.pending_verification) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="rounded border border-ink-100">
            <h3 class="border-b border-ink-100 px-3 py-2">{{ t('Exceptions to look at') }}</h3>
            <dl class="grid grid-cols-2 gap-x-6 px-3 py-2 md:grid-cols-4">
                <div v-for="(label, key) in EXCEPTIONS" :key="key" class="flex justify-between py-1">
                    <dt class="text-ink-600">{{ t(label) }}</dt><dd class="font-semibold tabular-nums" :class="report.exceptions[key] ? 'text-haldi-700' : 'text-ink-400'">{{ report.exceptions[key] ?? 0 }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
