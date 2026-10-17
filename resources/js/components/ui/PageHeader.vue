<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ title: string; subtitle?: string | null }>();
</script>

<template>
    <Head :title="title" />
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <slot name="crumbs" />
            <h1>{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 max-w-3xl text-sm text-ink-500">{{ subtitle }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
