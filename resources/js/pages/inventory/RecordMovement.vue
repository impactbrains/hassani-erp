<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import InventoryTabs from '@/components/InventoryTabs.vue';
import { Search, X } from 'lucide-vue-next';

interface LocationOption {
    id: number;
    name: string;
}

interface VariantOption {
    id: number;
    name: string;
    sku: string | null;
}

const props = defineProps<{
    locations: LocationOption[];
    preselectedVariant: VariantOption | null;
}>();

const form = useForm({
    variant_id: props.preselectedVariant?.id ?? (null as number | null),
    location_id: null as number | null,
    type: 'purchase' as 'purchase' | 'adjustment' | 'return',
    quantity_change: '',
    note: '',
});

// Searchable variant picker — queries the server as the user types rather
// than loading every variant up front, since the catalog can grow large.
const variantSearch = ref('');
const variantResults = ref<VariantOption[]>([]);
const selectedVariant = ref<VariantOption | null>(props.preselectedVariant);
const isSearching = ref(false);
const showDropdown = ref(false);

let debounceTimer: ReturnType<typeof setTimeout>;

watch(variantSearch, (query) => {
    clearTimeout(debounceTimer);

    if (!query.trim()) {
        variantResults.value = [];
        return;
    }

    debounceTimer = setTimeout(async () => {
        isSearching.value = true;
        try {
            const response = await fetch(`${route('inventory.variants.search')}?search=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            });
            variantResults.value = await response.json();
        } catch {
            variantResults.value = [];
        } finally {
            isSearching.value = false;
        }
    }, 250);
});

function selectVariant(variant: VariantOption) {
    selectedVariant.value = variant;
    form.variant_id = variant.id;
    showDropdown.value = false;
    variantSearch.value = '';
    variantResults.value = [];
}

function clearVariant() {
    selectedVariant.value = null;
    form.variant_id = null;
}

const typeHints: Record<string, string> = {
    purchase: 'Stock received — enter a positive quantity.',
    adjustment: 'Correcting a count discrepancy — positive to add, negative to remove.',
    return: 'Stock returned by a customer — enter a positive quantity.',
};

function submit() {
    form.post(route('inventory.store-movement'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            clearVariant();
        },
    });
}
</script>

<template>
    <Head title="Record Movement" />

    <DashboardLayout>
        <div class="mx-auto max-w-2xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Inventory</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Stock levels across all locations.
            </p>

            <InventoryTabs />

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="mb-4 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Record a stock movement
                </h2>

                <form @submit.prevent="submit" class="flex flex-col gap-4">
                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Variant</label>

                        <div v-if="selectedVariant" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                            <div>
                                <p class="font-sans text-sm font-medium text-ink-900">{{ selectedVariant.name }}</p>
                                <p v-if="selectedVariant.sku" class="font-sans text-xs text-slate-500">{{ selectedVariant.sku }}</p>
                            </div>
                            <button type="button" @click="clearVariant" class="text-slate-400 hover:text-danger-500">
                                <X :size="16" />
                            </button>
                        </div>

                        <div v-else class="relative">
                            <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="variantSearch"
                                type="text"
                                placeholder="Search by name or SKU..."
                                @focus="showDropdown = true"
                                class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />

                            <div
                                v-if="showDropdown && (variantResults.length > 0 || isSearching)"
                                class="absolute z-10 mt-1 w-full rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                            >
                                <p v-if="isSearching" class="px-3 py-2 font-sans text-xs text-slate-400">Searching...</p>
                                <button
                                    v-for="variant in variantResults"
                                    :key="variant.id"
                                    type="button"
                                    @click="selectVariant(variant)"
                                    class="block w-full px-3 py-2 text-left font-sans text-sm hover:bg-slate-50"
                                >
                                    <span class="font-medium text-ink-900">{{ variant.name }}</span>
                                    <span v-if="variant.sku" class="ml-1 text-xs text-slate-500">({{ variant.sku }})</span>
                                </button>
                            </div>
                        </div>

                        <p v-if="form.errors.variant_id" class="mt-1 font-sans text-xs text-danger-500">
                            {{ form.errors.variant_id }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Location</label>
                            <select
                                v-model="form.location_id"
                                required
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option :value="null" disabled>Select a location...</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.location_id" class="mt-1 font-sans text-xs text-danger-500">
                                {{ form.errors.location_id }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Type</label>
                            <select
                                v-model="form.type"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option value="purchase">Purchase (stock in)</option>
                                <option value="adjustment">Adjustment</option>
                                <option value="return">Return</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Quantity change</label>
                        <input
                            v-model="form.quantity_change"
                            type="number"
                            step="0.001"
                            required
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p class="mt-1 font-sans text-xs text-slate-400">{{ typeHints[form.type] }}</p>
                        <p v-if="form.errors.quantity_change" class="mt-1 font-sans text-xs text-danger-500">
                            {{ form.errors.quantity_change }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Note (optional)</label>
                        <input
                            v-model="form.note"
                            type="text"
                            placeholder="e.g. Supplier invoice #1234"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="form.processing || !form.variant_id"
                            class="rounded-lg bg-brand-500 px-5 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                        >
                            Record movement
                        </button>
                        <Link :href="route('inventory.index')" class="font-sans text-sm text-slate-500 hover:underline">
                            Back to overview
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </DashboardLayout>
</template>