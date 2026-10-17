<script setup lang="ts">
import { computed } from 'vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Cash count by note and coin; the total is what the server records. */
const props = defineProps<{ denominations: number[]; modelValue: Record<string, number> }>();
const emit = defineEmits<{ 'update:modelValue': [Record<string, number>] }>();

const total = computed(() => props.denominations.reduce((sum, d) => sum + d * (props.modelValue[String(d)] || 0), 0));
function set(d: number, value: string) {
    emit('update:modelValue', { ...props.modelValue, [String(d)]: Math.max(0, Math.floor(Number(value) || 0)) });
}
</script>

<template>
    <div>
        <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 sm:grid-cols-2">
            <label v-for="d in denominations" :key="d" class="flex items-center gap-3 text-sm">
                <span class="w-16 text-end font-semibold tabular-nums text-ink-800">{{ d >= 10 ? d.toLocaleString('en-PK') : d }}</span>
                <span class="text-ink-400">×</span>
                <input type="number" min="0" inputmode="numeric" class="input w-20 py-1 text-end tabular-nums" :value="modelValue[String(d)] || ''" @input="set(d, ($event.target as HTMLInputElement).value)" />
                <span class="ms-auto tabular-nums text-ink-500">{{ (modelValue[String(d)] || 0) > 0 ? money(d * (modelValue[String(d)] || 0)) : '' }}</span>
            </label>
        </div>
        <div class="mt-3 flex items-baseline justify-between border-t border-ink-100 pt-3">
            <span class="text-sm text-ink-600">{{ t('Total counted') }}</span>
            <span class="text-2xl font-semibold tabular-nums text-ink-900">{{ money(total) }}</span>
        </div>
    </div>
</template>
