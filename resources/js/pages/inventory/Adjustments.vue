<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import { useFilters } from '@/composables/useFilters';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ adjustments: any; filters: Record<string, any> }>();
const filters = useFilters('inventory.adjustments.index', { status: props.filters.status ?? '', kind: props.filters.kind ?? '' });
const kinds: Record<string, string> = { opening: 'Opening stock', write_off: 'Write-off', adjustment: 'Correction', quarantine: 'Quarantine', release: 'Release from quarantine' };
const columns = [
    { key: 'number', label: 'Number' }, { key: 'business_date', label: 'Date', type: 'date' as const }, { key: 'kind_label', label: 'Type' }, { key: 'store.name', label: 'Store' },
    { key: 'reason_code.label', label: 'Reason' }, { key: 'requester.name', label: 'Requested by' }, { key: 'total_value', label: 'Value', type: 'money' as const }, { key: 'status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Stock adjustments')" :subtitle="t('Opening stock, damage and expiry write-offs, corrections and quarantine. Large values need a manager to approve.')">
        <template #actions>
            <template v-if="can('inventory.adjust')">
                <Button :href="route('inventory.adjustments.create', { kind: 'opening' })">{{ t('Opening stock') }}</Button>
                <Button :href="route('inventory.adjustments.create', { kind: 'adjustment' })">{{ t('Correction') }}</Button>
                <Button variant="primary" :href="route('inventory.adjustments.create', { kind: 'write_off' })">{{ t('Write-off') }}</Button>
            </template>
        </template>
    </PageHeader>
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <select v-model="filters.kind" class="input max-w-[14rem]"><option value="">{{ t('All types') }}</option><option v-for="(l, k) in kinds" :key="k" :value="k">{{ t(l) }}</option></select>
        <select v-model="filters.status" class="input max-w-[14rem]"><option value="">{{ t('Any status') }}</option><option value="pending_approval">{{ t('Waiting for approval') }}</option><option value="posted">{{ t('Posted') }}</option><option value="rejected">{{ t('Rejected') }}</option></select>
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="adjustments.data.map((r: any) => ({ ...r, kind_label: t(kinds[r.kind] ?? r.kind) }))" :row-href="(r) => route('inventory.adjustments.show', r.id)" empty="No adjustments yet." />
        <Pagination :meta="adjustments" />
    </section>
</template>
