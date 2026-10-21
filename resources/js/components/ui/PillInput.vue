<script setup lang="ts">
import { computed, ref } from 'vue';
import { X } from 'lucide-vue-next';
import { t } from '@/lib/i18n';

/**
 * A list of numbers shown as pills. Type an amount and press Enter (or comma /
 * space) to add it; click the cross on a pill to remove it. The list is kept
 * sorted from largest to smallest and duplicates are refused.
 */
const props = withDefaults(defineProps<{ modelValue: number[]; disabled?: boolean; placeholder?: string; min?: number }>(), { placeholder: '', min: 1 });
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>();

const draft = ref('');
const error = ref('');
const inputEl = ref<HTMLInputElement | null>(null);
const pills = computed(() => [...props.modelValue].sort((a, b) => b - a));
const show = (n: number) => n.toLocaleString('en-PK');

function commit(raw: string) {
    const parts = raw.split(/[\s,;]+/).map((s) => s.trim()).filter(Boolean);
    if (!parts.length) return;
    const next = new Set(props.modelValue);
    const rejected: string[] = [];
    error.value = '';
    for (const part of parts) {
        const n = Number(part);
        if (!Number.isFinite(n) || n <= 0) {
            error.value = t('Enter a number greater than zero.');
            rejected.push(part);
        } else if (next.has(n)) {
            error.value = t(':n is already in the list.', { n: show(n) });
        } else {
            next.add(n);
        }
    }
    emit('update:modelValue', [...next].sort((a, b) => b - a));
    draft.value = rejected.join(' ');
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
        e.preventDefault();
        commit(draft.value);
    }
}
function remove(n: number) {
    if (props.modelValue.length <= props.min) {
        error.value = t('Keep at least one denomination.');
        return;
    }
    error.value = '';
    emit('update:modelValue', props.modelValue.filter((x) => x !== n));
}
</script>

<template>
    <div>
        <div
            class="flex min-h-[2.5rem] flex-wrap items-center gap-1.5 rounded border border-ink-200 bg-white px-2 py-1.5 focus-within:border-haldi-500"
            :class="disabled ? 'bg-ink-50 opacity-70' : 'cursor-text'"
            @click="inputEl?.focus()"
        >
            <span v-for="n in pills" :key="n" class="inline-flex items-center gap-1 rounded-full bg-ink-800 py-0.5 ps-2.5 pe-1 text-[13px] font-medium tabular-nums text-white">
                {{ show(n) }}
                <button v-if="!disabled" type="button" class="rounded-full p-0.5 text-ink-300 hover:bg-white/15 hover:text-white" :aria-label="t('Remove :n', { n: show(n) })" @click.stop="remove(n)"><X class="h-3 w-3" /></button>
            </span>
            <input
                v-if="!disabled"
                ref="inputEl"
                v-model="draft"
                inputmode="decimal"
                class="min-w-[7rem] flex-1 border-0 bg-transparent p-0.5 text-sm outline-none focus:ring-0"
                :placeholder="placeholder || t('Type an amount, press Enter')"
                @keydown="onKey"
                @blur="commit(draft)"
            />
        </div>
        <p v-if="error" class="mt-1 text-[13px] text-ledger-red" role="alert">{{ error }}</p>
    </div>
</template>
