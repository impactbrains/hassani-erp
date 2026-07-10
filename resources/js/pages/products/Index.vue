<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import ProductsTabs from '@/components/ProductsTabs.vue';
import { Plus, Search, Pencil, Trash2 } from 'lucide-vue-next';

interface Variant {
    id: number;
    name: string;
    barcode: string | null;
    price: string;
    is_active: boolean;
}

interface ProductRow {
    id: number;
    name: string;
    brand: string | null;
    is_active: boolean;
    image_url: string | null;
    category: { id: number; name: string } | null;
    variants: Variant[];
}

interface Category {
    id: number;
    name: string;
}

const props = defineProps<{
    products: {
        data: ProductRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    categories: Category[];
    filters: { search?: string; category_id?: string };
}>();

const search = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.category_id ?? '');

let debounceTimer: ReturnType<typeof setTimeout>;

watch([search, categoryId], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('products.index'),
            { search: search.value || undefined, category_id: categoryId.value || undefined },
            { preserveState: true, replace: true }
        );
    }, 300);
});

function destroy(product: ProductRow) {
    if (!confirm(`Delete "${product.name}"? If it has sales history, it will be deactivated instead.`)) {
        return;
    }
    router.delete(route('products.destroy', { product: product.id }));
}
</script>

<template>
    <Head title="Products" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">Products</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">
                        Manage your catalog, variants and barcodes.
                    </p>
                </div>

                <Link
                    :href="route('products.create')"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> New product
                </Link>
            </div>

            <ProductsTabs />

            <div class="flex gap-3">
                <div class="relative flex-1">
                    <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search products..."
                        class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <select
                    v-model="categoryId"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option value="">All categories</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>
            </div>

            <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Product</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Category</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Variants</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="product in products.data"
                            :key="product.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                        <img
                                            v-if="product.image_url"
                                            :src="product.image_url"
                                            alt=""
                                            class="h-full w-full object-cover"
                                        />
                                    </div>
                                    <div>
                                        <p class="font-medium text-ink-900">{{ product.name }}</p>
                                        <p v-if="product.brand" class="text-xs text-slate-500">{{ product.brand }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ product.category?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ product.variants.length }} variant{{ product.variants.length === 1 ? '' : 's' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="product.is_active"
                                    class="inline-flex rounded-full bg-brand-500/15 px-2 py-0.5 text-xs font-medium text-ink-800"
                                >
                                    Active
                                </span>
                                <span v-else class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                                    Inactive
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <Link
                                        :href="route('products.edit', { product: product.id })"
                                        class="text-slate-400 hover:text-ink-800"
                                        title="Edit"
                                    >
                                        <Pencil :size="16" />
                                    </Link>
                                    <button
                                        @click="destroy(product)"
                                        class="text-slate-400 hover:text-danger-500"
                                        title="Delete"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="products.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                                No products found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="products.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="link in products.links"
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