<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Stat from '@/components/ui/Stat.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { get } from '@/lib/http';
import { date, dateTime, money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Look up one product: how much is sellable, where it sits, which batches, and what moved recently. */
defineProps<{ locations: any[] }>();
const data = ref<any>(null);
const loading = ref(false);
const variantId = ref<number | null>(null);

async function pick(p: any) {
    loading.value = true;
    variantId.value = p.variant_id;
    try {
        data.value = await get(route('inventory.stock.variant', p.variant_id));
    } finally {
        loading.value = false;
    }
}
const kinds: Record<string, string> = { sale: 'Sold', sale_return: 'Customer return', purchase_receipt: 'Received from supplier', adjustment: 'Adjustment', write_off: 'Write-off', opening: 'Opening stock', transfer_out: 'Sent', transfer_in: 'Received', count_variance: 'Count difference', repack_in: 'Repacked in', repack_out: 'Repacked out', supplier_return: 'Returned to supplier', quarantine: 'Quarantine', release: 'Released' };
</script>

<template>
    <PageHeader :title="t('Stock on hand')" :subtitle="t('Search a product to see how much you have, where it is, and what moved.')">
        <template #actions>
            <Button :href="route('reports.show', 'inventory.on_hand')">{{ t('Full stock list') }}</Button>
            <Button variant="primary" :href="route('inventory.adjustments.create', { kind: 'opening' })">{{ t('Add opening stock') }}</Button>
        </template>
    </PageHeader>
    <div class="panel mb-5 p-3"><ProductPicker purpose="any" :placeholder="t('Scan or type a product name, SKU or barcode')" @pick="pick" /></div>

    <p v-if="loading" class="text-sm text-ink-500">{{ t('Loading…') }}</p>
    <template v-else-if="data">
        <h2 class="mb-3 text-lg font-semibold">{{ data.name }} <span class="text-sm font-normal text-ink-500">{{ data.sku }}</span></h2>
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-6">
            <Stat :label="t('On hand')" :value="qty(data.breakdown.on_hand)" />
            <Stat :label="t('Can be sold')" :value="qty(data.breakdown.sellable)" tone="good" />
            <Stat :label="t('Quarantined')" :value="qty(data.breakdown.quarantined)" :tone="Number(data.breakdown.quarantined) ? 'warn' : null" />
            <Stat :label="t('Damaged')" :value="qty(data.breakdown.damaged)" :tone="Number(data.breakdown.damaged) ? 'bad' : null" />
            <Stat :label="t('Expired')" :value="qty(data.breakdown.expired)" :tone="Number(data.breakdown.expired) ? 'bad' : null" />
            <Stat :label="t('In transit')" :value="qty(data.breakdown.in_transit)" />
        </div>
        <div v-if="data.cost" class="mb-5 flex flex-wrap gap-6 text-sm text-ink-700">
            <span>{{ t('Average cost') }}: <b class="tabular-nums">{{ money(data.cost.average_cost) }}</b></span>
            <span>{{ t('Last purchase cost') }}: <b class="tabular-nums">{{ money(data.cost.last_purchase_cost) }}</b></span>
            <span>{{ t('Stock value') }}: <b class="tabular-nums">{{ money(Number(data.cost.average_cost) * Number(data.breakdown.on_hand)) }}</b></span>
        </div>
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Where it is') }}</h2></div>
                <table class="table-base">
                    <thead><tr><th>{{ t('Location') }}</th><th>{{ t('Batch') }}</th><th>{{ t('Expiry') }}</th><th class="num">{{ t('Quantity') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in data.levels" :key="i">
                            <td>{{ l.location }}</td>
                            <td>{{ l.batch_no ?? '—' }} <Badge v-if="l.batch_status && l.batch_status !== 'available'" :status="l.batch_status" /></td>
                            <td>{{ l.expiry_date ? date(l.expiry_date) : '—' }}</td>
                            <td class="num" :class="Number(l.on_hand) < 0 ? 'text-ledger-red' : ''">{{ qty(l.on_hand) }}</td>
                        </tr>
                        <tr v-if="!data.levels.length"><td colspan="4" class="py-6 text-center text-ink-500">{{ t('No stock anywhere in this store.') }}</td></tr>
                    </tbody>
                </table>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Recent movements') }}</h2></div>
                <table class="table-base">
                    <thead><tr><th>{{ t('When') }}</th><th>{{ t('What') }}</th><th>{{ t('Location') }}</th><th class="num">{{ t('Quantity') }}</th><th>{{ t('By') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(m, i) in data.movements" :key="i">
                            <td class="whitespace-nowrap text-[13px]">{{ dateTime(m.at) }}</td>
                            <td>{{ t(kinds[m.type] ?? m.type.replaceAll('_', ' ')) }}</td>
                            <td>{{ m.location }}</td>
                            <td class="num" :class="Number(m.quantity) < 0 ? 'text-ledger-red' : 'text-ledger-green'">{{ Number(m.quantity) > 0 ? '+' : '' }}{{ qty(m.quantity) }}</td>
                            <td class="text-[13px]">{{ m.by }}</td>
                        </tr>
                        <tr v-if="!data.movements.length"><td colspan="5" class="py-6 text-center text-ink-500">{{ t('Nothing has moved yet.') }}</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
    </template>
    <section v-else class="panel p-8 text-center text-ink-500">
        <p>{{ t('Locations in this store') }}:</p>
        <p class="mt-2 flex flex-wrap justify-center gap-2"><Badge v-for="l in locations" :key="l.id" :tone="l.is_sellable ? 'good' : 'neutral'" :label="l.name" /></p>
    </section>
</template>
