<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

/** Laravel paginator links: previous / page numbers / next, with the row range. */
defineProps<{ meta: { links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number } }>();
const label = (l: string) => (l.includes('Previous') ? '‹' : l.includes('Next') ? '›' : l);
</script>

<template>
    <div v-if="meta.total > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-4 py-2.5 text-sm text-ink-500">
        <span>{{ t(':from–:to of :total', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</span>
        <nav v-if="meta.links.length > 3" class="flex flex-wrap gap-1" dir="ltr">
            <template v-for="(l, i) in meta.links" :key="i">
                <Link v-if="l.url" :href="l.url" preserve-scroll preserve-state class="min-w-[2rem] rounded px-2 py-1 text-center" :class="l.active ? 'bg-ink-800 text-white' : 'hover:bg-ink-50'">{{ label(l.label) }}</Link>
                <span v-else class="min-w-[2rem] px-2 py-1 text-center text-ink-300">{{ label(l.label) }}</span>
            </template>
        </nav>
    </div>
</template>
