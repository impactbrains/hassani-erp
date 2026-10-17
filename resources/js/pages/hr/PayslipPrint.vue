<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { date, money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ payslip: any }>();
const page = usePage<any>();
const p = props.payslip;
const of = (type: string) => p.lines.filter((l: any) => l.type === type);
const d = computed(() => p.contact?.employee_detail ?? {});
function print() {
    window.print();
}
onMounted(() => document.title = `${t('Payslip')} ${p.contact?.name}`);
</script>

<template>
    <div class="mx-auto my-6 max-w-2xl bg-white p-8 text-[14px] text-ink-900 shadow print:my-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b-2 border-ink-800 pb-3">
            <div><div class="text-xl font-bold">{{ page.props.app?.company?.name ?? 'Hassani ERP' }}</div><div class="text-ink-600">{{ t('Payslip') }} · {{ date(p.run?.period_start) }} – {{ date(p.run?.period_end) }}</div></div>
            <button class="no-print rounded bg-ink-800 px-3 py-1.5 text-sm text-white" @click="print">{{ t('Print') }}</button>
        </div>
        <div class="mb-5 grid grid-cols-2 gap-2">
            <div><span class="text-ink-500">{{ t('Name') }}:</span> <b>{{ p.contact?.name }}</b></div>
            <div><span class="text-ink-500">{{ t('Employee no.') }}:</span> {{ d.employee_no }}</div>
            <div><span class="text-ink-500">{{ t('Job') }}:</span> {{ d.job_title ?? '—' }}</div>
            <div><span class="text-ink-500">{{ t('Department') }}:</span> {{ d.department?.name ?? '—' }}</div>
            <div><span class="text-ink-500">{{ t('Days paid') }}:</span> {{ p.days_payable }} · {{ t('present') }} {{ p.days_present }} · {{ t('absent') }} {{ p.days_absent }}</div>
            <div><span class="text-ink-500">{{ t('Leave') }}:</span> {{ p.days_paid_leave }} {{ t('paid') }} · {{ p.days_unpaid_leave }} {{ t('unpaid') }}</div>
        </div>
        <div class="grid grid-cols-2 gap-6">
            <table class="w-full"><thead><tr class="border-b border-ink-300"><th class="py-1 text-start">{{ t('Earnings') }}</th><th class="py-1 text-end">Rs</th></tr></thead>
                <tbody><tr v-for="l in of('earning')" :key="l.id"><td class="py-0.5">{{ l.description }}</td><td class="text-end tabular-nums">{{ money(l.amount, { symbol: false }) }}</td></tr></tbody>
                <tfoot><tr class="border-t border-ink-300 font-semibold"><td class="py-1">{{ t('Gross') }}</td><td class="text-end tabular-nums">{{ money(p.gross_pay, { symbol: false }) }}</td></tr></tfoot></table>
            <table class="w-full"><thead><tr class="border-b border-ink-300"><th class="py-1 text-start">{{ t('Deductions') }}</th><th class="py-1 text-end">Rs</th></tr></thead>
                <tbody><tr v-for="l in of('deduction')" :key="l.id"><td class="py-0.5">{{ l.description }}</td><td class="text-end tabular-nums">{{ money(l.amount, { symbol: false }) }}</td></tr></tbody>
                <tfoot><tr class="border-t border-ink-300 font-semibold"><td class="py-1">{{ t('Total') }}</td><td class="text-end tabular-nums">{{ money(p.total_deductions, { symbol: false }) }}</td></tr></tfoot></table>
        </div>
        <div class="mt-6 flex justify-between rounded bg-ink-50 px-4 py-3 text-lg font-bold"><span>{{ t('Net pay') }}</span><span class="tabular-nums">{{ money(p.net_pay) }}</span></div>
        <p v-if="p.paid_at" class="mt-2 text-ink-600">{{ t('Paid on') }} {{ date(p.paid_at) }} {{ p.payment_reference ? '· ' + p.payment_reference : '' }}</p>
        <div class="mt-12 grid grid-cols-2 gap-10 text-center text-ink-600"><div class="border-t border-ink-400 pt-1">{{ t('Employee signature') }}</div><div class="border-t border-ink-400 pt-1">{{ t('Authorised by') }}</div></div>
    </div>
</template>
