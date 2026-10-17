<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import ExpenseModal from '@/components/app/ExpenseModal.vue';
import { useFilters } from '@/composables/useFilters';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ journals: any; filters: Record<string, any> }>();
const filters = useFilters('accounting.journals.index', { q: props.filters.q ?? '', kind: props.filters.kind ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const expense = ref(false);
const kinds: Record<string, string> = { auto: 'Automatic', manual: 'Manual', expense: 'Expense', reversal: 'Reversal', opening: 'Opening' };
const columns = [{ key: 'number', label: 'Entry' }, { key: 'entry_date', label: 'Date', type: 'date' as const }, { key: 'description', label: 'Details' }, { key: 'kind_label', label: 'Type' }, { key: 'lines_sum_debit', label: 'Amount', type: 'money' as const }, { key: 'creator.name', label: 'By' }];
</script>

<template>
    <PageHeader :title="t('Journal entries')" :subtitle="t('Posted entries are never edited. To correct one, reverse it and post the right one.')">
        <template #actions>
            <Button v-if="can('accounting.journals.manage')" @click="expense = true">{{ t('Record expense') }}</Button>
            <Button v-if="can('accounting.journals.manage')" variant="primary" :href="route('accounting.journals.create')">{{ t('Manual journal') }}</Button>
        </template>
    </PageHeader>
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Number or details')" />
        <select v-model="filters.kind" class="input max-w-[12rem]"><option value="">{{ t('All types') }}</option><option v-for="(l, k) in kinds" :key="k" :value="k">{{ t(l) }}</option></select>
        <input v-model="filters.from" type="date" class="input max-w-[11rem]" /><input v-model="filters.to" type="date" class="input max-w-[11rem]" />
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="journals.data.map((j: any) => ({ ...j, kind_label: t(kinds[j.kind] ?? j.kind) }))" :row-href="(r) => route('accounting.journals.show', r.id)" />
        <Pagination :meta="journals" />
    </section>
    <ExpenseModal :open="expense" @close="expense = false" />
</template>
