<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Large exports are prepared in the background; this page refreshes itself until they are ready. */
const props = defineProps<{ exports: any }>();
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (props.exports.data.some((e: any) => ['queued', 'running'].includes(e.status))) router.reload({ only: ['exports'] });
    }, 4000);
});
onBeforeUnmount(() => clearInterval(timer));
const size = (b: number | null) => (b ? (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.ceil(b / 1024)} KB`) : '—');
</script>

<template>
    <PageHeader :title="t('My downloads')" :subtitle="t('CSV files open in Excel. Files are kept for 7 days.')">
        <template #actions><Button :href="route('reports.index')">{{ t('Reports') }}</Button></template>
    </PageHeader>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Report') }}</th><th>{{ t('Requested') }}</th><th>{{ t('Status') }}</th><th class="num">{{ t('Rows') }}</th><th class="num">{{ t('Size') }}</th><th>{{ t('Available until') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="e in exports.data" :key="e.id">
                    <td class="font-medium">{{ t(e.title) }}</td><td>{{ dateTime(e.created_at) }}</td>
                    <td><Badge :status="e.status" /><div v-if="e.error" class="text-[12px] text-ledger-red">{{ e.error }}</div></td>
                    <td class="num">{{ e.row_count?.toLocaleString('en-PK') ?? '—' }}</td><td class="num">{{ size(e.file_size) }}</td>
                    <td>{{ e.expires_at ? dateTime(e.expires_at) : '—' }}</td>
                    <td><a v-if="e.status === 'completed'" :href="route('reports.exports.download', e.id)" class="link">{{ t('Download') }}</a></td>
                </tr>
                <tr v-if="!exports.data.length"><td colspan="7" class="py-8 text-center text-ink-500">{{ t('Nothing downloaded yet.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="exports" />
    </section>
</template>
