<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import ProductsTabs from '@/components/ProductsTabs.vue';
import SelectWithCreate from '@/components/SelectWithCreate.vue';
import MediaPicker from '@/components/MediaPicker.vue';
import { useBarcodeScanner } from '@/composables/useBarcodeScanner';
import { Plus, Trash2, ScanLine } from 'lucide-vue-next';

interface VariantForm {
    id: number | null;
    name: string;
    sku: string;
    barcode: string;
    price: string;
    cost: string;
    tax_id: number | null;
    unit_id: number | null;
    pack_size: string;
    low_stock_threshold: string;
    is_loose: boolean;
    track_inventory: boolean;
    is_active: boolean;
    can_edit_price: boolean;
    media_id: number | null;
    image_url: string | null; // display-only, from server; not submitted
    stock_by_location?: Record<number, number>; // display-only, from server; current stock per location
    stock_adjustments: Record<number, string>; // editable target quantity per location, submitted to backend
}

interface LocationOption {
    id: number;
    name: string;
}

const props = defineProps<{
    product: {
        id: number;
        name: string;
        category_id: number | null;
        brand: string | null;
        description: string | null;
        tax_id: number | null;
        is_active: boolean;
        can_edit_price: boolean;
        media_id: number | null;
        image_url: string | null;
        variants: VariantForm[];
    } | null;
    categories: { id: number; name: string }[];
    units: { id: number; name: string; short_code: string }[];
    taxes: { id: number; name: string; rate: string; type: string; is_default?: boolean }[];
    locations: LocationOption[];
}>();

const isEditing = !!props.product;

// Local reactive copies so a newly created category/unit can be appended
// immediately without a full page reload from the server.
const categoryOptions = ref(props.categories.map((c) => ({ id: c.id, label: c.name })));
const unitOptions = ref(props.units.map((u) => ({ id: u.id, label: u.short_code })));

function blankVariant(): VariantForm {
    return {
        id: null,
        name: '',
        sku: '',
        barcode: '',
        price: '',
        cost: '',
        tax_id: props.product?.tax_id ?? null,
        unit_id: null,
        pack_size: '',
        low_stock_threshold: '',
        track_inventory: true,
        is_loose: false,
        is_active: true,
        can_edit_price: false,
        media_id: null,
        image_url: null,
        stock_by_location: {},
        stock_adjustments: Object.fromEntries(props.locations.map((loc) => [loc.id, '0'])),
    };
}

interface ProductFormData {
    [key: string]: any;
    name: string;
    category_id: number | null;
    brand: string;
    description: string;
    tax_id: number | null;
    is_active: boolean;
    can_edit_price: boolean;
    media_id: number | null;
    variants: VariantForm[];
}

const form = useForm<ProductFormData>({
    name: props.product?.name ?? '',
    category_id: props.product?.category_id ?? null,
    brand: props.product?.brand ?? '',
    description: props.product?.description ?? '',
    tax_id: props.product?.tax_id ?? (props.taxes.find((t) => t.is_default)?.id ?? null),
    is_active: props.product?.is_active ?? true,
    can_edit_price: props.product?.can_edit_price ?? false,
    media_id: props.product?.media_id ?? null,
    variants: props.product?.variants?.length
        ? props.product.variants.map((v) => ({
              ...v,
              // Seed each location's editable field with its current
              // stock, as a string for v-model binding. Left untouched,
              // this produces a zero-delta adjustment on submit.
              stock_adjustments: Object.fromEntries(
                  props.locations.map((loc) => [loc.id, String(v.stock_by_location?.[loc.id] ?? 0)])
              ),
          }))
        : [blankVariant()],
});

// Product-level image preview (separate from per-variant previews below).
const productImagePreview = ref<string | null>(props.product?.image_url ?? null);

// Cascade: checking the product-level "can edit price" box pushes `true`
// onto every current variant, as a one-time action. It does NOT lock
// variants — each can still be individually unchecked afterward, and
// this watcher won't re-fire to undo that (it only reacts to the
// product-level box being turned ON, not to variant-level changes).
watch(
    () => form.can_edit_price,
    (enabled) => {
        if (enabled) {
            form.variants.forEach((variant) => {
                variant.can_edit_price = true;
            });
        }
    }
);

function addVariant() {
    form.variants.push(blankVariant());
}

function removeVariant(index: number) {
    if (form.variants.length === 1) return; // always keep at least one
    form.variants.splice(index, 1);
}

// Scanner support — whichever variant row's barcode input is currently
// focused receives the scan. Tracks the index via focus events on each input.
const activeVariantIndex = ref<number | null>(null);
const scanFeedback = ref<string | null>(null);

useBarcodeScanner((code) => {
    if (activeVariantIndex.value === null) return;
    form.variants[activeVariantIndex.value].barcode = code;
    scanFeedback.value = `Scanned into variant ${activeVariantIndex.value + 1}`;
    setTimeout(() => (scanFeedback.value = null), 2000);
});

function submit() {
    if (isEditing && props.product) {
        form.put(route('products.update', { product: props.product.id }));
    } else {
        form.post(route('products.store'));
    }
}
</script>

<template>
    <Head :title="isEditing ? `Edit ${product?.name}` : 'New product'" />

    <DashboardLayout>
        <div class="mx-auto max-w-4xl">
            <Link :href="route('products.index')" class="font-sans text-sm text-slate-500 hover:underline">
                &larr; Back to Products
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">
                {{ isEditing ? `Edit ${product?.name}` : 'New product' }}
            </h1>

            <ProductsTabs />

            <div
                v-if="scanFeedback"
                class="mt-4 rounded-lg bg-brand-500/15 px-4 py-2 font-sans text-sm text-ink-800"
            >
                <ScanLine :size="14" class="mr-1 inline" /> {{ scanFeedback }}
            </div>

            <form @submit.prevent="submit" class="mt-6 flex flex-col gap-6">
                <!-- Product fields -->
                <section class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-4 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Product details
                    </h2>

                    <div class="mb-4">
                        <MediaPicker
                            v-model="form.media_id"
                            v-model:preview-url="productImagePreview"
                        />
                        <p v-if="form.errors.media_id" class="mt-1 font-sans text-xs text-danger-500">
                            {{ form.errors.media_id }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Category</label>
                            <SelectWithCreate
                                v-model="form.category_id"
                                :options="categoryOptions"
                                placeholder="None"
                                create-label="Create new category"
                                :create-fields="[{ key: 'name', label: 'Category name' }]"
                                :create-endpoint="route('categories.store')"
                                @created="(opt) => categoryOptions.push(opt)"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Brand</label>
                            <input
                                v-model="form.brand"
                                type="text"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Default tax</label>
                            <select
                                v-model="form.tax_id"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option :value="null">None</option>
                                <option v-for="tax in taxes" :key="tax.id" :value="tax.id">
                                    {{ tax.name }} ({{ tax.rate }}{{ tax.type === 'percentage' ? '%' : '' }})
                                </option>
                            </select>
                        </div>

                        <div class="flex items-end gap-4">
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    v-model="form.is_active"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                />
                                <span class="font-sans text-sm text-ink-900">Active</span>
                            </label>

                            <label class="flex items-center gap-2" title="Applies to all variants below when checked">
                                <input
                                    type="checkbox"
                                    v-model="form.can_edit_price"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                />
                                <span class="font-sans text-sm text-ink-900">Allow price edit in POS</span>
                            </label>
                        </div>

                        <div class="col-span-2">
                            <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Description</label>
                            <textarea
                                v-model="form.description"
                                rows="2"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                        </div>
                    </div>
                </section>

                <!-- Variants -->
                <section class="rounded-lg border border-slate-200 bg-white p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Variants
                        </h2>
                        <p class="font-sans text-xs text-slate-400">
                            <ScanLine :size="12" class="mr-1 inline" />
                            Click a barcode field, then scan
                        </p>
                    </div>

                    <div class="flex flex-col gap-4">
                        <div
                            v-for="(variant, index) in form.variants"
                            :key="index"
                            class="rounded-lg border border-slate-100 p-4"
                        >
                            <div class="grid grid-cols-6 gap-3">
                                <div class="col-span-6">
                                    <MediaPicker
                                        v-model="variant.media_id"
                                        v-model:preview-url="variant.image_url"
                                    />
                                </div>

                                <div class="col-span-2">
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Variant name</label>
                                    <input
                                        v-model="variant.name"
                                        type="text"
                                        placeholder="e.g. 500ml"
                                        required
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                    />
                                </div>

                                <div>
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">SKU</label>
                                    <input
                                        v-model="variant.sku"
                                        type="text"
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                    />
                                </div>

                                <div class="col-span-2">
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Barcode</label>
                                    <input
                                        v-model="variant.barcode"
                                        type="text"
                                        placeholder="Scan or type"
                                        @focus="activeVariantIndex = index"
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        :class="{ 'ring-2 ring-brand-500': activeVariantIndex === index }"
                                    />
                                </div>

                                <div class="flex items-end justify-end">
                                    <button
                                        type="button"
                                        @click="removeVariant(index)"
                                        :disabled="form.variants.length === 1"
                                        class="text-slate-400 hover:text-danger-500 disabled:opacity-30"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>

                                <div>
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Price</label>
                                    <input
                                        v-model="variant.price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        required
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                    />
                                </div>

                                <div>
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Cost</label>
                                    <input
                                        v-model="variant.cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                    />
                                </div>

                                <div>
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Unit</label>
                                    <SelectWithCreate
                                        v-model="variant.unit_id"
                                        :options="unitOptions"
                                        placeholder="—"
                                        create-label="Create new unit"
                                        label-field="short_code"
                                        :create-fields="[
                                            { key: 'name', label: 'Unit name', placeholder: 'e.g. Kilogram' },
                                            { key: 'short_code', label: 'Short code', placeholder: 'e.g. kg' },
                                        ]"
                                        :create-endpoint="route('units.store')"
                                        @created="(opt) => unitOptions.push(opt)"
                                    />
                                </div>

                                <div>
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Pack size</label>
                                    <input
                                        v-model="variant.pack_size"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                    />
                                </div>

                                <div>
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">
                                        Low stock threshold
                                    </label>
                                    <input
                                        v-model="variant.low_stock_threshold"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        placeholder="None"
                                        class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                    />
                                </div>

                                <div v-if="variant.track_inventory && locations.length > 0" class="col-span-6">
                                    <label class="mb-1 block font-sans text-xs font-medium text-slate-500">
                                        Stock on hand
                                    </label>
                                    <div
                                        class="grid gap-3"
                                        :class="
                                            locations.length === 1
                                                ? 'grid-cols-1'
                                                : locations.length === 2
                                                  ? 'grid-cols-2'
                                                  : locations.length === 3
                                                    ? 'grid-cols-3'
                                                    : 'grid-cols-4'
                                        "
                                    >
                                        <div v-for="location in locations" :key="location.id">
                                            <label class="mb-1 block font-sans text-xs text-slate-400">{{ location.name }}</label>
                                            <input
                                                v-model="variant.stock_adjustments[location.id]"
                                                type="number"
                                                step="0.001"
                                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                            />
                                        </div>
                                    </div>
                                    <p class="mt-1 font-sans text-xs text-slate-400">
                                        Changing a value records a stock adjustment for the difference — it doesn't overwrite history.
                                    </p>
                                </div>

                                <div class="col-span-6 flex items-end gap-4">
                                    <label class="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            v-model="variant.track_inventory"
                                            class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                        />
                                        <span class="font-sans text-xs text-ink-900">Track stock</span>
                                    </label>
                                    <label class="flex items-center gap-1.5" title="Off by default: quantity rounds to whole numbers (e.g. spice boxes sold as 1, 2, 3). Enable for items sold by variable weight/measure (e.g. rice by the kg).">
                                        <input
                                            type="checkbox"
                                            v-model="variant.is_loose"
                                            class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                        />
                                        <span class="font-sans text-xs text-ink-900">Loose item</span>
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            v-model="variant.is_active"
                                            class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                        />
                                        <span class="font-sans text-xs text-ink-900">Active</span>
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            v-model="variant.can_edit_price"
                                            class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                        />
                                        <span class="font-sans text-xs text-ink-900">Allow price edit in POS</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="addVariant"
                        class="mt-4 flex items-center gap-1.5 font-sans text-sm font-medium text-ink-800 hover:underline"
                    >
                        <Plus :size="16" /> Add variant
                    </button>
                </section>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-5 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                    >
                        {{ isEditing ? 'Save changes' : 'Create product' }}
                    </button>
                </div>
            </form>
        </div>
    </DashboardLayout>
</template>