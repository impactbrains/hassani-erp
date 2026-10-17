<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ArrowLeft, Banknote, Camera, PackagePlus, ClipboardList, Lock, Minus, PauseCircle, Percent, Plus, Printer, Receipt, ScanLine, Trash2, UserRound, X } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Badge from '@/components/ui/Badge.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import PinApproval from '@/components/app/PinApproval.vue';
import FlashToasts from '@/components/app/FlashToasts.vue';
import DenominationCount from '@/components/pos/DenominationCount.vue';
import PaymentDialog from '@/components/pos/PaymentDialog.vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import ShiftReportView from '@/components/pos/ShiftReportView.vue';
import { ApiError, del, get, post, uuid } from '@/lib/http';
import { dateTime, money, qty, round2 } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * The POS screen. Scanner first: a handheld USB scanner types the barcode
 * plus Enter into the search box, which is always focused. The browser
 * keeps a proposed cart; the server re-prices it (quote) and posts the sale.
 *
 * Several carts can be open at once, one tab per customer. Switching tabs
 * keeps every cart exactly as it was (no hold / resume needed).
 *
 * Keys: F2 search · F3 new cart · F4 customer · F6 discount · F7/F8 previous/next cart
 *       · Alt+1..9 jump to a cart · F9 pay · Esc close.
 */
const props = defineProps<{ store: any; registers: any[]; shift: any | null; paymentMethods: any[]; reasons: Record<string, any[]>; settings: any; can: Record<string, boolean>; unlistedItem: any }>();
const page = usePage<any>();

interface Line { description?: string; barcode?: string | null; unlisted?: boolean; key: string; variant_id: number; name: string; name_ur: string | null; sku: string; units: any[]; variant_unit_id: number; quantity: string; unit_price: string | null; price_token: string | null; discount: { type: 'amount' | 'percent'; value: string } | null; available: string }
type Discount = { type: 'amount' | 'percent'; value: string };
/** Everything that belongs to one customer's sale. One of these per tab. */
interface Cart {
    id: string;
    number: number;
    lines: Line[];
    removed: any[];
    selected: number;
    customer: any | null;
    cartDiscount: Discount | null;
    quote: any | null;
    quoteError: string | null;
    tokens: { discount?: string; credit?: string };
    idempotencyKey: string;
    quoteSeq: number;
}

const shift = ref<any | null>(props.shift);

// ---------- carts (one tab per customer) ----------
const carts = ref<Cart[]>([]);
const activeId = ref('');
function makeCart(number: number): Cart {
    return { id: uuid(), number, lines: [], removed: [], selected: -1, customer: null, cartDiscount: null, quote: null, quoteError: null, tokens: {}, idempotencyKey: uuid(), quoteSeq: 0 };
}
carts.value = [makeCart(1)];
activeId.value = carts.value[0].id;
const activeCart = computed<Cart>(() => carts.value.find((c) => c.id === activeId.value) ?? carts.value[0]);

// The rest of the screen works on "the current cart". These point at the active tab, so
// reading or assigning them (lines.value.push, customer.value = x ...) changes that tab only.
const lines = computed<Line[]>({ get: () => activeCart.value.lines, set: (v) => { activeCart.value.lines = v; } });
const removed = computed<any[]>({ get: () => activeCart.value.removed, set: (v) => { activeCart.value.removed = v; } });
const selected = computed<number>({ get: () => activeCart.value.selected, set: (v) => { activeCart.value.selected = v; } });
const customer = computed<any | null>({ get: () => activeCart.value.customer, set: (v) => { activeCart.value.customer = v; } });
const cartDiscount = computed<Discount | null>({ get: () => activeCart.value.cartDiscount, set: (v) => { activeCart.value.cartDiscount = v; } });
const quote = computed<any | null>({ get: () => activeCart.value.quote, set: (v) => { activeCart.value.quote = v; } });
const quoteError = computed<string | null>({ get: () => activeCart.value.quoteError, set: (v) => { activeCart.value.quoteError = v; } });
const tokens = computed<{ discount?: string; credit?: string }>({ get: () => activeCart.value.tokens, set: (v) => { activeCart.value.tokens = v; } });

const search = ref('');
const results = ref<any[]>([]);
const searching = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const customerPicker = ref<any>(null);

const modal = ref<null | 'unlisted' | 'pay' | 'receipt' | 'held' | 'recent' | 'cash' | 'x' | 'close' | 'discount' | 'price' | 'camera'>(null);
const busy = ref(false);
const payError = ref<string | null>(null);
const receipt = ref<any | null>(null);
// 'sale' = the receipt of the sale just made (closing it finishes the cart); 'reprint' = an old receipt (closing it touches nothing).
const receiptKind = ref<'sale' | 'reprint'>('sale');
// Receipt language for this POS screen: starts from the store setting, the cashier can switch it.
const receiptLang = ref<'en' | 'ur' | 'both'>(readReceiptLang() ?? props.settings.receipt_language ?? 'en');
function readReceiptLang(): 'en' | 'ur' | 'both' | null {
    try {
        return (localStorage.getItem('hassani.receiptLang') as 'en' | 'ur' | 'both' | null) ?? null;
    } catch {
        return null;
    }
}
function setReceiptLang(value: 'en' | 'ur' | 'both') {
    receiptLang.value = value;
    try {
        localStorage.setItem('hassani.receiptLang', value);
    } catch {
        /* private window: keep it for this session only */
    }
}
// True while the customer picker's "Add a customer" popup is open: no tab switching or shortcuts meanwhile.
const pickerDialog = ref(false);
function onPickerDialog(open: boolean) {
    pickerDialog.value = open;
    if (!open) focusSearch();
}
const approval = ref<{ action: string; reason?: string; resolve: (token: string | null) => void } | null>(null);
const toasts = ref<any>(null);

// ---------- cart tabs ----------
const cartIsEmpty = (c: Cart) => !c.lines.length && !c.customer;
const cartHasItems = computed(() => carts.value.some((c) => c.lines.length > 0));
function tabLabel(c: Cart): string {
    return (c.customer && localName(c.customer)) || `${t('Cart')} ${c.number}`;
}
function nextCartNumber(): number {
    const used = new Set(carts.value.map((c) => c.number));
    let n = 1;
    while (used.has(n)) n++;
    return n;
}
function openBlankTab() {
    const c = makeCart(nextCartNumber());
    carts.value.push(c);
    activeId.value = c.id;
    search.value = '';
    results.value = [];
    focusSearch();
}
function addTab() {
    if (modal.value || busy.value || pickerDialog.value) return;
    // An untouched empty cart is already a "new cart": go to it instead of piling up blank tabs.
    const blank = carts.value.find(cartIsEmpty);
    if (blank) return switchTab(blank.id);
    openBlankTab();
}
function switchTab(id: string) {
    if (id === activeId.value || modal.value || busy.value || pickerDialog.value) return;
    activeId.value = id;
    search.value = '';
    results.value = [];
    focusSearch();
}
function stepTab(direction: number) {
    const i = carts.value.findIndex((c) => c.id === activeId.value);
    const next = carts.value[(i + direction + carts.value.length) % carts.value.length];
    if (next) switchTab(next.id);
}
/** Remove a tab. If it was the last one, a fresh empty cart takes its place. */
function removeTab(id: string) {
    const index = carts.value.findIndex((c) => c.id === id);
    if (index < 0) return;
    carts.value[index].quoteSeq++;
    carts.value.splice(index, 1);
    if (!carts.value.length) {
        const c = makeCart(1);
        carts.value.push(c);
        activeId.value = c.id;
    } else if (activeId.value === id) {
        activeId.value = carts.value[Math.min(index, carts.value.length - 1)].id;
    }
    search.value = '';
    results.value = [];
    focusSearch();
}
const logVoid = (c: Cart) => post(route('pos.void-log'), { lines: c.lines.map((l) => ({ description: l.name, quantity: l.quantity })) }).catch(() => null);
async function closeTab(id: string) {
    if (modal.value || busy.value || pickerDialog.value) return;
    const cart = carts.value.find((c) => c.id === id);
    if (!cart) return;
    if (cart.lines.length) {
        if (!confirm(t('Close this cart without a sale?'))) return;
        await logVoid(cart);
    }
    removeTab(id);
}
function resetCart(c: Cart) {
    c.quoteSeq++;
    Object.assign(c, { lines: [], removed: [], selected: -1, customer: null, cartDiscount: null, quote: null, quoteError: null, tokens: {}, idempotencyKey: uuid() });
}

const units = (l: Line) => l.units.find((u) => u.id === l.variant_unit_id);
const listPrice = (l: Line, i: number) => quote.value?.list_prices?.[i] ?? units(l)?.price ?? null;
const lineTotal = (i: number) => quote.value?.lines?.[i]?.line_total ?? null;
const total = computed(() => quote.value?.total ?? '0.00');
const itemCount = computed(() => lines.value.reduce((s, l) => s + (Number(l.quantity) || 0), 0));

// ---------- shift ----------
const openForm = ref({ register_id: props.registers.find((r) => !r.busy_with)?.id ?? props.registers[0]?.id, denominations: {} as Record<string, number> });
async function openShift() {
    busy.value = true;
    try {
        shift.value = await post(route('pos.shifts.open'), openForm.value);
        focusSearch();
    } catch (e) {
        notify('error', (e as Error).message);
    } finally {
        busy.value = false;
    }
}

const pickedRegister = computed(() => props.registers.find((r) => r.id === openForm.value.register_id));
const joining = computed(() => !!props.can.join && !!pickedRegister.value?.busy_with);
async function joinShift() {
    busy.value = true;
    try {
        shift.value = await post(route('pos.shifts.join'), { register_id: openForm.value.register_id });
        focusSearch();
    } catch (e) {
        notify('error', (e as Error).message);
    } finally {
        busy.value = false;
    }
}

// ---------- searching & scanning ----------
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(searchTimer);
    if (value.trim().length < 2) { results.value = []; return; }
    searchTimer = setTimeout(() => runSearch(value, false), 180);
});

async function runSearch(term: string, addSingle: boolean) {
    searching.value = true;
    try {
        const found = await get(route('lookup.products', { q: term, customer_id: customer.value?.id, limit: 12 }));
        if (addSingle && found.length === 1) {
            add(found[0]);
            search.value = '';
            results.value = [];
        } else {
            results.value = found;
            if (addSingle && !found.length) openUnlisted(term);
        }
    } finally {
        searching.value = false;
    }
}
function onSearchEnter() {
    const term = search.value.trim();
    if (!term) return;
    if (results.value.length === 1) { add(results.value[0]); search.value = ''; results.value = []; return; }
    runSearch(term, true);
}

function add(product: any, quantity = 1) {
    const unitId = product.scanned_unit_id ?? product.units.find((u: any) => u.is_default)?.id ?? product.units[0]?.id;
    if (!unitId) return notify('error', t('This product has no pack size that can be sold.'));
    const existing = lines.value.findIndex((l) => l.variant_id === product.variant_id && l.variant_unit_id === unitId && !l.unit_price);
    if (existing >= 0) {
        lines.value[existing].quantity = String(round2(Number(lines.value[existing].quantity) + quantity));
        selected.value = existing;
    } else {
        lines.value.push({ key: uuid(), variant_id: product.variant_id, name: product.name, name_ur: product.name_ur, sku: product.sku, units: product.units, variant_unit_id: unitId, quantity: String(quantity), unit_price: null, price_token: null, discount: null, available: product.available });
        selected.value = lines.value.length - 1;
    }
    if (Number(product.available) <= 0 && product.type === 'stocked') notify('error', t(':name shows no stock. Check the shelf, or the sale will be stopped.', { name: product.name }));
    focusSearch();
}

function changeQty(i: number, delta: number) {
    const l = lines.value[i];
    const next = round2(Number(l.quantity) + delta);
    if (next <= 0) return removeLine(i);
    l.quantity = String(next);
}
function removeLine(i: number) {
    const [l] = lines.value.splice(i, 1);
    removed.value.push({ description: l.name, quantity: l.quantity, sku: l.sku });
    selected.value = Math.min(selected.value, lines.value.length - 1);
}

// ---------- items not in the product list yet ----------
const unlistedForm = ref({ name: '', barcode: '', price: '', quantity: '1' });
function openUnlisted(term = '') {
    const looksLikeBarcode = /^\d{6,}$/.test(term.trim());
    unlistedForm.value = { name: looksLikeBarcode ? '' : term.trim(), barcode: looksLikeBarcode ? term.trim() : '', price: '', quantity: '1' };
    search.value = '';
    results.value = [];
    modal.value = 'unlisted';
}
function addUnlisted() {
    const f = unlistedForm.value;
    if (!f.name.trim() || !(Number(f.price) > 0) || !(Number(f.quantity) > 0)) return;
    const item = props.unlistedItem;
    lines.value.push({
        key: uuid(), variant_id: item.variant_id, name: `${f.name.trim()} (${t('not in list')})`, name_ur: null, sku: f.barcode || t('Unlisted'), units: item.units,
        variant_unit_id: item.units[0].id, quantity: f.quantity, unit_price: f.price, price_token: null, discount: null, available: '0',
        unlisted: true, description: f.name.trim(), barcode: f.barcode || null,
    });
    selected.value = lines.value.length - 1;
    modal.value = null;
    focusSearch();
}

// ---------- server pricing ----------
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
watch([lines, cartDiscount, customer], () => {
    clearTimeout(quoteTimer);
    if (!lines.value.length) { quote.value = null; quoteError.value = null; return; }
    quoteTimer = setTimeout(refreshQuote, 220);
}, { deep: true });

async function refreshQuote() {
    // Remember which cart this answer is for: the cashier may switch tabs while the server is thinking.
    const cart = activeCart.value;
    const seq = ++cart.quoteSeq;
    try {
        const result = await post(route('pos.quote'), buildPayload(cart, false));
        if (seq === cart.quoteSeq) { cart.quote = result; cart.quoteError = null; }
    } catch (e) {
        if (seq === cart.quoteSeq) cart.quoteError = (e as Error).message;
    }
}

function buildPayload(cart: Cart, withPayment: boolean, payments: any[] = []) {
    return {
        idempotency_key: cart.idempotencyKey,
        register_id: shift.value?.register_id,
        customer_id: cart.customer?.id ?? null,
        lines: cart.lines.map((l) => ({
            variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, quantity: l.quantity, unit_price: l.unit_price,
            price_approval_token: l.price_token, discount: l.discount && l.discount.value !== '' ? l.discount : null,
            description: l.unlisted ? l.description : null, barcode: l.unlisted ? l.barcode : null,
        })),
        cart_discount: cart.cartDiscount && cart.cartDiscount.value !== '' ? cart.cartDiscount : null,
        ...(withPayment ? {
            payments, expected_total: cart.quote?.total ?? '0.00', removed_lines: cart.removed,
            discount_approval_token: cart.tokens.discount ?? null, credit_approval_token: cart.tokens.credit ?? null,
        } : {}),
    };
}

// ---------- approvals ----------
function askApproval(action: string, reason?: string): Promise<string | null> {
    return new Promise((resolve) => { approval.value = { action, reason, resolve }; });
}
function approved(token: string, by: string) {
    approval.value?.resolve(token);
    approval.value = null;
    notify('success', t('Approved by :name', { name: by }));
}
function approvalCancelled() {
    approval.value?.resolve(null);
    approval.value = null;
}

// ---------- payment ----------
async function openPay() {
    if (!lines.value.length || !shift.value) return;
    await refreshQuote();
    if (quoteError.value) return notify('error', quoteError.value);
    if (quote.value?.discount_needs_approval && !tokens.value.discount) {
        const token = await askApproval('discount', t('Discount of :pct% is above your limit.', { pct: quote.value.max_discount_percent }));
        if (!token) return;
        tokens.value.discount = token;
    }
    payError.value = null;
    modal.value = 'pay';
}

async function pay(payments: any[]) {
    const cart = activeCart.value;
    busy.value = true;
    payError.value = null;
    try {
        const result = await post(route('pos.checkout'), buildPayload(cart, true, payments));
        receipt.value = result.receipt;
        receiptKind.value = 'sale';
        modal.value = 'receipt';
        if (props.settings.auto_print) nextTick(() => printReceipt());
    } catch (e) {
        if (e instanceof ApiError && ['approval_required', 'approval_invalid'].includes(e.code) && e.details?.action) {
            const action = e.details.action as string;
            const reason = action === 'credit_limit' ? (e.details.reasons ?? []).join('; ') : e.message;
            const token = await askApproval(action, reason);
            if (token) {
                if (action === 'credit_limit') cart.tokens.credit = token;
                if (action === 'discount') cart.tokens.discount = token;
                busy.value = false;
                return pay(payments);
            }
        } else if (e instanceof ApiError && e.code === 'total_changed') {
            await refreshQuote();
        }
        payError.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

/** The sale is done: close its tab (or start a fresh empty cart if it was the only one). */
function finishSale() {
    receipt.value = null;
    modal.value = null;
    removeTab(activeId.value);
}
function closeReceipt() {
    if (receiptKind.value === 'sale') return finishSale();
    // A reprint of an old receipt must never touch the cart that is being rung up.
    receipt.value = null;
    modal.value = null;
    focusSearch();
}

async function clearCart() {
    const cart = activeCart.value;
    if (!cart.lines.length) return;
    if (!confirm(t('Clear this cart without a sale?'))) return;
    await logVoid(cart);
    resetCart(cart);
    focusSearch();
}

// ---------- printing (80 mm thermal; the printer driver opens the cash drawer) ----------
function printReceipt() {
    window.print();
}

// ---------- line editing ----------
const priceEdit = ref<{ index: number; price: string } | null>(null);
function editPrice(i: number) {
    priceEdit.value = { index: i, price: lines.value[i].unit_price ?? listPrice(lines.value[i], i) ?? '' };
    modal.value = 'price';
}
async function savePrice() {
    if (!priceEdit.value) return;
    const line = lines.value[priceEdit.value.index];
    const price = priceEdit.value.price;
    if (!props.can.override_price && !line.unlisted) {
        const token = await askApproval('price_override', t(':name at Rs :price', { name: line.name, price }));
        if (!token) return;
        line.price_token = token;
    }
    line.unit_price = price;
    modal.value = null;
    focusSearch();
}
const discountEdit = ref<{ index: number | null; type: 'amount' | 'percent'; value: string }>({ index: null, type: 'percent', value: '' });
function editDiscount(i: number | null) {
    const current = i === null ? cartDiscount.value : lines.value[i].discount;
    discountEdit.value = { index: i, type: current?.type ?? 'percent', value: current?.value ?? '' };
    modal.value = 'discount';
}
function saveDiscount() {
    const d = discountEdit.value.value === '' || Number(discountEdit.value.value) === 0 ? null : { type: discountEdit.value.type, value: discountEdit.value.value };
    if (discountEdit.value.index === null) cartDiscount.value = d;
    else lines.value[discountEdit.value.index].discount = d;
    tokens.value.discount = undefined;
    modal.value = null;
    focusSearch();
}

// ---------- carts that were put on hold earlier ----------
// New carts no longer need holding (use a tab). This list only lets the cashier pick up carts
// that were held before, each one opening in its own tab.
const heldCarts = ref<any[]>([]);
async function showHeld() {
    heldCarts.value = await get(route('pos.held'));
    modal.value = 'held';
}
async function resume(id: number) {
    try {
        const res = await post(route('pos.resume', id));
        // Open it in its own tab (re-use the current one if it is still empty).
        if (!cartIsEmpty(activeCart.value)) openBlankTab();
        lines.value = res.cart.lines ?? [];
        cartDiscount.value = res.cart.cartDiscount ?? null;
        customer.value = res.cart.customer ?? null;
        lines.value.forEach((l) => (l.price_token = null));
        focusSearch();
    } catch (e) {
        notify('error', (e as Error).message);
    }
}
async function discard(id: number) {
    await del(route('pos.discard', id));
    heldCarts.value = heldCarts.value.filter((c) => c.id !== id);
}

// ---------- receipts (reprint / return) ----------
const recent = ref<any[]>([]);
const recentQ = ref('');
async function showRecent() {
    recent.value = await get(route('pos.recent', { q: recentQ.value }));
    modal.value = 'recent';
}
async function reprint(id: number) {
    receipt.value = await get(route('pos.receipt', id));
    receiptKind.value = 'reprint';
    modal.value = 'receipt';
    nextTick(() => printReceipt());
}

// ---------- cash in/out, X and Z ----------
const cashForm = ref({ type: 'payout', amount: '', reason_code_id: null as number | null, recipient: '', note: '', approval_token: null as string | null });
async function saveCash() {
    busy.value = true;
    try {
        await post(route('pos.shifts.cash', shift.value.id), cashForm.value);
        notify('success', t('Recorded.'));
        modal.value = null;
        cashForm.value = { type: 'payout', amount: '', reason_code_id: null, recipient: '', note: '', approval_token: null };
    } catch (e) {
        if (e instanceof ApiError && e.details?.action) {
            const token = await askApproval(e.details.action);
            if (token) { cashForm.value.approval_token = token; busy.value = false; return saveCash(); }
        }
        notify('error', (e as Error).message);
    } finally {
        busy.value = false;
    }
}
const xReport = ref<any | null>(null);
async function showX() {
    xReport.value = await get(route('pos.shifts.x', shift.value.id));
    modal.value = 'x';
}
const closeForm = ref({ denominations: {} as Record<string, number>, note: '', approval_token: null as string | null });
const zResult = ref<any | null>(null);
async function closeShift() {
    if (cartHasItems.value) return notify('error', t('Finish or close all open carts first.'));
    busy.value = true;
    try {
        zResult.value = await post(route('pos.shifts.close', shift.value.id), closeForm.value);
    } catch (e) {
        notify('error', (e as Error).message);
    } finally {
        busy.value = false;
    }
}

// ---------- camera scanning (fallback when no handheld scanner) ----------
let camera: any = null;
async function startCamera() {
    modal.value = 'camera';
    await nextTick();
    const { Html5Qrcode } = await import('html5-qrcode');
    camera = new Html5Qrcode('camera-reader');
    await camera.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 140 } }, (code: string) => {
        stopCamera();
        runSearch(code, true);
    }).catch(() => notify('error', t('The camera could not be opened.')));
}
function stopCamera() {
    camera?.stop?.().catch(() => null);
    camera = null;
    if (modal.value === 'camera') modal.value = null;
}

// ---------- keyboard ----------
function focusSearch() {
    nextTick(() => searchInput.value?.focus());
}
function onKey(e: KeyboardEvent) {
    if (approval.value || pickerDialog.value) return;
    if (e.key === 'F2') { e.preventDefault(); focusSearch(); }
    if (e.key === 'F4') { e.preventDefault(); customerPicker.value?.focus(); }
    if (e.key === 'F6' && props.can.discount) { e.preventDefault(); editDiscount(null); }
    if (e.key === 'F9') { e.preventDefault(); if (!modal.value) openPay(); }
    if (e.key === 'Enter' && modal.value === 'receipt') { e.preventDefault(); closeReceipt(); }
    if (shift.value && !modal.value && !busy.value) {
        if (e.key === 'F3') { e.preventDefault(); addTab(); }
        if (e.key === 'F7') { e.preventDefault(); stepTab(-1); }
        if (e.key === 'F8') { e.preventDefault(); stepTab(1); }
        if (e.altKey && !e.ctrlKey && /^[1-9]$/.test(e.key)) {
            const target = carts.value[Number(e.key) - 1];
            if (target) { e.preventDefault(); switchTab(target.id); }
        }
    }
    if (!modal.value && document.activeElement === searchInput.value && !search.value && selected.value >= 0) {
        if (e.key === '+' ) { e.preventDefault(); changeQty(selected.value, 1); }
        if (e.key === '-') { e.preventDefault(); changeQty(selected.value, -1); }
        if (e.key === 'Delete') { e.preventDefault(); removeLine(selected.value); }
        if (e.key === 'ArrowUp') { e.preventDefault(); selected.value = Math.max(0, selected.value - 1); }
        if (e.key === 'ArrowDown') { e.preventDefault(); selected.value = Math.min(lines.value.length - 1, selected.value + 1); }
    }
}
function notify(kind: 'success' | 'error', text: string) {
    toasts.value?.push(kind, text);
}
function guardUnload(e: BeforeUnloadEvent) {
    if (cartHasItems.value) e.preventDefault();
}
onMounted(() => {
    window.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', guardUnload);
    focusSearch();
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('beforeunload', guardUnload);
    stopCamera();
});

const clock = ref(new Date());
const clockTimer = setInterval(() => (clock.value = new Date()), 30000);
onBeforeUnmount(() => clearInterval(clockTimer));
</script>

<template>
    <Head :title="t('POS')" />
    <div class="no-print flex h-screen flex-col bg-paper">
        <!-- Top bar -->
        <header class="flex h-14 shrink-0 items-center gap-3 bg-ink-900 px-4 text-white">
            <Link :href="route('dashboard')" class="flex items-center gap-1.5 rounded px-2 py-1.5 text-sm text-ink-200 hover:bg-white/10"><ArrowLeft class="h-4 w-4" />{{ t('Dashboard') }}</Link>
            <div class="h-6 w-px bg-white/15" />
            <div class="leading-tight">
                <div class="text-sm font-semibold">{{ localName(store) }}</div>
                <div class="text-[12px] text-ink-300"><template v-if="shift">{{ shift.register }} · {{ t('Shift') }} {{ shift.number }}<template v-if="shift.joined"> · {{ t('opened by :name', { name: shift.opener }) }}</template></template><template v-else>{{ t('No shift open') }}</template> · {{ page.props.auth.user.name }}</div>
            </div>
            <div v-if="shift" class="ms-auto flex items-center gap-1 text-sm">
                <button v-if="can.hold" class="flex items-center gap-1.5 rounded px-2.5 py-1.5 hover:bg-white/10" @click="showHeld"><PauseCircle class="h-4 w-4" />{{ t('Held carts') }}</button>
                <button class="flex items-center gap-1.5 rounded px-2.5 py-1.5 hover:bg-white/10" @click="showRecent"><Receipt class="h-4 w-4" />{{ t('Receipts & returns') }}</button>
                <button v-if="can.payout || can.drop" class="flex items-center gap-1.5 rounded px-2.5 py-1.5 hover:bg-white/10" @click="modal = 'cash'"><Banknote class="h-4 w-4" />{{ t('Cash in/out') }}</button>
                <button class="flex items-center gap-1.5 rounded px-2.5 py-1.5 hover:bg-white/10" @click="showX"><ClipboardList class="h-4 w-4" />{{ t('X report') }}</button>
                <button v-if="can.close" class="flex items-center gap-1.5 rounded px-2.5 py-1.5 hover:bg-white/10" @click="modal = 'close'; zResult = null"><Lock class="h-4 w-4" />{{ t('Close shift') }}</button>
            </div>
            <div class="ms-2 text-sm tabular-nums text-ink-300">{{ clock.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Karachi' }) }}</div>
        </header>

        <!-- Cart tabs: one per customer -->
        <nav v-if="shift" class="flex shrink-0 items-end gap-1 overflow-x-auto bg-ink-800 px-3 pt-2" role="tablist" :aria-label="t('Open carts')">
            <div
                v-for="(c, i) in carts"
                :key="c.id"
                class="flex max-w-[17rem] shrink-0 items-center rounded-t-lg text-sm"
                :class="c.id === activeId ? 'bg-white text-ink-900' : 'bg-white/5 text-ink-200 hover:bg-white/10'"
            >
                <button type="button" role="tab" :aria-selected="c.id === activeId" class="flex min-w-0 items-center gap-2 py-2 ps-3 pe-2" :title="`Alt+${i + 1}`" @click="switchTab(c.id)">
                    <UserRound class="h-4 w-4 shrink-0" />
                    <span class="truncate font-medium">{{ tabLabel(c) }}</span>
                    <span v-if="c.lines.length" class="shrink-0 rounded-full px-1.5 text-[12px] tabular-nums" :class="c.id === activeId ? 'bg-ink-900/10' : 'bg-white/15'">{{ c.lines.length }}</span>
                    <span v-if="c.lines.length && c.quote" class="shrink-0 text-[12.5px] tabular-nums opacity-80">{{ money(c.quote.total) }}</span>
                </button>
                <button v-if="carts.length > 1" type="button" class="me-1.5 rounded p-1 opacity-60 hover:bg-black/10 hover:opacity-100" :aria-label="t('Close cart')" :title="t('Close cart')" @click="closeTab(c.id)"><X class="h-3.5 w-3.5" /></button>
                <span v-else class="pe-2" />
            </div>
            <button type="button" class="mb-1 ms-1 flex shrink-0 items-center gap-1 rounded px-2.5 py-1.5 text-sm text-ink-200 hover:bg-white/10" :title="`${t('New cart')} (F3)`" @click="addTab"><Plus class="h-4 w-4" />{{ t('New cart') }}</button>
        </nav>

        <!-- Open shift first -->
        <main v-if="!shift" class="flex flex-1 items-start justify-center overflow-auto p-6">
            <div class="panel w-full max-w-xl p-6">
                <h1>{{ joining ? t('Join open shift') : t('Open shift') }}</h1>
                <p class="mt-1 text-sm text-ink-500">{{ joining ? t(':name opened this counter. You will sell on the same shift and the same cash drawer, and every sale still shows your name.', { name: pickedRegister?.busy_with }) : t('Count the cash in the drawer before the first sale. This is your opening cash.') }}</p>
                <label class="mt-5 block">
                    <span class="field-label">{{ t('POS counter') }}</span>
                    <select v-model="openForm.register_id" class="input">
                        <option v-for="r in registers" :key="r.id" :value="r.id" :disabled="!!r.busy_with && !can.join">{{ r.name }}{{ r.busy_with ? ` — ${t('in use by')} ${r.busy_with}` : '' }}</option>
                    </select>
                </label>
                <div v-if="!joining" class="mt-5"><DenominationCount v-model="openForm.denominations" :denominations="settings.denominations" /></div>
                <Button variant="primary" size="lg" class="mt-6 w-full" :loading="busy" @click="joining ? joinShift() : openShift()">{{ joining ? t('Join shift') : t('Open shift') }}</Button>
            </div>
        </main>

        <!-- Selling -->
        <main v-else class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_400px]">
            <section class="flex min-h-0 flex-col">
                <div class="relative border-b border-ink-100 bg-white p-3">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <ScanLine class="pointer-events-none absolute start-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-400" />
                            <input
                                ref="searchInput"
                                v-model="search"
                                class="input h-12 ps-11 text-lg"
                                :placeholder="t('Scan a barcode or type a product name (F2)')"
                                autocomplete="off"
                                @keydown.enter.prevent="onSearchEnter"
                                @keydown.esc="search = ''; results = []"
                            />
                        </div>
                        <Button size="lg" :title="t('Scan with camera')" @click="startCamera"><Camera class="h-5 w-5" /></Button>
                        <Button size="lg" :title="t('Sell an item that is not in the product list')" @click="openUnlisted(search)"><PackagePlus class="h-5 w-5" /><span class="hidden xl:inline">{{ t('Item not in list') }}</span></Button>
                    </div>
                    <ul v-if="results.length" class="absolute inset-x-3 top-full z-20 mt-1 max-h-[55vh] overflow-auto rounded-lg border border-ink-100 bg-white py-1 shadow-pop">
                        <li v-for="p in results" :key="p.variant_id">
                            <button class="flex w-full items-center gap-4 px-4 py-2.5 text-start hover:bg-haldi-50" @click="add(p); search = ''; results = []">
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium">{{ p.name }}</div>
                                    <div class="flex gap-3 text-[12.5px] text-ink-500"><span>{{ p.sku }}</span><span v-if="p.name_ur" class="urdu" dir="rtl">{{ p.name_ur }}</span></div>
                                </div>
                                <div class="text-end">
                                    <div class="font-semibold tabular-nums">{{ money(p.units.find((u: any) => u.is_default)?.price ?? p.units[0]?.price) }}</div>
                                    <div class="text-[12.5px] tabular-nums" :class="Number(p.available) > 0 ? 'text-ink-500' : 'text-ledger-red'">{{ qty(p.available) }} {{ t('in stock') }}</div>
                                </div>
                            </button>
                        </li>
                        <li class="border-t border-ink-100"><button class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50" @mousedown.prevent="openUnlisted(search)"><PackagePlus class="h-4 w-4" />{{ t('Not here? Sell “:name” as an item not in the list', { name: search }) }}</button></li>
                    </ul>
                </div>

                <div class="min-h-0 flex-1 overflow-auto bg-white">
                    <table v-if="lines.length" class="table-base">
                        <thead class="sticky top-0 z-10">
                            <tr><th class="w-8">#</th><th>{{ t('Item') }}</th><th class="w-32">{{ t('Pack') }}</th><th class="w-40 text-center">{{ t('Quantity') }}</th><th class="num">{{ t('Price') }}</th><th class="num">{{ t('Discount') }}</th><th class="num">{{ t('Amount') }}</th><th class="w-10"></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in lines" :key="l.key" class="cursor-pointer" :class="selected === i ? '[&>td]:bg-haldi-50' : ''" @click="selected = i">
                                <td class="tabular-nums text-ink-400">{{ i + 1 }}</td>
                                <td>
                                    <div class="font-medium leading-snug">{{ l.name }}</div>
                                    <div class="flex gap-3 text-[12px] text-ink-500"><span>{{ l.sku }}</span><span v-if="l.name_ur" class="urdu" dir="rtl">{{ l.name_ur }}</span></div>
                                </td>
                                <td>
                                    <select v-if="l.units.length > 1" v-model="l.variant_unit_id" class="input py-1 text-sm" @click.stop>
                                        <option v-for="u in l.units" :key="u.id" :value="u.id">{{ u.name }} ({{ qty(u.factor) }})</option>
                                    </select>
                                    <span v-else class="text-sm text-ink-600">{{ units(l)?.name }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-center gap-1">
                                        <button class="rounded border border-ink-200 p-1 hover:bg-ink-50" :aria-label="t('Less')" @click.stop="changeQty(i, -1)"><Minus class="h-4 w-4" /></button>
                                        <input v-model="l.quantity" type="number" min="0" :step="units(l)?.allows_fraction ? '0.001' : '1'" class="input w-16 px-1 py-1 text-center tabular-nums" @click.stop />
                                        <button class="rounded border border-ink-200 p-1 hover:bg-ink-50" :aria-label="t('More')" @click.stop="changeQty(i, 1)"><Plus class="h-4 w-4" /></button>
                                    </div>
                                </td>
                                <td class="num">
                                    <button class="tabular-nums underline decoration-dotted decoration-ink-300 underline-offset-4 hover:decoration-haldi-500" :class="l.unit_price ? 'font-semibold text-haldi-700' : ''" :title="t('Change price')" @click.stop="editPrice(i)">
                                        {{ money(l.unit_price ?? listPrice(l, i)) }}
                                    </button>
                                </td>
                                <td class="num">
                                    <button v-if="can.discount" class="tabular-nums text-ink-600 underline decoration-dotted decoration-ink-300 underline-offset-4" @click.stop="editDiscount(i)">
                                        {{ l.discount ? (l.discount.type === 'percent' ? `${l.discount.value}%` : money(l.discount.value)) : '—' }}
                                    </button>
                                    <span v-else>—</span>
                                </td>
                                <td class="num font-semibold">{{ lineTotal(i) !== null ? money(lineTotal(i)) : '…' }}</td>
                                <td><button class="rounded p-1 text-ink-400 hover:bg-ledger-redSoft hover:text-ledger-red" :aria-label="t('Remove')" @click.stop="removeLine(i)"><Trash2 class="h-4 w-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-10 text-center text-ink-500">
                        <ScanLine class="h-12 w-12 text-ink-200" />
                        <p class="text-base">{{ t('Scan the first item to start a sale.') }}</p>
                        <p class="text-sm">{{ t('F2 search · F3 new cart · F4 customer · F6 bill discount · F7/F8 switch cart · F9 pay') }}</p>
                    </div>
                </div>
            </section>

            <!-- Totals -->
            <aside class="flex min-h-0 flex-col bg-ink-800 text-white">
                <div class="space-y-2 border-b border-white/10 p-4">
                    <div class="flex items-center gap-2 text-[13px] text-ink-200"><UserRound class="h-4 w-4" />{{ t('Customer') }} <span class="text-ink-400">(F4)</span></div>
                    <ContactPicker ref="customerPicker" v-model="customer" type="customer" dark :placeholder="t('Walk-in customer — search to add Khata')" :create-route="can.add_customer === false ? undefined : 'pos.customers.store'" @dialog="onPickerDialog" />
                    <div v-if="customer && customer.credit_limit !== undefined" class="flex justify-between text-[13px] text-ink-200">
                        <span>{{ t('Khata limit') }} {{ money(customer.credit_limit) }}</span>
                        <Badge v-if="customer.credit_status && customer.credit_status !== 'active'" :status="customer.credit_status" />
                    </div>
                </div>

                <dl class="flex-1 space-y-2 overflow-auto p-4 text-[15px]">
                    <div class="flex justify-between text-ink-200"><dt>{{ t('Items') }}</dt><dd class="tabular-nums">{{ lines.length }} · {{ qty(itemCount) }}</dd></div>
                    <div class="flex justify-between text-ink-200"><dt>{{ t('Subtotal') }}</dt><dd class="tabular-nums">{{ money(quote?.subtotal ?? 0) }}</dd></div>
                    <div class="flex justify-between text-ink-200">
                        <dt>
                            <button v-if="can.discount" class="inline-flex items-center gap-1 underline decoration-dotted underline-offset-4" @click="editDiscount(null)"><Percent class="h-3.5 w-3.5" />{{ t('Discount') }} (F6)</button>
                            <span v-else>{{ t('Discount') }}</span>
                        </dt>
                        <dd class="tabular-nums">−{{ money(quote?.discount_total ?? 0) }}</dd>
                    </div>
                    <div v-if="quote && Number(quote.tax_total) > 0" class="flex justify-between text-ink-300"><dt>{{ t('Sales tax (included)') }}</dt><dd class="tabular-nums">{{ money(quote.tax_total) }}</dd></div>
                    <div v-if="quote && Number(quote.rounding_adjustment) !== 0" class="flex justify-between text-ink-300"><dt>{{ t('Rounding') }}</dt><dd class="tabular-nums">{{ money(quote.rounding_adjustment) }}</dd></div>
                    <p v-if="quoteError" class="rounded bg-ledger-red/25 px-3 py-2 text-sm text-[#FFC9C1]">{{ quoteError }}</p>
                </dl>

                <div class="border-t border-white/10 p-4">
                    <div class="flex items-end justify-between">
                        <span class="pb-1 text-ink-200">{{ t('Total') }}</span>
                        <span class="text-[3.25rem] font-semibold leading-none tracking-tight tabular-nums text-haldi-400">{{ money(total) }}</span>
                    </div>
                    <div class="mt-4 grid grid-cols-[1fr_3fr] gap-2">
                        <Button variant="ink" size="lg" class="!bg-white/10 hover:!bg-ledger-red" :title="t('Clear cart')" :disabled="!lines.length" @click="clearCart"><X class="h-5 w-5" /></Button>
                        <Button variant="primary" size="lg" class="h-14 text-lg" :disabled="!lines.length || !!quoteError" @click="openPay">{{ t('Pay') }} <kbd class="rounded bg-ink-900/10 px-1.5 text-[12px]">F9</kbd></Button>
                    </div>
                </div>
            </aside>
        </main>
    </div>

    <!-- Receipt for printing (only visible on paper) -->
    <div v-if="receipt" class="hidden print:block"><ReceiptView :receipt="receipt" :language="receiptLang" /></div>
    <div v-else-if="zResult || xReport" class="hidden print:block p-4"><ShiftReportView :report="zResult?.report ?? xReport" :kind="zResult ? 'Z' : 'X'" :variance="zResult?.variance" /></div>

    <PaymentDialog :open="modal === 'pay'" :total="total" :methods="paymentMethods" :customer="customer" :can-credit="can.credit" :busy="busy" :error="payError" @close="modal = null; focusSearch()" @pay="pay" />

    <Modal :open="modal === 'receipt'" :title="receiptKind === 'sale' ? t('Sale complete') : t('Receipt')" size="md" @close="closeReceipt">
        <div v-if="receipt" class="flex flex-col items-center gap-4">
            <div v-if="receiptKind === 'sale' && receipt.payments?.some((p: any) => Number(p.change) > 0)" class="w-full rounded-lg bg-ledger-greenSoft px-4 py-3 text-center">
                <div class="text-sm text-ledger-green">{{ t('Change to give') }}</div>
                <div class="text-4xl font-semibold tabular-nums text-ledger-green">{{ money(receipt.payments.reduce((s: number, p: any) => s + Number(p.change || 0), 0)) }}</div>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-ink-500">{{ t('Receipt language') }}</span>
                <div class="flex rounded border border-ink-200 p-0.5" role="group">
                    <button v-for="[key, label] in [['en', 'English only'], ['ur', 'Urdu only'], ['both', 'Both']]" :key="key" type="button" class="rounded px-2.5 py-1" :class="receiptLang === key ? 'bg-ink-800 text-white' : 'hover:bg-ink-50'" @click="setReceiptLang(key as any)">{{ t(label) }}</button>
                </div>
            </div>
            <div class="max-h-[50vh] overflow-auto rounded border border-ink-100 p-3"><ReceiptView :receipt="receipt" :language="receiptLang" /></div>
        </div>
        <template #footer>
            <Button @click="printReceipt"><Printer class="h-4 w-4" />{{ t('Print receipt') }}</Button>
            <Button variant="primary" size="lg" @click="closeReceipt">{{ receiptKind === 'sale' ? t('New sale') : t('Done') }} <kbd class="rounded bg-ink-900/10 px-1.5 text-[11px]">Enter</kbd></Button>
        </template>
    </Modal>

 <Modal :open="modal === 'unlisted'" :title="t('Sell an item that is not in the product list')" size="md" @close="modal = null; focusSearch()">
        <form class="space-y-3" @submit.prevent="addUnlisted">
            <p class="text-[13px] text-ink-500">{{ t('The sale goes through now. The item is added to Inventory → Products to create, so it can be set up properly later.') }}</p>
            <label class="block"><span class="field-label">{{ t('Item name') }} *</span><input v-model="unlistedForm.name" class="input" maxlength="120" autofocus required /></label>
            <div class="grid grid-cols-3 gap-3">
                <label class="block"><span class="field-label">{{ t('Price') }} *</span><input v-model="unlistedForm.price" type="number" step="0.01" min="0.01" class="input text-end tabular-nums" required /></label>
                <label class="block"><span class="field-label">{{ t('Quantity') }}</span><input v-model="unlistedForm.quantity" type="number" step="any" min="0.001" class="input text-end tabular-nums" /></label>
                <label class="block"><span class="field-label">{{ t('Barcode') }}</span><input v-model="unlistedForm.barcode" class="input" maxlength="64" /></label>
            </div>
            <div class="flex justify-end gap-2"><Button @click="modal = null">{{ t('Cancel') }}</Button><Button type="submit" variant="primary">{{ t('Add to cart') }}</Button></div>
        </form>
    </Modal>

    <Modal :open="modal === 'price'" :title="t('Change price')" size="sm" @close="modal = null">
        <form v-if="priceEdit" class="space-y-3" @submit.prevent="savePrice">
            <p class="text-sm text-ink-600">{{ lines[priceEdit.index]?.name }}</p>
            <input v-model="priceEdit.price" type="number" step="0.01" min="0" class="input text-end text-2xl tabular-nums" autofocus />
            <p v-if="!can.override_price" class="text-[13px] text-ink-500">{{ t('A manager will approve this price with their PIN.') }}</p>
            <div class="flex justify-end gap-2">
                <Button v-if="lines[priceEdit.index]?.unit_price" @click="lines[priceEdit.index].unit_price = null; lines[priceEdit.index].price_token = null; modal = null">{{ t('Use list price') }}</Button>
                <Button type="submit" variant="primary">{{ t('Apply') }}</Button>
            </div>
        </form>
    </Modal>

    <Modal :open="modal === 'discount'" :title="discountEdit.index === null ? t('Discount on the whole bill') : t('Discount on this item')" size="sm" @close="modal = null">
        <form class="space-y-3" @submit.prevent="saveDiscount">
            <div class="flex rounded border border-ink-200 p-0.5 text-sm">
                <button type="button" class="flex-1 rounded py-1.5" :class="discountEdit.type === 'percent' ? 'bg-ink-800 text-white' : ''" @click="discountEdit.type = 'percent'">%</button>
                <button type="button" class="flex-1 rounded py-1.5" :class="discountEdit.type === 'amount' ? 'bg-ink-800 text-white' : ''" @click="discountEdit.type = 'amount'">Rs</button>
            </div>
            <input v-model="discountEdit.value" type="number" step="0.01" min="0" class="input text-end text-2xl tabular-nums" autofocus />
            <p class="text-[13px] text-ink-500">{{ t('Above :pct% needs a manager’s PIN.', { pct: settings.max_discount_percent }) }}</p>
            <div class="flex justify-end gap-2"><Button type="submit" variant="primary">{{ t('Apply') }}</Button></div>
        </form>
    </Modal>

    <Modal :open="modal === 'held'" :title="t('Held carts')" size="lg" @close="modal = null">
        <p v-if="!heldCarts.length" class="py-6 text-center text-sm text-ink-500">{{ t('No carts are on hold.') }}</p>
        <ul v-else class="divide-y divide-ink-100">
            <li v-for="c in heldCarts" :key="c.id" class="flex items-center gap-3 py-2.5">
                <div class="flex-1"><div class="font-medium">{{ c.label }}</div><div class="text-[12.5px] text-ink-500">{{ c.user?.name }} · {{ dateTime(c.created_at) }}</div></div>
                <span class="tabular-nums">{{ money(c.estimated_total) }}</span>
                <Button size="sm" variant="primary" @click="resume(c.id); modal = null">{{ t('Resume') }}</Button>
                <Button size="sm" variant="ghost" @click="discard(c.id)">{{ t('Discard') }}</Button>
            </li>
        </ul>
    </Modal>

    <Modal :open="modal === 'recent'" :title="t('Receipts & returns')" size="lg" @close="modal = null">
        <form class="mb-3 flex gap-2" @submit.prevent="showRecent"><input v-model="recentQ" class="input" :placeholder="t('Receipt number or customer')" /><Button type="submit">{{ t('Search') }}</Button></form>
        <table class="table-base">
            <thead><tr><th>{{ t('Receipt') }}</th><th>{{ t('Customer') }}</th><th>{{ t('Time') }}</th><th class="num">{{ t('Total') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="r in recent" :key="r.id">
                    <td class="font-medium">{{ r.number }}</td><td>{{ r.customer_name ?? t('Walk-in') }}</td><td>{{ dateTime(r.posted_at) }}</td><td class="num">{{ money(r.total) }}</td>
                    <td class="flex justify-end gap-1">
                        <Button size="sm" @click="reprint(r.id)"><Printer class="h-4 w-4" />{{ t('Reprint') }}</Button>
                        <Button v-if="can.refund" size="sm" :href="route('sales.invoices.show', r.id)">{{ t('Return items') }}</Button>
                    </td>
                </tr>
            </tbody>
        </table>
    </Modal>

    <Modal :open="modal === 'cash'" :title="t('Cash in / out of the drawer')" size="md" @close="modal = null">
        <form class="space-y-3" @submit.prevent="saveCash">
            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <button v-for="[k, label] in [['payout', 'Payout'], ['drop', 'Cash drop'], ['pay_in', 'Pay in'], ['no_sale', 'Open drawer']]" :key="k" type="button" class="rounded border px-2 py-2" :class="cashForm.type === k ? 'border-ink-800 bg-ink-50 font-medium' : 'border-ink-200'" @click="cashForm.type = k">{{ t(label) }}</button>
            </div>
            <label v-if="cashForm.type !== 'no_sale'" class="block"><span class="field-label">{{ t('Amount') }}</span><input v-model="cashForm.amount" type="number" step="1" min="0" class="input text-end text-lg tabular-nums" /></label>
            <label v-if="['payout', 'no_sale'].includes(cashForm.type)" class="block">
                <span class="field-label">{{ t('Reason') }}</span>
                <select v-model="cashForm.reason_code_id" class="input"><option :value="null">—</option><option v-for="r in reasons[cashForm.type] ?? []" :key="r.id" :value="r.id">{{ localName(r) }}</option></select>
            </label>
            <label v-if="cashForm.type === 'payout'" class="block"><span class="field-label">{{ t('Paid to') }}</span><input v-model="cashForm.recipient" class="input" /></label>
            <label class="block"><span class="field-label">{{ t('Note') }}</span><input v-model="cashForm.note" class="input" /></label>
            <div class="flex justify-end"><Button type="submit" variant="primary" :loading="busy">{{ t('Record') }}</Button></div>
        </form>
    </Modal>

    <Modal :open="modal === 'x'" :title="t('X report')" size="xl" @close="modal = null">
        <ShiftReportView v-if="xReport" :report="xReport" kind="X" />
        <template #footer><Button @click="printReceipt"><Printer class="h-4 w-4" />{{ t('Print') }}</Button></template>
    </Modal>

    <Modal :open="modal === 'close'" :title="t('Close shift')" size="xl" :dismissable="!zResult" @close="modal = null">
        <template v-if="!zResult">
            <p class="mb-4 text-sm text-ink-600">{{ settings.blind_close ? t('Count all the cash in the drawer. The expected amount is shown after you submit.') : t('Count all the cash in the drawer.') }}</p>
            <DenominationCount v-model="closeForm.denominations" :denominations="settings.denominations" />
            <label class="mt-4 block"><span class="field-label">{{ t('Note (optional)') }}</span><input v-model="closeForm.note" class="input" /></label>
        </template>
        <template v-else>
            <div class="mb-4 rounded-lg px-4 py-3" :class="Number(zResult.variance) === 0 ? 'bg-ledger-greenSoft text-ledger-green' : 'bg-haldi-50 text-haldi-900'">
                <div class="text-lg font-semibold">{{ Number(zResult.variance) === 0 ? t('Cash matches. Shift closed.') : t('Shift closed with a difference of :amount.', { amount: money(zResult.variance) }) }}</div>
                <div v-if="zResult.variance_status === 'pending_review'" class="text-sm">{{ t('A manager will review the difference. It is never deducted from pay automatically.') }}</div>
            </div>
            <ShiftReportView :report="zResult.report" kind="Z" :variance="zResult.variance" />
        </template>
        <template #footer>
            <template v-if="!zResult"><Button @click="modal = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="busy" @click="closeShift">{{ t('Close shift') }}</Button></template>
            <template v-else><Button @click="printReceipt"><Printer class="h-4 w-4" />{{ t('Print Z report') }}</Button><Button variant="ink" @click="router.visit(route('dashboard'))">{{ t('Done') }}</Button></template>
        </template>
    </Modal>

    <Modal :open="modal === 'camera'" :title="t('Scan with camera')" size="md" @close="stopCamera">
        <div id="camera-reader" class="overflow-hidden rounded" />
    </Modal>

    <PinApproval :open="!!approval" :action="approval?.action ?? null" :reason="approval?.reason" @approved="approved" @close="approvalCancelled" />
    <FlashToasts ref="toasts" />
</template>