<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Clock, Lock, Star } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ sections: Record<string, { label: string; label_ur: string }>; reports: any[]; recent: string[] }>();
const q = ref('');
const byKey = computed(() => Object.fromEntries(props.reports.map((r) => [r.key, r])));
const filtered = computed(() => {
    const term = q.value.trim().toLowerCase();
    return props.reports.filter((r) => !term || `${r.title} ${r.title_ur ?? ''} ${r.description}`.toLowerCase().includes(term));
});
const grouped = computed(() => Object.entries(props.sections).map(([key, s]) => ({ key, label: localName(s), reports: filtered.value.filter((r) => r.section === key) })).filter((g) => g.reports.length));
const favorites = computed(() => props.reports.filter((r) => r.favorite));
const recentReports = computed(() => props.recent.map((k) => byKey.value[k]).filter(Boolean));
function star(r: any) {
    router.post(route('reports.favorite', r.key), {}, { preserveScroll: true, only: ['reports'] });
}
</script>

<template>
    <PageHeader :title="t('Reports')" :subtitle="t('Every report can be filtered by date and store, compared with an earlier period, and downloaded as CSV for Excel.')">
        <template #actions><Button :href="route('reports.exports')">{{ t('My downloads') }}</Button></template>
    </PageHeader>
    <input v-model="q" type="search" class="input mb-5 max-w-md" :placeholder="t('Find a report')" />

    <div v-if="!q && (favorites.length || recentReports.length)" class="mb-6 grid gap-4 md:grid-cols-2">
        <section v-if="favorites.length" class="panel p-4">
            <h3 class="mb-2">{{ t('Starred') }}</h3>
            <div class="flex flex-wrap gap-2"><Link v-for="r in favorites" :key="r.key" :href="route('reports.show', r.key)" class="inline-flex items-center gap-1.5 rounded-full border border-haldi-300 bg-haldi-50 px-3 py-1 text-[13px] font-medium text-haldi-800 transition hover:border-haldi-500"><Star class="h-3.5 w-3.5 text-haldi-500" fill="currentColor" />{{ localName(r) }}</Link></div>
        </section>
        <section v-if="recentReports.length" class="panel p-4">
            <h3 class="mb-2">{{ t('Recently opened') }}</h3>
            <div class="flex flex-wrap gap-2"><Link v-for="r in recentReports" :key="r.key" :href="route('reports.show', r.key)" class="inline-flex items-center gap-1.5 rounded-full border border-ink-200 bg-white px-3 py-1 text-[13px] font-medium text-ink-800 transition hover:border-ink-400 hover:bg-ink-50"><Clock class="h-3.5 w-3.5 text-ink-400" />{{ localName(r) }}</Link></div>
        </section>
    </div>

    <div class="space-y-6">
        <section v-for="g in grouped" :key="g.key">
            <h2 class="mb-2 text-[15px] font-semibold text-ink-800">{{ g.label }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="r in g.reports" :key="r.key" class="panel group relative flex flex-col p-4 transition hover:border-haldi-400">
                    <Link :href="route('reports.show', r.key)" class="absolute inset-0" :aria-label="localName(r)" />
                    <div class="flex items-start gap-2">
                        <h3 class="flex-1 text-[15px] font-semibold text-ink-900">{{ localName(r) }}</h3>
                        <Lock v-if="r.sensitive" class="mt-0.5 h-4 w-4 text-ink-400" :title="t('Sensitive: restricted access')" />
                        <button type="button" class="relative z-10 text-ink-300 hover:text-haldi-600" :class="r.favorite ? 'text-haldi-500' : ''" :aria-label="t('Star')" @click="star(r)"><Star class="h-4 w-4" :fill="r.favorite ? 'currentColor' : 'none'" /></button>
                    </div>
                    <p class="mt-1 text-[13px] leading-snug text-ink-600">{{ t(r.description) }}</p>
                </div>
            </div>
        </section>
        <p v-if="!grouped.length" class="text-ink-500">{{ t('No report matches.') }}</p>
    </div>
</template>
