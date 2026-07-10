<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, Search } from 'lucide-vue-next';

interface DocumentRow {
    id: number;
    type: 'pos_order' | 'pos_refund';
    number: string;
    status: 'draft' | 'confirmed' | 'paid' | 'cancelled' | 'refunded';
    total: string;
    line_count: number;
    created_at: string;
    formatted_created_at: string;
    contact: { id: number; name: string } | null;
    location: { id: number; name: string } | null;
    user: { id: number; name: string } | null;
}

interface LocationOption {
    id: number;
    name: string;
}

const props = defineProps<{
    documents: {
        data: DocumentRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    locations: LocationOption[];
    filters: {
        type?: string;
        status?: string;
        location_id?: string;
        search?: string;
        from?: string;
        to?: string;
    };
    statusCounts: Record<string, number>;
}>();

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
const locationId = ref(props.filters.location_id ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

const statusTabs = [
    { value: '', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'refunded', label: 'Refunded' },
];

function applyFilters(overrides: Record<string, string | undefined> = {}) {
    router.get(
        route('pos.history'),
        {
            status: overrides.status ?? props.filters.status ?? undefined,
            type: type.value || undefined,
            search: search.value || undefined,
            location_id: locationId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

let debounceTimer: ReturnType<typeof setTimeout>;
watch([search, type, locationId, from, to], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => applyFilters(), 300);
});

function selectStatus(status: string) {
    applyFilters({ status });
}

const statusBadgeClass: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-600',
    confirmed: 'bg-amber-100 text-amber-700',
    paid: 'bg-brand-500/15 text-ink-800',
    cancelled: 'bg-danger-500/15 text-danger-500',
    refunded: 'bg-blue-100 text-blue-700',
};

const typeBadgeClass: Record<string, string> = {
    pos_order: 'bg-slate-100 text-slate-600',
    pos_refund: 'bg-danger-500/15 text-danger-500',
};

const typeLabel: Record<string, string> = {
    pos_order: 'Order',
    pos_refund: 'Refund',
};

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Order History" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <Link :href="route('pos.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to POS
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">Order History</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">All POS orders, drafts, and receipts.</p>

            <!-- Status tabs -->
            <div class="mt-4 flex gap-1 border-b border-slate-200">
                <button
                    v-for="tab in statusTabs"
                    :key="tab.value"
                    @click="selectStatus(tab.value)"
                    class="border-b-2 px-4 py-2.5 font-sans text-sm font-medium transition-colors"
                    :class="
                        (filters.status ?? '') === tab.value
                            ? 'border-brand-500 text-ink-900'
                            : 'border-transparent text-slate-500 hover:text-ink-800'
                    "
                >
                    {{ tab.label }}
                    <span v-if="tab.value && statusCounts[tab.value]" class="ml-1 text-xs text-slate-400">
                        ({{ statusCounts[tab.value] }})
                    </span>
                </button>
            </div>

            <!-- Filters -->
            <div class="mt-4 flex flex-wrap gap-3">
                <div class="relative max-w-xs flex-1">
                    <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Order number or customer..."
                        class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <select
                    v-model="type"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option value="">Orders & refunds</option>
                    <option value="pos_order">Orders only</option>
                    <option value="pos_refund">Refunds only</option>
                </select>

                <select
                    v-model="locationId"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option value="">All locations</option>
                    <option v-for="location in locations" :key="location.id" :value="location.id">
                        {{ location.name }}
                    </option>
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

            <!-- Table -->
            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Order #</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Type</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Date</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Customer</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Location</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Cashier</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Items</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="doc in documents.data"
                            :key="doc.id"
                            class="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                            @click="router.visit(route('pos.history.show', { document: doc.id }))"
                        >
                            <td class="px-4 py-3 font-medium text-ink-900">{{ doc.number }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="typeBadgeClass[doc.type]"
                                >
                                    {{ typeLabel[doc.type] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ doc.formatted_created_at }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ doc.contact?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ doc.location?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ doc.user?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ doc.line_count }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="statusBadgeClass[doc.status]"
                                >
                                    {{ doc.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-ink-900">Rs {{ money(doc.total) }}</td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="9" class="px-4 py-8 text-center text-slate-400">No orders found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="documents.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="link in documents.links"
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