<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { t } from '@/lib/i18n';

/**
 * The previous / next arrows on a record's page. The server (App\Support\Pager) works out where this
 * record sits in the list it was opened from and sends the two addresses; this only draws them.
 * Ctrl/Cmd-click opens the neighbour in a new tab. `confirmLeave` asks first when the page holds
 * edits that are not saved yet (the product form).
 */
const props = defineProps<{
    pager?: { position: number; total: number; capped: boolean; source: 'list' | 'all'; prev: string; next: string } | null;
    confirmLeave?: boolean;
}>();

function go(event: MouseEvent, href: string) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return; // let the browser open a new tab or window
    event.preventDefault();
    if (props.confirmLeave && !window.confirm(t('You have changes that are not saved. Leave without saving?'))) return;
    router.visit(href);
}
const where = (which: 'prev' | 'next') => (props.pager?.source === 'list' ? t(which === 'prev' ? 'Previous in the list you came from' : 'Next in the list you came from') : t(which === 'prev' ? 'Previous record' : 'Next record'));
</script>

<template>
    <nav v-if="pager && pager.total > 1" class="no-print inline-flex items-center rounded border border-ink-200 bg-white text-ink-700" :aria-label="t('Previous and next record')">
        <a :href="pager.prev" :title="where('prev')" :aria-label="where('prev')" class="flex h-8 w-8 items-center justify-center rounded-s hover:bg-ink-50" @click="go($event, pager.prev)"><ChevronLeft class="h-4 w-4 rtl:rotate-180" /></a>
        <span class="min-w-[4.5rem] select-none border-x border-ink-200 px-2 text-center text-[13px] leading-8 tabular-nums text-ink-600">{{ t(':position of :total', { position: pager.position, total: pager.capped ? `${pager.total}+` : pager.total }) }}</span>
        <a :href="pager.next" :title="where('next')" :aria-label="where('next')" class="flex h-8 w-8 items-center justify-center rounded-e hover:bg-ink-50" @click="go($event, pager.next)"><ChevronRight class="h-4 w-4 rtl:rotate-180" /></a>
    </nav>
</template>
