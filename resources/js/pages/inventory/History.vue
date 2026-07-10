<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import InventoryTabs from '@/components/InventoryTabs.vue';

interface MovementRow {
    id: number;
    quantity_change: string;
    type: 'sale' | 'purchase' | 'adjustment' | 'return' | 'transfer';
    note: string | null;
    created_at: string;
    variant: {
        id: number;
        name: string;
        sku: string | null;
        product: { id: number; name: string } | null;
    } | null;
    location: { id: number; name: string } | null;
    user: { id: number; name: string } | null;
}

interface LocationOption {
    id: number;
    name: string;
}

const props = defineProps<{
    movements: {
        data: MovementRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    locations: LocationOption[];
    filters: {
        location_id?: string;
        type?: string;
        from?: string;
        to?: string;
    };
}>();

const locationId = ref(props.filters.location_id ?? '');
const type = ref(props.filters.type ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

function applyFilters() {
    router.get(
        route('inventory.history'),
        {
            location_id: locationId.value || undefined,
            type: type.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

watch([locationId, type, from, to], applyFilters);

const typeBadgeClass: Record<string, string> = {
    sale: 'bg-danger-500/15 text-danger-500',
    purchase: 'bg-brand-500/15 text-ink-800',
    adjustment: 'bg-slate-100 text-slate-600',
    return: 'bg-amber-100 text-amber-700',
    transfer: 'bg-blue-100 text-blue-700',
};

function formatQty(value: string): string {
    const num = Number(value);
    const sign = num > 0 ? '+' : '';
    return `${sign}${Number.isInteger(num) ? num : num.toFixed(3).replace(/\.?0+$/, '')}`;
}
</script>

<template>
    <Head title="Inventory History" />

    <DashboardLayout>
        <div class="mx-auto max-w-6xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Inventory</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Stock levels across all locations.
            </p>

            <InventoryTabs />

            <div class="flex flex-wrap gap-3">
                <select
                    v-model="locationId"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option value="">All locations</option>
                    <option v-for="location in locations" :key="location.id" :value="location.id">
                        {{ location.name }}
                    </option>
                </select>

                <select
                    v-model="type"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option value="">All types</option>
                    <option value="sale">Sale</option>
                    <option value="purchase">Purchase</option>
                    <option value="adjustment">Adjustment</option>
                    <option value="return">Return</option>
                    <option value="transfer">Transfer</option>
                </select>

                <input
                    v-model="from"
                    type="date"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />
                <span class="self-center font-sans text-sm text-slate-400">to</span>
                <input
                    v-model="to"
                    type="date"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Date</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Variant</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Location</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Type</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Qty</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Recorded by</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="movement in movements.data"
                            :key="movement.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                            <td class="px-4 py-3 text-slate-500">{{ movement.created_at }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-ink-900">{{ movement.variant?.product?.name }}</p>
                                <p class="text-xs text-slate-500">{{ movement.variant?.name }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ movement.location?.name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="typeBadgeClass[movement.type]"
                                >
                                    {{ movement.type }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium"
                                :class="Number(movement.quantity_change) >= 0 ? 'text-ink-900' : 'text-danger-500'"
                            >
                                {{ formatQty(movement.quantity_change) }}
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ movement.user?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ movement.note ?? '—' }}</td>
                        </tr>
                        <tr v-if="movements.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                No movements found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="movements.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="link in movements.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    class="rounded-lg px-3 py-1.5 font-sans text-sm"
                    :class="link.active ? 'bg-ink-900 text-white' : 'text-slate-500 hover:bg-slate-100'"
                    preserve-state
                >
                    <span v-html="link.label" />
                </Link>
            </div>
        </div>
    </DashboardLayout>
</template>