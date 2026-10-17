<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import SupplierFormModal from '@/components/app/SupplierFormModal.vue';
import { useFilters } from '@/composables/useFilters';
import { money } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ suppliers: any; filters: Record<string, any> }>();
const filters = useFilters('purchasing.suppliers.index', { q: props.filters.q ?? '' });
const adding = ref(false);
</script>

<template>
    <PageHeader :title="t('Suppliers')" :subtitle="t('Who you buy from, and how much you owe each one.')">
        <template #actions><Button v-if="can('purchasing.suppliers.manage')" variant="primary" @click="adding = true">{{ t('Add supplier') }}</Button></template>
    </PageHeader>
    <div class="panel mb-4 p-3"><input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Name, phone or code')" /></div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Supplier') }}</th><th>{{ t('Phone') }}</th><th>{{ t('City') }}</th><th class="num">{{ t('Delivery (days)') }}</th><th class="num">{{ t('Days to pay') }}</th><th class="num">{{ t('We owe') }}</th></tr></thead>
            <tbody>
                <tr v-for="s in suppliers.data" :key="s.id">
                    <td><Link :href="route('purchasing.suppliers.show', s.id)" class="font-medium hover:underline">{{ s.name }}</Link><div class="text-[12px] text-ink-500">{{ s.code }}<span v-if="!s.is_active"> · {{ t('inactive') }}</span></div></td>
                    <td>{{ s.phone ?? '—' }}</td><td>{{ s.city ?? '—' }}</td><td class="num">{{ s.supplier_profile?.lead_time_days ?? '—' }}</td><td class="num">{{ s.supplier_profile?.payment_terms_days ?? '—' }}</td>
                    <td class="num font-semibold" :class="Number(s.payable) > 0 ? 'text-ledger-red' : 'text-ink-400'">{{ money(s.payable ?? 0) }}</td>
                </tr>
                <tr v-if="!suppliers.data.length"><td colspan="6" class="py-8 text-center text-ink-500">{{ t('No suppliers yet.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="suppliers" />
    </section>
    <SupplierFormModal :open="adding" @close="adding = false" />
</template>
