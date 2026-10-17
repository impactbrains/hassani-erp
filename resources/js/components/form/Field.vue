<script setup lang="ts">
import { t } from '@/lib/i18n';

/** Label, control (slot), hint and error message in one block. */
defineProps<{ label?: string; error?: string | null; hint?: string | null; required?: boolean; for?: string }>();
</script>

<template>
    <div>
        <label v-if="label" :for="$props.for" class="field-label">{{ t(label) }}<span v-if="required" class="text-ledger-red"> *</span></label>
        <slot />
        <p v-if="error" class="mt-1 text-[13px] text-ledger-red" role="alert">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-[12.5px] text-ink-500">{{ t(hint) }}</p>
    </div>
</template>
