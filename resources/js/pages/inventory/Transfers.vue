<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import { useFilters } from '@/composables/useFilters';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ transfers: any; filters: Record<string, any> }>();
const filters = useFilters('inventory.transfers.index', { status: props.filters.status ?? '' });
const columns = [
    { key: 'number', label: 'Number' }, { key: 'created_at', label: 'Created', type: 'date' as const }, { key: 'from', label: 'From' }, { key: 'to', label: 'To' },
    { key: 'requester.name', label: 'Requested by' }, { key: 'status', label: 'Status', type: 'status' as const },
];
const rows = (data: any[]) => data.map((r) => ({ ...r, from: `${r.from_store?.name} · ${r.from_location?.name}`, to: `${r.to_store?.name} · ${r.to_location?.name}` }));
</script>

<template>
    <PageHeader :title="t('Transfers')" :subtitle="t('Move stock between the godown and the shop floor, or to another store.')">
        <template #actions><Button v-if="can('inventory.transfers.manage')" variant="primary" :href="route('inventory.transfers.create')">{{ t('New transfer') }}</Button></template>
    </PageHeader>
    <div class="panel mb-4 flex gap-3 p-3">
        <select v-model="filters.status" class="input max-w-[14rem]">
            <option value="">{{ t('Any status') }}</option>
            <option v-for="s in ['requested', 'approved', 'dispatched', 'partially_received', 'received', 'cancelled', 'rejected']" :key="s" :value="s">{{ t(s.replace('_', ' ')) }}</option>
        </select>
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="rows(transfers.data)" :row-href="(r) => route('inventory.transfers.show', r.id)" empty="No transfers yet." />
        <Pagination :meta="transfers" />
    </section>
</template>
