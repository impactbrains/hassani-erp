<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ tabs: { key: string; label: string; count?: number | null }[]; modelValue: string }>();
const emit = defineEmits<{ 'update:modelValue': [string] }>();
</script>

<template>
    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-ink-100" role="tablist">
        <button v-for="tab in tabs" :key="tab.key" role="tab" :aria-selected="modelValue === tab.key" class="relative whitespace-nowrap px-3 py-2 text-sm font-medium" :class="modelValue === tab.key ? 'text-ink-900' : 'text-ink-500 hover:text-ink-800'" @click="emit('update:modelValue', tab.key)">
            {{ t(tab.label) }}<span v-if="tab.count" class="ms-1.5 rounded-full bg-ink-50 px-1.5 text-[12px] tabular-nums text-ink-600">{{ tab.count }}</span>
            <span v-if="modelValue === tab.key" class="absolute inset-x-2 -bottom-px h-0.5 rounded bg-haldi-500" />
        </button>
    </div>
</template>
