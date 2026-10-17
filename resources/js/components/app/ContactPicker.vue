<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Search, X } from 'lucide-vue-next';
import { get } from '@/lib/http';
import { localName, t } from '@/lib/i18n';

/** Search customers, suppliers or employees by name, code or phone. */
const props = withDefaults(defineProps<{ type: 'customer' | 'supplier' | 'employee'; modelValue: any | null; placeholder?: string; dark?: boolean; roleType?: string }>(), { placeholder: undefined, dark: false, roleType: undefined });
const emit = defineEmits<{ 'update:modelValue': [any | null] }>();

const q = ref('');
const results = ref<any[]>([]);
const open = ref(false);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(q, (value) => {
    clearTimeout(timer);
    if (!value.trim()) { results.value = []; return; }
    timer = setTimeout(async () => {
        results.value = await get(route('lookup.contacts', { type: props.type, q: value, role_type: props.roleType }));
        active.value = 0;
        open.value = true;
    }, 200);
});
onBeforeUnmount(() => clearTimeout(timer));

function choose(item: any) {
    emit('update:modelValue', item);
    q.value = '';
    open.value = false;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { active.value = Math.min(active.value + 1, results.value.length - 1); e.preventDefault(); }
    if (e.key === 'ArrowUp') { active.value = Math.max(active.value - 1, 0); e.preventDefault(); }
    if (e.key === 'Enter' && results.value[active.value]) { choose(results.value[active.value]); e.preventDefault(); }
    if (e.key === 'Escape') open.value = false;
}
function closeSoon() {
    setTimeout(() => (open.value = false), 150);
}
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <div v-if="modelValue" class="flex items-center gap-2 rounded border px-3 py-2" :class="dark ? 'border-white/15 bg-white/5 text-white' : 'border-ink-200 bg-white'">
            <div class="min-w-0 flex-1">
                <div class="truncate font-medium">{{ localName(modelValue) }} <span v-if="modelValue.code" class="text-[12px] opacity-60">{{ modelValue.code }}</span></div>
                <div v-if="modelValue.phone || modelValue.area" class="truncate text-[12.5px] opacity-70">{{ [modelValue.phone, modelValue.area].filter(Boolean).join(', ') }}</div>
            </div>
            <button type="button" class="rounded p-1 opacity-70 hover:opacity-100" :aria-label="t('Remove')" @click="emit('update:modelValue', null)"><X class="h-4 w-4" /></button>
        </div>
        <div v-else class="relative">
            <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 opacity-50" />
            <input ref="input" v-model="q" type="search" class="input ps-9" :class="dark ? '!border-white/15 !bg-white/5 !text-white placeholder:!text-ink-300' : ''" :placeholder="placeholder ?? t('Search by name, code or phone')" @keydown="onKey" @focus="open = results.length > 0" @blur="closeSoon" />
        </div>
        <ul v-if="open && results.length" class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-ink-100 bg-white py-1 text-graphite shadow-pop">
            <li v-for="(r, i) in results" :key="r.id">
                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-start text-sm" :class="i === active ? 'bg-haldi-50' : 'hover:bg-ink-50'" @mousedown.prevent="choose(r)">
                    <span class="min-w-0 flex-1"><span class="font-medium">{{ localName(r) }}</span> <span class="text-[12px] text-ink-400">{{ r.code }}</span><span v-if="r.area" class="block text-[12px] text-ink-500">{{ r.area }}</span></span>
                    <span class="text-[12.5px] tabular-nums text-ink-500">{{ r.phone }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
