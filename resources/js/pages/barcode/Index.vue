<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import BarcodeTabs from '@/components/BarcodeTabs.vue';
import { Search, Barcode as BarcodeIcon, Printer, X} from 'lucide-vue-next';

interface VariantRow {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: string;
    image_url: string | null;
}

const props = defineProps<{
    variants: VariantRow[];
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let debounceTimer: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('barcode.index'),
            { search: search.value || undefined },
            { preserveState: true, replace: true }
        );
    }, 300);
});

// Local optimistic state so generating/clearing a barcode updates the row
// immediately without a full page reload.
const localVariants = ref<VariantRow[]>([...props.variants]);
watch(() => props.variants, (fresh) => { localVariants.value = [...fresh]; });

const processingId = ref<number | null>(null);

function jsonHeaders(): HeadersInit {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
    };
}

async function generateBarcode(variant: VariantRow) {
    processingId.value = variant.id;
    try {
        const response = await fetch(route('barcode.generate', { variant: variant.id }), {
            method: 'POST',
            headers: jsonHeaders(),
        });
        if (response.ok) {
            const data = await response.json();
            const row = localVariants.value.find((v) => v.id === variant.id);
            if (row) row.barcode = data.barcode;
        }
    } finally {
        processingId.value = null;
    }
}

async function clearBarcode(variant: VariantRow) {
    if (!confirm(`Remove the barcode from "${variant.name}"? You can generate a new one afterward.`)) return;

    processingId.value = variant.id;
    try {
        const response = await fetch(route('barcode.clear', { variant: variant.id }), {
            method: 'POST',
            headers: jsonHeaders(),
        });
        if (response.ok) {
            const row = localVariants.value.find((v) => v.id === variant.id);
            if (row) row.barcode = null;
        }
    } finally {
        processingId.value = null;
    }
}

function printLabel(variant: VariantRow) {
    window.open(route('barcode.print', { variant: variant.id }), '_blank');
}

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Barcodes" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Barcodes</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Generate and print barcodes for products that don't have one.
            </p>
            <BarcodeTabs />
            <div class="mt-4 relative max-w-sm">
                <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search products, SKU..."
                    class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Product</th>
                            <th class="px-4 py-3 font-medium text-slate-500">SKU</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Barcode</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-500">Price</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="variant in localVariants"
                            :key="variant.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                        <img
                                            v-if="variant.image_url"
                                            :src="variant.image_url"
                                            alt=""
                                            class="h-full w-full object-contain"
                                        />
                                    </div>
                                    <p class="font-medium text-ink-900">{{ variant.name }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ variant.sku ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span v-if="variant.barcode" class="font-mono text-ink-900">{{ variant.barcode }}</span>
                                <span v-else class="font-sans text-xs italic text-slate-400">No barcode</span>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-500">Rs {{ money(variant.price) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <button
                                        v-if="!variant.barcode"
                                        @click="generateBarcode(variant)"
                                        :disabled="processingId === variant.id"
                                        class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-1.5 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50"
                                    >
                                        <BarcodeIcon :size="14" /> Generate
                                    </button>
                                    <template v-else>
                                        <button
                                            @click="printLabel(variant)"
                                            class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 font-sans text-xs font-semibold text-ink-900 hover:bg-slate-50"
                                        >
                                            <Printer :size="14" /> Print
                                        </button>
                                        <button
                                            @click="clearBarcode(variant)"
                                            :disabled="processingId === variant.id"
                                            class="text-slate-400 hover:text-danger-500"
                                            title="Remove barcode"
                                        >
                                            <X :size="16" />
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="localVariants.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">No products found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>