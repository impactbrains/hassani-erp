<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Search } from 'lucide-vue-next';
import { get } from '@/lib/http';
import { money, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Search products by name (English or Urdu), SKU or barcode; Enter adds the first match. */
const props = withDefaults(defineProps<{ customerId?: number | null; storeId?: number | null; placeholder?: string; showStock?: boolean; purpose?: 'sale' | 'purchase' | 'any' }>(), { customerId: null, storeId: null, placeholder: undefined, showStock: true, purpose: 'sale' });
const emit = defineEmits<{ pick: [product: any] }>();

const q = ref('');
const results = ref<any[]>([]);
const open = ref(false);
const active = ref(0);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(q, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) { results.value = []; return; }
    timer = setTimeout(load, 200);
});
onBeforeUnmount(() => clearTimeout(timer));

async function load() {
    results.value = await get(route('lookup.products', { q: q.value, customer_id: props.customerId, store_id: props.storeId, purpose: props.purpose, limit: 15 }));
    active.value = 0;
    open.value = true;
}
function pick(p: any) {
    emit('pick', p);
    q.value = '';
    results.value = [];
    open.value = false;
}
async function onEnter() {
    if (!results.value.length && q.value.trim()) await load();
    if (results.value[active.value]) pick(results.value[active.value]);
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { active.value = Math.min(active.value + 1, results.value.length - 1); e.preventDefault(); }
    if (e.key === 'ArrowUp') { active.value = Math.max(active.value - 1, 0); e.preventDefault(); }
    if (e.key === 'Escape') open.value = false;
}
function closeSoon() {
    setTimeout(() => (open.value = false), 150);
}
</script>

<template>
    <div class="relative">
        <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
        <input v-model="q" type="search" class="input ps-9" :placeholder="placeholder ?? t('Add a product: scan, or type a name or SKU')" @keydown.enter.prevent="onEnter" @keydown="onKey" @blur="closeSoon" @focus="open = results.length > 0" />
        <ul v-if="open && results.length" class="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-lg border border-ink-100 bg-white py-1 shadow-pop">
            <li v-for="(p, i) in results" :key="p.variant_id">
                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-start text-sm" :class="i === active ? 'bg-haldi-50' : 'hover:bg-ink-50'" @mousedown.prevent="pick(p)">
                    <span class="min-w-0 flex-1"><span class="font-medium">{{ p.name }}</span> <span class="text-[12px] text-ink-400">{{ p.sku }}</span><span v-if="p.name_ur" class="urdu block text-[12.5px] text-ink-500" dir="rtl">{{ p.name_ur }}</span></span>
                    <span class="text-end text-[12.5px] tabular-nums text-ink-600">{{ money(p.units.find((u: any) => u.is_default)?.price ?? p.units[0]?.price) }}<span v-if="showStock" class="block" :class="Number(p.available) > 0 ? 'text-ink-400' : 'text-ledger-red'">{{ qty(p.available) }} {{ t('in stock') }}</span></span>
                </button>
            </li>
        </ul>
    </div>
</template>
