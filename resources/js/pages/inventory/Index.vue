<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import InventoryTabs from '@/components/InventoryTabs.vue';
import { Search, AlertTriangle, Plus, PackagePlus } from 'lucide-vue-next';

interface LocationRow {
    id: number;
    name: string;
    type: 'store' | 'warehouse';
}

interface VariantStockRow {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    low_stock_threshold: string | null;
    stock_per_location: Record<number, number>;
    total_stock: number;
    is_low_stock: boolean;
}

const props = defineProps<{
    locations: LocationRow[];
    rows: VariantStockRow[];
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let debounceTimer: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('inventory.index'),
            { search: search.value || undefined },
            { preserveState: true, replace: true }
        );
    }, 300);
});

function formatQty(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(3).replace(/\.?0+$/, '');
}
</script>

<template>
    <Head title="Inventory" />

    <DashboardLayout>
        <div class="mx-auto max-w-6xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">Inventory</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">
                        Stock levels across all locations.
                    </p>
                </div>

                <Link
                    :href="route('inventory.record-movement')"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> Record movement
                </Link>
            </div>

            <InventoryTabs />

            <div class="relative max-w-sm">
                <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search variants..."
                    class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />
            </div>

            <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Variant</th>
                            <th class="px-4 py-3 font-medium text-slate-500">SKU</th>
                            <th
                                v-for="location in locations"
                                :key="location.id"
                                class="px-4 py-3 text-right font-medium text-slate-500"
                            >
                                {{ location.name }}
                            </th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <AlertTriangle
                                        v-if="row.is_low_stock"
                                        :size="14"
                                        class="shrink-0 text-danger-500"
                                    />
                                    <span class="font-medium text-ink-900">{{ row.name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ row.sku ?? '—' }}</td>
                            <td
                                v-for="location in locations"
                                :key="location.id"
                                class="px-4 py-3 text-right text-slate-700"
                            >
                                {{ formatQty(row.stock_per_location[location.id] ?? 0) }}{{ row.unit ? ` ${row.unit}` : '' }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold"
                                :class="row.is_low_stock ? 'text-danger-500' : 'text-ink-900'"
                            >
                                {{ formatQty(row.total_stock) }}{{ row.unit ? ` ${row.unit}` : '' }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="route('inventory.record-movement', { variant_id: row.id })"
                                    class="text-slate-400 hover:text-ink-800"
                                    title="Record movement for this variant"
                                >
                                    <PackagePlus :size="16" />
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td :colspan="locations.length + 4" class="px-4 py-8 text-center text-slate-400">
                                No tracked variants found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>