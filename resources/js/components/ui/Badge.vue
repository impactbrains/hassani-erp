<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';

/** Status pill. Tone comes from the status word so the same state always looks the same. */
const props = defineProps<{ status?: string | null; tone?: 'good' | 'warn' | 'bad' | 'info' | 'neutral'; label?: string }>();

const TONES: Record<string, 'good' | 'warn' | 'bad' | 'info' | 'neutral'> = {
    paid: 'good', verified: 'good', posted: 'good', completed: 'good', delivered: 'good', active: 'good', approved: 'good', closed: 'neutral', received: 'good', settled: 'good', final: 'good', none: 'good', accepted: 'good', validated: 'good', available: 'good', present: 'good',
    partial: 'warn', pending: 'warn', pending_review: 'warn', pending_approval: 'warn', submitted: 'warn', draft: 'neutral', open: 'info', in_progress: 'info', confirmed: 'info', picking: 'info', packed: 'info', dispatched: 'info', out_for_delivery: 'info', assigned: 'info', sent: 'info', probation: 'info', onboarding: 'info', requested: 'warn', held: 'info', experimental: 'warn', late: 'warn', half_day: 'warn', near_expiry: 'warn', slow: 'warn', partially_received: 'warn', disbursed: 'info',
    unpaid: 'bad', rejected: 'bad', cancelled: 'neutral', failed: 'bad', returned: 'warn', suspended: 'bad', deactivated: 'neutral', blocked: 'bad', on_hold: 'warn', overdue: 'bad', expired: 'bad', dead: 'bad', quarantined: 'warn', recalled: 'bad', absent: 'bad', resigned: 'neutral', terminated: 'bad', insufficient_history: 'neutral',
};
const tone = computed(() => props.tone ?? TONES[props.status ?? ''] ?? 'neutral');
const text = computed(() => props.label ?? t((props.status ?? '').toString()).replaceAll('_', ' '));
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-[12px] font-medium"
        :class="{ good: 'bg-ledger-greenSoft text-ledger-green', warn: 'bg-haldi-50 text-haldi-800', bad: 'bg-ledger-redSoft text-ledger-red', info: 'bg-ink-50 text-ink-700', neutral: 'bg-slate-100 text-slate-600' }[tone]"
    >
        <span class="h-1.5 w-1.5 rounded-full" :class="{ good: 'bg-ledger-green', warn: 'bg-haldi-500', bad: 'bg-ledger-red', info: 'bg-ink-400', neutral: 'bg-slate-400' }[tone]" />
        {{ text }}
    </span>
</template>
