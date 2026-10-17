<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PackagePlus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { dateTime, money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Items sold at the POS that are not in the product list yet. Sorted by how
 * often they sold in the last 30 days, so the most-needed ones are created
 * first: frequently (5+ sales), occasionally (2–4), rarely (0–1).
 */
const props = defineProps<{ items: any; filters: { status: string }; counts: Record<string, number>; canCreate: boolean }>();

const FREQ: Record<string, { label: string; tone: 'bad' | 'warn' | 'neutral' }> = {
    frequently: { label: 'Sold frequently', tone: 'bad' },
    occasionally: { label: 'Sold occasionally', tone: 'warn' },
    rarely: { label: 'Sold rarely', tone: 'neutral' },
};
function setStatus(status: string) {
    router.get(route('inventory.unlisted'), { status }, { preserveState: true, replace: true });
}
function dismiss(item: any) {
    if (confirm(t('Remove “:name” from this list? Past sales stay recorded.', { name: item.name }))) router.post(route('inventory.unlisted.dismiss', item.id), {}, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('Products to create')" :subtitle="t('Items sold at the POS that are not in the product list yet. Create the ones that sell often so they get a proper price, stock and barcode.')" />
    <Tabs
        :model-value="props.filters.status"
        :tabs="[{ key: 'pending', label: 'To be made', count: counts.pending }, { key: 'created', label: 'Created', count: counts.created }, { key: 'dismissed', label: 'Removed', count: counts.dismissed }]"
        @update:model-value="setStatus"
    />
    <section class="panel">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th>{{ t('Item (as typed at the POS)') }}</th><th>{{ t('How often') }}</th><th class="num">{{ t('Sales in 30 days') }}</th><th class="num">{{ t('Times sold') }}</th>
                        <th class="num">{{ t('Quantity sold') }}</th><th class="num">{{ t('Last price') }}</th><th class="num">{{ t('Total sales') }}</th><th>{{ t('Last sold') }}</th><th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items.data" :key="item.id">
                        <td><div class="font-medium">{{ item.name }}</div><div v-if="item.barcode" class="text-[12px] text-ink-500">{{ item.barcode }}</div></td>
                        <td><Badge :tone="FREQ[item.frequency].tone" :label="t(FREQ[item.frequency].label)" /></td>
                        <td class="num">{{ item.sales_30_days }}</td>
                        <td class="num">{{ item.times_sold }}</td>
                        <td class="num">{{ qty(item.quantity_sold) }}</td>
                        <td class="num">{{ money(item.last_price) }}</td>
                        <td class="num">{{ money(item.total_sales) }}</td>
                        <td class="whitespace-nowrap text-ink-600">{{ dateTime(item.last_sold_at) }}<div class="text-[12px]">{{ item.last_store?.name }}</div></td>
                        <td class="text-end">
                            <div v-if="item.status === 'pending' && canCreate" class="flex flex-col items-end gap-1">
                                <Button size="sm" variant="primary" :href="route('catalog.products.create', { pending: item.id })"><PackagePlus class="h-4 w-4" />{{ t('Create product') }}</Button>
                                <Button size="sm" variant="ghost" @click="dismiss(item)">{{ t('Remove') }}</Button>
                            </div>
                            <span v-else-if="item.product" class="text-sm text-ink-600">{{ item.product.name }}</span>
                        </td>
                    </tr>
                    <tr v-if="!items.data.length"><td colspan="9" class="py-10 text-center text-ink-500">{{ t('Nothing here. Items sold with “Item not in list” at the POS appear here.') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :meta="items" />
    </section>
</template>
