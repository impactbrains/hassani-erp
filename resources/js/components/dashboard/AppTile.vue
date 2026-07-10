<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import * as icons from 'lucide-vue-next';
import type { DashboardApp } from '@/types/dashboard';

const props = defineProps<{ app: DashboardApp }>();

const IconComponent = computed(() => {
    return (icons as Record<string, any>)[props.app.icon] ?? icons.Square;
});

// operations/customer-facing apps get the brand-yellow top edge,
// back-office/productivity apps get the navy top edge.
const accentClass = computed(() => {
    return props.app.group === 'operations' || props.app.group === 'customer-facing'
        ? 'before:bg-brand-500'
        : 'before:bg-ink-700';
});
</script>

<template>
    <component
        :is="app.enabled ? Link : 'div'"
        :href="app.enabled ? route(app.routeName) : undefined"
        class="group relative flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition-all before:absolute before:inset-x-0 before:top-0 before:h-1 before:rounded-t-lg"
        :class="[
            accentClass,
            app.enabled
                ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md'
                : 'cursor-not-allowed opacity-60',
        ]"
    >
        <div class="flex items-center justify-between">
            <div
                class="flex h-11 w-11 items-center justify-center rounded-lg bg-ink-900/5 text-ink-900 transition-colors"
                :class="app.enabled && 'group-hover:bg-brand-500/15 group-hover:text-ink-800'"
            >
                <component :is="IconComponent" :size="22" :stroke-width="1.75" />
            </div>

            <span
                v-if="!app.enabled"
                class="rounded-full bg-slate-100 px-2 py-0.5 font-sans text-[11px] font-medium text-slate-500"
            >
                Coming soon
            </span>
        </div>

        <div>
            <h3 class="font-display text-[15px] font-semibold text-ink-900">
                {{ app.name }}
            </h3>
            <p class="mt-0.5 font-sans text-[13px] leading-snug text-slate-500">
                {{ app.description }}
            </p>
        </div>
    </component>
</template>