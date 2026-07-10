<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { useBarcodeScanner } from '@/composables/useBarcodeScanner';
import {
    Search, Plus, Minus, X, User, Percent, ScanLine,
    ShoppingCart, History, AlertTriangle,
} from 'lucide-vue-next';

interface Variant {
    id: number;
    name: string;
    sku: string | null;
    price: string;
    can_edit_price: boolean;
    is_loose: boolean;
    image_url: string | null;
}

interface ProductWithVariants {
    id: number;
    name: string;
    brand: string | null;
    image_url: string | null;
    variants: Variant[];
}

interface DocumentLine {
    id: number;
    variant_id: number | null;
    description: string;
    quantity: string;
    unit_price: string;
    tax_id: number | null;
    tax_amount: string;
    discount_percent: string;
    discount_amount: string;
    line_total: string;
    is_custom: boolean;
    variant: { id: number; can_edit_price: boolean; is_loose: boolean; product: { name: string } | null } | null;
}

interface Contact {
    id: number;
    name: string;
    phone: string | null;
}

interface OrderDocument {
    id: number;
    number: string;
    status: string;
    location_id: number;
    contact_id: number | null;
    contact: Contact | null;
    subtotal: string;
    tax_total: string;
    discount_type: 'percentage' | 'fixed' | null;
    discount_value: string | null;
    total: string;
    lines: DocumentLine[];
}

interface LocationOption {
    id: number;
    name: string;
}

const props = defineProps<{
    products: ProductWithVariants[];
    locations: LocationOption[];
    drafts: OrderDocument[];
    defaultLocationId: number | null;
}>();

// ---- Draft tabs ----
const drafts = ref<OrderDocument[]>([...props.drafts]);
const activeDraftId = ref<number | null>(drafts.value[0]?.id ?? null);
const activeDraft = computed(() => drafts.value.find((d) => d.id === activeDraftId.value) ?? null);

function jsonHeaders(): HeadersInit {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
    };
}

function replaceActiveDraft(updated: OrderDocument) {
    const index = drafts.value.findIndex((d) => d.id === updated.id);
    if (index !== -1) {
        drafts.value[index] = updated;
    }
}

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Display-only formatting for quantity — the backend stores/calculates at
// full precision (up to 6 decimals) so a typed price always round-trips
// back exactly, but showing 6 decimals in the cart is visual noise. This
// truncates trailing zeros down to a max of 3 decimals for display; the
// underlying line.quantity value used for calculations is untouched.
function displayQty(value: string | number): string {
    const num = Number(value);
    return num.toFixed(3).replace(/\.?0+$/, '') || '0';
}

async function startNewDraft() {
    const locationId = activeDraft.value?.location_id ?? props.defaultLocationId ?? props.locations[0]?.id;
    if (!locationId) return;

    const response = await fetch(route('pos.drafts.store'), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ location_id: locationId }),
    });
    const newDraft = await response.json();
    drafts.value.push({ ...newDraft, lines: [] });
    activeDraftId.value = newDraft.id;
}

async function closeDraft(draftId: number) {
    const draft = drafts.value.find((d) => d.id === draftId);
    if (!draft) return;

    if (draft.lines.length > 0 && !confirm(`Discard order ${draft.number}? This cannot be undone.`)) {
        return;
    }

    await fetch(route('pos.drafts.destroy', { document: draftId }), {
        method: 'DELETE',
        headers: jsonHeaders(),
    });

    drafts.value = drafts.value.filter((d) => d.id !== draftId);
    if (activeDraftId.value === draftId) {
        activeDraftId.value = drafts.value[0]?.id ?? null;
    }
}

onMounted(() => {
    if (drafts.value.length === 0) {
        startNewDraft();
    }
});

// ---- Product grid + search ----
const search = ref('');
const searchResults = ref<Variant[] | null>(null);
let searchDebounce: ReturnType<typeof setTimeout>;

watch(search, (query) => {
    clearTimeout(searchDebounce);
    if (!query.trim()) {
        searchResults.value = null;
        return;
    }
    searchDebounce = setTimeout(async () => {
        const response = await fetch(`${route('pos.search')}?search=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
        });
        searchResults.value = await response.json();
    }, 250);
});

// Variant picker popover — shown when a multi-variant product is clicked.
const variantPickerProduct = ref<ProductWithVariants | null>(null);

function onProductClick(product: ProductWithVariants) {
    if (product.variants.length === 1) {
        addVariantToCart(product.variants[0].id);
    } else if (product.variants.length > 1) {
        variantPickerProduct.value = product;
    }
}

function pickVariant(variantId: number) {
    addVariantToCart(variantId);
    variantPickerProduct.value = null;
}

// Shown when a click-to-add is blocked by insufficient stock, or after a
// barcode scan that succeeded but flagged a stock discrepancy.
const stockAlert = ref<{ type: 'error' | 'warning'; message: string } | null>(null);

async function addVariantToCart(variantId: number, quantity = 1) {
    if (!activeDraft.value) {
        await startNewDraft();
    }
    if (!activeDraft.value) return;

    const response = await fetch(route('pos.lines.store', { document: activeDraft.value.id }), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ variant_id: variantId, quantity }),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => null);
        const message = data?.errors?.quantity?.[0] ?? 'Could not add this item.';
        stockAlert.value = { type: 'error', message };
        setTimeout(() => (stockAlert.value = null), 4000);
        return;
    }

    replaceActiveDraft(await response.json());
}

// ---- Custom / off-catalog item entry ----
const showCustomItemPanel = ref(false);
const customItemName = ref('');
const customItemPrice = ref('');
const quickPrices = [50, 100, 150, 200, 300, 500, 1000];
const isAddingCustomItem = ref(false);

function pickQuickPrice(price: number) {
    customItemPrice.value = String(price);
}

function openCustomItemPanel() {
    customItemName.value = '';
    customItemPrice.value = '';
    showCustomItemPanel.value = true;
}

async function submitCustomItem() {
    const name = customItemName.value.trim();
    const price = Number(customItemPrice.value);
    if (!name || !price || price <= 0) return;

    if (!activeDraft.value) {
        await startNewDraft();
    }
    if (!activeDraft.value) return;

    isAddingCustomItem.value = true;
    try {
        const response = await fetch(route('pos.lines.custom', { document: activeDraft.value.id }), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ description: name, price }),
        });
        if (response.ok) {
            replaceActiveDraft(await response.json());
            showCustomItemPanel.value = false;
        }
    } finally {
        isAddingCustomItem.value = false;
    }
}

// ---- Barcode scanning — always targets whichever draft tab is active ----
const scanFeedback = ref<string | null>(null);

useBarcodeScanner(async (code) => {
    if (!activeDraft.value) {
        await startNewDraft();
    }
    if (!activeDraft.value) return;

    const response = await fetch(route('pos.scan-barcode'), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ barcode: code, document_id: activeDraft.value.id }),
    });

    if (response.ok) {
        const data = await response.json();
        replaceActiveDraft(data.document);

        if (data.stock_warning) {
            // Scanning never blocks — the cashier already has the item in
            // hand — but a stock discrepancy is still worth flagging.
            stockAlert.value = { type: 'warning', message: data.stock_warning };
            setTimeout(() => (stockAlert.value = null), 5000);
        } else {
            scanFeedback.value = 'Added to cart';
            setTimeout(() => (scanFeedback.value = null), 1500);
        }
    } else {
        scanFeedback.value = 'Barcode not found';
        setTimeout(() => (scanFeedback.value = null), 1500);
    }
});

// ---- Cart line editing ----
const selectedLineId = ref<number | null>(null);

function selectLine(lineId: number) {
    selectedLineId.value = selectedLineId.value === lineId ? null : lineId;
}

function deselectAll() {
    selectedLineId.value = null;
}

async function updateLineQuantity(line: DocumentLine, delta: number) {
    if (!activeDraft.value) return;
    const newQty = Math.max(0.001, Number(line.quantity) + delta);
    await setLineQuantity(line, newQty);
}

async function setLineQuantity(line: DocumentLine, quantity: number) {
    if (!activeDraft.value || quantity <= 0) return;
    const response = await fetch(route('pos.lines.update', { document: activeDraft.value.id, line: line.id }), {
        method: 'PUT',
        headers: jsonHeaders(),
        body: JSON.stringify({ quantity }),
    });
    if (response.ok) {
        replaceActiveDraft(await response.json());
    } else {
        const data = await response.json().catch(() => null);
        const message = data?.errors?.quantity?.[0] ?? 'Could not update quantity.';
        stockAlert.value = { type: 'error', message };
        setTimeout(() => (stockAlert.value = null), 4000);
    }
}

// Entering a target line price back-solves quantity from the variant's
// base rate (unit_price never changes) and clears any existing discount,
// since a manually-typed price supersedes it. Only allowed when the
// line's variant has can_edit_price enabled — enforced server-side too.
async function setLineTargetPrice(line: DocumentLine, targetPrice: number) {
    if (!activeDraft.value || targetPrice <= 0) return;
    const response = await fetch(route('pos.lines.update', { document: activeDraft.value.id, line: line.id }), {
        method: 'PUT',
        headers: jsonHeaders(),
        body: JSON.stringify({ target_price: targetPrice }),
    });
    if (response.ok) {
        replaceActiveDraft(await response.json());
    } else {
        const data = await response.json().catch(() => null);
        const message = data?.errors?.quantity?.[0] ?? data?.errors?.target_price?.[0] ?? 'Could not update price.';
        stockAlert.value = { type: 'error', message };
        setTimeout(() => (stockAlert.value = null), 4000);
    }
}

async function removeLine(line: DocumentLine) {
    if (!activeDraft.value) return;
    const response = await fetch(route('pos.lines.destroy', { document: activeDraft.value.id, line: line.id }), {
        method: 'DELETE',
        headers: jsonHeaders(),
    });
    if (response.ok) {
        replaceActiveDraft(await response.json());
        if (selectedLineId.value === line.id) selectedLineId.value = null;
    }
}

// ---- Discount panel — targets the selected line, or the whole order if nothing is selected ----
const showDiscountPanel = ref(false);
const discountType = ref<'percentage' | 'fixed'>('percentage');
const discountValue = ref('');

const selectedLine = computed(() =>
    activeDraft.value?.lines.find((l) => l.id === selectedLineId.value) ?? null
);

const canDiscountSelectedLine = computed(() => selectedLine.value?.variant?.can_edit_price ?? false);

async function applyDiscount() {
    if (!activeDraft.value || !discountValue.value) return;

    if (selectedLine.value) {
        if (!canDiscountSelectedLine.value) return;
        const response = await fetch(
            route('pos.lines.discount', { document: activeDraft.value.id, line: selectedLine.value.id }),
            {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({ discount_type: discountType.value, discount_value: discountValue.value }),
            }
        );
        if (response.ok) replaceActiveDraft(await response.json());
    } else {
        const response = await fetch(route('pos.discount', { document: activeDraft.value.id }), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ discount_type: discountType.value, discount_value: discountValue.value }),
        });
        if (response.ok) replaceActiveDraft(await response.json());
    }

    showDiscountPanel.value = false;
    discountValue.value = '';
}

async function clearOrderDiscount() {
    if (!activeDraft.value) return;
    const response = await fetch(route('pos.discount', { document: activeDraft.value.id }), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ discount_type: null, discount_value: null }),
    });
    if (response.ok) replaceActiveDraft(await response.json());
}

// ---- Customer assignment ----
const showContactSearch = ref(false);
const contactSearch = ref('');
const contactResults = ref<Contact[]>([]);
const isCreatingContact = ref(false);
const newContactPhone = ref('');
let contactDebounce: ReturnType<typeof setTimeout>;

watch(contactSearch, (query) => {
    clearTimeout(contactDebounce);
    isCreatingContact.value = false;
    contactDebounce = setTimeout(async () => {
        const response = await fetch(`${route('pos.contacts.search')}?search=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
        });
        contactResults.value = await response.json();
    }, 250);
});

async function assignContact(contactId: number | null) {
    if (!activeDraft.value) return;
    const response = await fetch(route('pos.contact', { document: activeDraft.value.id }), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ contact_id: contactId }),
    });
    if (response.ok) replaceActiveDraft(await response.json());
    showContactSearch.value = false;
    contactSearch.value = '';
}

// Quick-create a customer directly from the search box, matching the
// inline "create new" pattern used elsewhere in the app (categories,
// units) — then immediately assigns the new contact to this order.
async function createAndAssignContact() {
    if (!contactSearch.value.trim()) return;

    const response = await fetch(route('pos.contacts.store'), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ name: contactSearch.value.trim(), phone: newContactPhone.value || null }),
    });

    if (response.ok) {
        const contact: Contact = await response.json();
        await assignContact(contact.id);
    }

    isCreatingContact.value = false;
    newContactPhone.value = '';
}

// ---- Proceed to checkout ----
async function goToCheckout() {
    if (!activeDraft.value || activeDraft.value.lines.length === 0) return;

    // Re-check this draft's live status first — it may have already been
    // completed in another tab (e.g. checkout was finished there, and this
    // tab still shows it as an open draft until the cashier clicks back
    // into it). Avoids sailing into a 422 on an order that's no longer
    // editable.
    const isStillDraft = await verifyDraftIsEditable(activeDraft.value.id);
    if (!isStillDraft) return;

    window.open(route('pos.checkout.show', { document: activeDraft.value.id }), '_blank');
}

async function selectDraftTab(draftId: number) {
    activeDraftId.value = draftId;
    await verifyDraftIsEditable(draftId);
}

/**
 * Fetches the live status of a draft. If it's no longer a draft (already
 * checked out elsewhere), removes it from the local tab bar and switches
 * to another open draft, rather than leaving a stale, broken tab clickable.
 * Returns true if the draft is still editable.
 */
async function verifyDraftIsEditable(draftId: number): Promise<boolean> {
    const response = await fetch(route('pos.drafts.refresh', { document: draftId }), {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) return false;

    const fresh = await response.json();

    if (fresh.status !== 'draft') {
        drafts.value = drafts.value.filter((d) => d.id !== draftId);
        if (activeDraftId.value === draftId) {
            activeDraftId.value = drafts.value[0]?.id ?? null;
        }
        alert(`Order ${fresh.number} was already completed and has been removed from your open orders.`);
        return false;
    }

    replaceActiveDraft(fresh);
    return true;
}
</script>

<template>
    <Head title="POS" />

    <DashboardLayout>
        <div
            v-if="stockAlert"
            class="mb-3 flex items-center gap-2 rounded-lg px-4 py-2.5 font-sans text-sm font-medium"
            :class="
                stockAlert.type === 'error'
                    ? 'bg-danger-500/15 text-danger-500'
                    : 'bg-amber-100 text-amber-700'
            "
        >
            <AlertTriangle :size="16" class="shrink-0" />
            {{ stockAlert.message }}
        </div>

        <div class="flex h-[calc(100vh-8rem)] gap-4">
            <!-- Left: product catalog -->
            <div class="flex flex-1 flex-col">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div class="relative max-w-md flex-1">
                        <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search products, SKU, barcode..."
                            class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>

                    <div
                        v-if="scanFeedback"
                        class="rounded-lg bg-brand-500/15 px-3 py-1.5 font-sans text-sm text-ink-800"
                    >
                        <ScanLine :size="14" class="mr-1 inline" /> {{ scanFeedback }}
                    </div>

                    <button
                        @click="openCustomItemPanel"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 font-sans text-sm text-slate-600 hover:bg-slate-50"
                    >
                        <Plus :size="16" /> Custom item
                    </button>

                    <Link
                        :href="route('pos.history')"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 font-sans text-sm text-slate-600 hover:bg-slate-50"
                    >
                        <History :size="16" /> Orders
                    </Link>
                </div>

                <!-- Search results override the grid when active -->
                <div v-if="searchResults" class="flex-1 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3">
                    <div v-if="searchResults.length === 0" class="py-8 text-center font-sans text-sm text-slate-400">
                        No matches found.
                    </div>
                    <div v-else class="grid grid-cols-4 gap-3">
                        <button
                            v-for="variant in searchResults"
                            :key="variant.id"
                            @click="addVariantToCart(variant.id)"
                            class="flex flex-col rounded-lg border border-slate-200 p-3 text-left hover:border-brand-500 hover:bg-brand-500/5"
                        >
                            <div class="mb-2 flex h-20 items-center justify-center overflow-hidden rounded-md bg-slate-50">
                                <img v-if="variant.image_url" :src="variant.image_url" class="h-full w-full object-contain" alt="" />
                            </div>
                            <p class="font-sans text-sm font-medium text-ink-900">{{ variant.name }}</p>
                            <p class="font-sans text-sm text-slate-500">Rs {{ money(variant.price) }}</p>
                        </button>
                    </div>
                </div>

                <!-- Product grid -->
                <div v-else class="flex-1 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3">
                    <div class="grid grid-cols-4 gap-3">
                        <button
                            v-for="product in products"
                            :key="product.id"
                            @click="onProductClick(product)"
                            class="flex flex-col rounded-lg border border-slate-200 p-3 text-left hover:border-brand-500 hover:bg-brand-500/5"
                        >
                            <div class="mb-2 flex h-20 items-center justify-center overflow-hidden rounded-md bg-slate-50">
                                <img v-if="product.image_url" :src="product.image_url" class="h-full w-full object-contain" alt="" />
                            </div>
                            <p class="font-sans text-sm font-medium text-ink-900">{{ product.name }}</p>
                            <p v-if="product.variants.length > 1" class="font-sans text-xs text-slate-400">
                                {{ product.variants.length }} variants
                            </p>
                            <p v-else-if="product.variants.length === 1" class="font-sans text-sm text-slate-500">
                                Rs {{ money(product.variants[0].price) }}
                            </p>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right: cart sidebar -->
            <div class="flex w-[30vw] min-w-[420px] shrink-0 flex-col rounded-lg border border-slate-200 bg-white">
                <!-- Draft tabs -->
                <div class="flex items-center gap-1 overflow-x-auto border-b border-slate-200 px-2 pt-2">
                    <button
                        v-for="draft in drafts"
                        :key="draft.id"
                        @click="selectDraftTab(draft.id)"
                        class="group flex shrink-0 items-center gap-1 whitespace-nowrap rounded-t-lg px-2.5 py-2 font-sans text-xs font-medium"
                        :class="activeDraftId === draft.id ? 'bg-brand-500/15 text-ink-900' : 'text-slate-500 hover:bg-slate-50'"
                    >
                        {{ draft.number }}
                        <X
                            :size="12"
                            class="opacity-0 group-hover:opacity-100"
                            @click.stop="closeDraft(draft.id)"
                        />
                    </button>
                    <button
                        @click="startNewDraft"
                        class="shrink-0 rounded-t-lg px-2 py-2 text-slate-400 hover:bg-slate-50 hover:text-ink-800"
                        title="New order"
                    >
                        <Plus :size="14" />
                    </button>
                </div>

                <template v-if="activeDraft">
                    <!-- Customer -->
                    <div class="border-b border-slate-100 px-3 py-2">
                        <button
                            v-if="!activeDraft.contact"
                            @click="showContactSearch = !showContactSearch"
                            class="flex items-center gap-1.5 font-sans text-xs text-slate-500 hover:text-ink-800"
                        >
                            <User :size="14" /> Add customer
                        </button>
                        <div v-else class="flex items-center justify-between">
                            <span class="flex items-center gap-1.5 font-sans text-xs text-ink-900">
                                <User :size="14" /> {{ activeDraft.contact.name }}
                            </span>
                            <button @click="assignContact(null)" class="text-slate-400 hover:text-danger-500">
                                <X :size="12" />
                            </button>
                        </div>

                        <div v-if="showContactSearch" class="relative mt-2">
                            <input
                                v-model="contactSearch"
                                type="text"
                                placeholder="Search or type a new name..."
                                autofocus
                                class="w-full rounded-lg border-slate-200 px-2 py-1.5 font-sans text-xs focus:border-brand-500 focus:ring-brand-500"
                            />
                            <div
                                v-if="contactSearch.trim() && !isCreatingContact"
                                class="absolute z-10 mt-1 w-full rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                            >
                                <button
                                    v-for="contact in contactResults"
                                    :key="contact.id"
                                    @click="assignContact(contact.id)"
                                    class="block w-full px-3 py-1.5 text-left font-sans text-xs hover:bg-slate-50"
                                >
                                    {{ contact.name }}
                                    <span v-if="contact.phone" class="text-slate-400">({{ contact.phone }})</span>
                                </button>
                                <button
                                    @click="isCreatingContact = true"
                                    class="flex w-full items-center gap-1 border-t border-slate-100 px-3 py-1.5 text-left font-sans text-xs font-medium text-ink-800 hover:bg-slate-50"
                                >
                                    <Plus :size="12" /> Add "{{ contactSearch }}" as new customer
                                </button>
                            </div>

                            <!-- Optional phone number before confirming the new customer -->
                            <div v-if="isCreatingContact" class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                <p class="mb-1.5 font-sans text-xs text-ink-900">New customer: <strong>{{ contactSearch }}</strong></p>
                                <input
                                    v-model="newContactPhone"
                                    type="text"
                                    placeholder="Phone (optional)"
                                    class="w-full rounded-lg border-slate-200 px-2 py-1.5 font-sans text-xs focus:border-brand-500 focus:ring-brand-500"
                                />
                                <div class="mt-1.5 flex gap-2">
                                    <button
                                        @click="createAndAssignContact"
                                        class="rounded-lg bg-brand-500 px-2.5 py-1 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400"
                                    >
                                        Create & assign
                                    </button>
                                    <button
                                        @click="isCreatingContact = false"
                                        class="font-sans text-xs text-slate-400 hover:text-ink-800"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cart lines — clicking empty area deselects, enabling order-level discount -->
                    <div class="flex-1 overflow-y-auto px-3 py-2" @click.self="deselectAll">
                        <div v-if="activeDraft.lines.length === 0" class="flex h-full items-center justify-center">
                            <p class="font-sans text-sm text-slate-400">Cart is empty — click a product or scan a barcode</p>
                        </div>

                        <div
                            v-for="line in activeDraft.lines"
                            :key="line.id"
                            @click="selectLine(line.id)"
                            class="mb-2 w-full cursor-pointer rounded-lg border p-2.5 text-left transition-colors"
                            :class="selectedLineId === line.id ? 'border-brand-500 bg-brand-500/5' : 'border-slate-100 hover:border-slate-200'"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-sans text-sm font-medium text-ink-900">{{ line.description }}</p>
                                <button @click.stop="removeLine(line)" class="shrink-0 text-slate-300 hover:text-danger-500">
                                    <X :size="14" />
                                </button>
                            </div>

                            <div class="mt-1.5 flex items-center justify-between">
                                <!-- Custom/off-catalog items have no meaningful quantity —
                                     they're always exactly 1, so the qty controls are hidden
                                     entirely rather than showing a fixed, unusable "1". -->
                                <div v-if="!line.is_custom" class="flex items-center gap-2">
                                    <button
                                        @click.stop="updateLineQuantity(line, -1)"
                                        class="flex h-6 w-6 items-center justify-center rounded border border-slate-200 text-slate-500 hover:bg-slate-50"
                                    >
                                        <Minus :size="12" />
                                    </button>
                                    <!-- Typeable quantity — committed on blur/Enter, always kept in
                                         sync with price via the same base-rate ratio. Spinner arrows
                                         removed (no-spinner class) since the +/- buttons already
                                         cover that, and the arrows were crowding the digits.
                                         Non-loose items step by whole numbers; loose items (sold by
                                         variable weight/measure) allow fractional entry. Backend
                                         enforces the actual rounding regardless of this hint. -->
                                    <input
                                        type="number"
                                        :step="line.variant?.is_loose ? '0.001' : '1'"
                                        :min="line.variant?.is_loose ? '0.001' : '1'"
                                        :value="displayQty(line.quantity)"
                                        @click.stop
                                        @change="(e) => setLineQuantity(line, Number((e.target as HTMLInputElement).value))"
                                        class="no-spinner w-20 rounded border border-slate-200 text-center font-sans text-sm text-ink-900 focus:border-brand-500 focus:ring-brand-500"
                                    />
                                    <button
                                        @click.stop="updateLineQuantity(line, 1)"
                                        class="flex h-6 w-6 items-center justify-center rounded border border-slate-200 text-slate-500 hover:bg-slate-50"
                                    >
                                        <Plus :size="12" />
                                    </button>
                                </div>
                                <div v-else class="font-sans text-xs italic text-slate-400">Custom item</div>

                                <div class="text-right">
                                    <p
                                        v-if="Number(line.discount_amount) > 0"
                                        class="font-sans text-xs text-slate-400 line-through"
                                    >
                                        Rs {{ money(Number(line.unit_price) * Number(line.quantity)) }}
                                    </p>

                                    <!-- Typeable price — available for every line regardless of
                                         can_edit_price (that flag only gates discounting). Shows/
                                         accepts the PRE-TAX amount (line_total minus tax); tax is
                                         added back on top after quantity is derived. Custom items
                                         have no per-unit rate to derive quantity from, so they show
                                         a plain read-only total instead. -->
                                    <input
                                        v-if="!line.is_custom"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        :value="(Number(line.line_total) - Number(line.tax_amount)).toFixed(2)"
                                        @click.stop
                                        @change="(e) => setLineTargetPrice(line, Number((e.target as HTMLInputElement).value))"
                                        class="no-spinner w-20 rounded border border-slate-200 text-right font-sans text-sm font-semibold text-ink-900 focus:border-brand-500 focus:ring-brand-500"
                                    />
                                    <p v-if="!line.is_custom" class="font-sans text-[10px] text-slate-400">pre-tax</p>
                                    <p v-else class="font-sans text-sm font-semibold text-ink-900">Rs {{ money(line.line_total) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Discount trigger -->
                    <div class="border-t border-slate-100 px-3 py-2">
                        <button
                            v-if="!showDiscountPanel"
                            @click="showDiscountPanel = true"
                            :disabled="selectedLineId !== null && !canDiscountSelectedLine"
                            class="flex items-center gap-1.5 font-sans text-xs text-slate-500 hover:text-ink-800 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <Percent :size="14" />
                            {{ selectedLine ? `Discount: ${selectedLine.description}` : 'Order discount' }}
                        </button>
                        <p
                            v-if="selectedLineId !== null && !canDiscountSelectedLine"
                            class="font-sans text-xs text-slate-400"
                        >
                            Price editing isn't enabled for this item.
                        </p>

                        <div v-if="showDiscountPanel" class="flex items-center gap-2">
                            <select
                                v-model="discountType"
                                class="rounded-lg border-slate-200 font-sans text-xs focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option value="percentage">%</option>
                                <option value="fixed">Rs</option>
                            </select>
                            <input
                                v-model="discountValue"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0"
                                class="w-20 rounded-lg border-slate-200 font-sans text-xs focus:border-brand-500 focus:ring-brand-500"
                            />
                            <button
                                @click="applyDiscount"
                                class="rounded-lg bg-brand-500 px-2.5 py-1.5 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400"
                            >
                                Apply
                            </button>
                            <button
                                @click="showDiscountPanel = false"
                                class="font-sans text-xs text-slate-400 hover:text-ink-800"
                            >
                                Cancel
                            </button>
                        </div>

                        <button
                            v-if="!selectedLine && activeDraft.discount_type"
                            @click="clearOrderDiscount"
                            class="mt-1 font-sans text-xs text-danger-500 hover:underline"
                        >
                            Remove order discount ({{ activeDraft.discount_type === 'percentage' ? `${activeDraft.discount_value}%` : `Rs ${activeDraft.discount_value}` }})
                        </button>
                    </div>

                    <!-- Totals + checkout -->
                    <div class="border-t border-slate-200 px-3 py-3">
                        <div class="flex justify-between font-sans text-sm text-slate-500">
                            <span>Subtotal</span>
                            <span>Rs {{ money(activeDraft.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between font-sans text-sm text-slate-500">
                            <span>Tax</span>
                            <span>Rs {{ money(activeDraft.tax_total) }}</span>
                        </div>
                        <div v-if="activeDraft.discount_type" class="flex justify-between font-sans text-sm text-danger-500">
                            <span>Discount</span>
                            <span>
                                -{{ activeDraft.discount_type === 'percentage' ? `${activeDraft.discount_value}%` : `Rs ${money(activeDraft.discount_value ?? 0)}` }}
                            </span>
                        </div>
                        <div class="mt-1 flex justify-between border-t border-slate-100 pt-1 font-sans text-base font-bold text-ink-900">
                            <span>Total</span>
                            <span>Rs {{ money(activeDraft.total) }}</span>
                        </div>

                        <button
                            @click="goToCheckout"
                            :disabled="activeDraft.lines.length === 0"
                            class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-brand-500 py-2.5 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-40"
                        >
                            <ShoppingCart :size="16" /> Checkout
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Variant picker popover -->
        <div
            v-if="variantPickerProduct"
            class="fixed inset-0 z-20 flex items-center justify-center bg-ink-900/30"
            @click.self="variantPickerProduct = null"
        >
            <div class="w-96 rounded-lg border border-slate-200 bg-white p-4 shadow-xl">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-sans text-sm font-semibold text-ink-900">{{ variantPickerProduct.name }}</h3>
                    <button @click="variantPickerProduct = null" class="text-slate-400 hover:text-ink-800">
                        <X :size="16" />
                    </button>
                </div>

                <!-- Each row shows its OWN variant image, not the parent
                     product's image — variants can have distinct photos
                     (e.g. different colors/flavors of the same product). -->
                <div class="flex flex-col gap-1.5 overflow-y-auto" style="max-height: 20rem">
                    <button
                        v-for="variant in variantPickerProduct.variants"
                        :key="variant.id"
                        @click="pickVariant(variant.id)"
                        class="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2 text-left hover:border-brand-500 hover:bg-brand-500/5"
                    >
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-50">
                            <img
                                v-if="variant.image_url"
                                :src="variant.image_url"
                                class="h-full w-full object-contain"
                                alt=""
                            />
                        </div>
                        <span class="flex-1 font-sans text-sm text-ink-900">{{ variant.name }}</span>
                        <span class="font-sans text-sm text-slate-500">Rs {{ money(variant.price) }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Custom / off-catalog item panel -->
        <div
            v-if="showCustomItemPanel"
            class="fixed inset-0 z-20 flex items-center justify-center bg-ink-900/30"
            @click.self="showCustomItemPanel = false"
        >
            <div class="w-96 rounded-lg border border-slate-200 bg-white p-4 shadow-xl">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-sans text-sm font-semibold text-ink-900">Custom item</h3>
                    <button @click="showCustomItemPanel = false" class="text-slate-400 hover:text-ink-800">
                        <X :size="16" />
                    </button>
                </div>

                <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Item name</label>
                <input
                    v-model="customItemName"
                    type="text"
                    placeholder="e.g. Custom repair fee"
                    autofocus
                    class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />

                <label class="mb-1 mt-3 block font-sans text-xs font-medium text-slate-500">Price</label>
                <div class="grid grid-cols-4 gap-1.5">
                    <button
                        v-for="price in quickPrices"
                        :key="price"
                        @click="pickQuickPrice(price)"
                        class="rounded-lg border px-2 py-1.5 font-sans text-xs font-medium transition-colors"
                        :class="
                            Number(customItemPrice) === price
                                ? 'border-brand-500 bg-brand-500/15 text-ink-900'
                                : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                        "
                    >
                        {{ price }}
                    </button>
                </div>

                <input
                    v-model="customItemPrice"
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="Or enter a custom price"
                    class="no-spinner mt-2 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />

                <button
                    @click="submitCustomItem"
                    :disabled="!customItemName.trim() || !Number(customItemPrice) || isAddingCustomItem"
                    class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-brand-500 py-2.5 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-40"
                >
                    <Plus :size="16" /> Add to cart
                </button>
            </div>
        </div>
    </DashboardLayout>
</template>

<style scoped>
/* Hides the native up/down spinner arrows on number inputs in the cart —
   the dedicated +/- buttons already cover that, and the arrows were
   crowding out the actual digits in a narrow field. */
.no-spinner::-webkit-outer-spin-button,
.no-spinner::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.no-spinner {
    -moz-appearance: textfield;
}
</style>