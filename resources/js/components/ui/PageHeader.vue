<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RecordPager from '@/components/ui/RecordPager.vue';

/**
 * `pager` (optional) is the previous / next pair a record page gets from App\Support\Pager.
 * It is drawn first in the actions area, so every record page shows it in the same place.
 */
defineProps<{
    title: string;
    subtitle?: string | null;
    pager?: { position: number; total: number; capped: boolean; source: 'list' | 'all'; prev: string; next: string } | null;
    confirmLeave?: boolean;
}>();
</script>

<template>
    <Head :title="title" />
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <slot name="crumbs" />
            <h1>{{ title }}</h1>
            <p v-if="subtitle || $slots.subtitle" class="mt-0.5 max-w-3xl text-sm text-ink-500"><slot name="subtitle">{{ subtitle }}</slot></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <RecordPager :pager="pager" :confirm-leave="confirmLeave" />
            <slot name="actions" />
        </div>
    </div>
</template>
