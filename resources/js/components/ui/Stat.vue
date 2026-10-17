<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ label: string; value: string | number | null; note?: string | null; tone?: 'good' | 'bad' | 'warn' | null }>();
</script>

<template>
    <div class="px-4 py-3">
        <div class="text-[13px] text-ink-500">{{ t(label) }}</div>
        <div class="mt-0.5 text-xl font-semibold tabular-nums" :class="tone ? { good: 'text-ledger-green', bad: 'text-ledger-red', warn: 'text-haldi-700' }[tone] : 'text-ink-900'">{{ value ?? '—' }}</div>
        <div v-if="note" class="text-[12.5px] text-ink-500">{{ note }}</div>
    </div>
</template>
