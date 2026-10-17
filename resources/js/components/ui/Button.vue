<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'ink'; size?: 'sm' | 'md' | 'lg'; href?: string; type?: 'button' | 'submit'; disabled?: boolean; loading?: boolean; method?: 'get' | 'post' | 'put' | 'delete' }>(), { variant: 'secondary', size: 'md', type: 'button' });

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 whitespace-nowrap',
    { sm: 'h-8 px-3 text-[13px]', md: 'h-10 px-4 text-sm', lg: 'h-12 px-5 text-base' }[props.size],
    {
        primary: 'bg-haldi-500 text-ink-950 hover:bg-haldi-400 active:bg-haldi-600',
        secondary: 'border border-ink-200 bg-white text-ink-800 hover:border-ink-300 hover:bg-ink-50',
        ghost: 'text-ink-700 hover:bg-ink-50',
        danger: 'bg-ledger-red text-white hover:bg-[#B13626]',
        ink: 'bg-ink-800 text-white hover:bg-ink-700',
    }[props.variant],
]);
</script>

<template>
    <Link v-if="href && !disabled" :href="href" :method="method" :as="method && method !== 'get' ? 'button' : 'a'" :class="classes"><slot /></Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading">
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        <slot />
    </button>
</template>
