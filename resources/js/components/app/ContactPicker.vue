<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Plus, Search, X } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import { ApiError, get, post } from '@/lib/http';
import { localName, t } from '@/lib/i18n';

/**
 * Search customers, suppliers or employees by name, code or phone.
 * Pass `create-route` (a route name that returns JSON) to also offer "+ Add a customer" (or "+ Add a supplier").
 */
const props = withDefaults(defineProps<{ type: 'customer' | 'supplier' | 'employee'; modelValue: any | null; placeholder?: string; dark?: boolean; roleType?: string; createRoute?: string }>(), { placeholder: undefined, dark: false, roleType: undefined, createRoute: undefined });
const emit = defineEmits<{ 'update:modelValue': [any | null]; dialog: [boolean] }>();

const q = ref('');
const results = ref<any[]>([]);
const open = ref(false);
const active = ref(0);
const pending = ref(false);
const input = ref<HTMLInputElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

// The "+ Add a customer" row shows once something has been typed.
const showAdd = computed(() => !!props.createRoute && !!q.value.trim());
const isSupplier = computed(() => props.type === 'supplier');
const words = computed(() => isSupplier.value
    ? { add: 'Add a supplier', maybe: 'This supplier may already exist:', use: 'Use this supplier', anyway: 'Add as a new supplier anyway' }
    : { add: 'Add a customer', maybe: 'This customer may already exist:', use: 'Use this customer', anyway: 'Add as a new customer anyway' });

async function lookup(term: string): Promise<any[]> {
    return get(route('lookup.contacts', { type: props.type, q: term, role_type: props.roleType }));
}

watch(q, (value) => {
    clearTimeout(timer);
    if (!value.trim()) { results.value = []; pending.value = false; return; }
    pending.value = true;
    timer = setTimeout(async () => {
        try {
            results.value = await lookup(value);
            active.value = 0;
            open.value = true;
        } finally {
            pending.value = false;
        }
    }, 200);
});
onBeforeUnmount(() => clearTimeout(timer));

function choose(item: any) {
    emit('update:modelValue', item);
    q.value = '';
    open.value = false;
}
function onKey(e: KeyboardEvent) {
    const last = results.value.length + (showAdd.value ? 1 : 0) - 1;
    if (e.key === 'ArrowDown') { active.value = Math.min(active.value + 1, Math.max(last, 0)); e.preventDefault(); }
    if (e.key === 'ArrowUp') { active.value = Math.max(active.value - 1, 0); e.preventDefault(); }
    if (e.key === 'Enter') {
        if (results.value[active.value]) { choose(results.value[active.value]); e.preventDefault(); }
        else if (showAdd.value && active.value === results.value.length && !pending.value) { startCreate(); e.preventDefault(); }
    }
    if (e.key === 'Escape') open.value = false;
}
function closeSoon() {
    setTimeout(() => (open.value = false), 150);
}

// ---------- add a customer ----------
const dialogOpen = ref(false);
const form = ref({ name: '', phone: '', area: '' });
const saving = ref(false);
const formError = ref<string | null>(null);
const matches = ref<any[]>([]);
watch(dialogOpen, (value) => emit('dialog', value));

function startCreate() {
    const term = q.value.trim();
    const looksLikePhone = /^[\d+\s()-]{7,}$/.test(term);
    form.value = { name: looksLikePhone ? '' : term, phone: looksLikePhone ? term : '', area: '' };
    matches.value = [];
    formError.value = null;
    open.value = false;
    dialogOpen.value = true;
}
async function save(confirmNotDuplicate: boolean) {
    if (!form.value.name.trim() || !props.createRoute) return;
    saving.value = true;
    formError.value = null;
    try {
        const created = await post(route(props.createRoute), { ...form.value, confirm_not_duplicate: confirmNotDuplicate });
        await pick(created);
    } catch (e) {
        if (e instanceof ApiError && e.code === 'possible_duplicate') matches.value = e.details?.matches ?? [];
        else formError.value = (e as Error).message;
    } finally {
        saving.value = false;
    }
}
/** Select the new contact. The row comes from the same search the picker uses, so credit info matches. */
async function pick(contact: any) {
    saving.value = true;
    let chosen = contact;
    try {
        const found = await lookup(contact.code);
        chosen = found.find((r: any) => r.id === contact.id) ?? contact;
    } catch {
        /* fall back to the basic record we already have */
    } finally {
        saving.value = false;
    }
    dialogOpen.value = false;
    choose(chosen);
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
            <input ref="input" v-model="q" type="search" class="input ps-9" :class="dark ? '!border-white/15 !bg-white/5 !text-white placeholder:!text-ink-300' : ''" :placeholder="placeholder ?? t('Search by name, code or phone')" @keydown="onKey" @focus="open = results.length > 0 || showAdd" @blur="closeSoon" />
        </div>
        <ul v-if="open && (results.length || showAdd)" class="absolute z-30 mt-1 max-h-72 w-full min-w-[16rem] overflow-auto rounded-lg border border-ink-100 bg-white py-1 text-graphite shadow-pop">
            <li v-for="(r, i) in results" :key="r.id">
                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-start text-sm" :class="i === active ? 'bg-haldi-50' : 'hover:bg-ink-50'" @mousedown.prevent="choose(r)">
                    <span class="min-w-0 flex-1"><span class="font-medium">{{ localName(r) }}</span> <span class="text-[12px] text-ink-400">{{ r.code }}</span><span v-if="r.area" class="block text-[12px] text-ink-500">{{ r.area }}</span></span>
                    <span class="text-[12.5px] tabular-nums text-ink-500">{{ r.phone }}</span>
                </button>
            </li>
            <li v-if="showAdd" :class="results.length ? 'border-t border-ink-100' : ''">
                <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm font-medium text-haldi-700" :class="active === results.length ? 'bg-haldi-50' : 'hover:bg-ink-50'" @mousedown.prevent="startCreate">
                    <Plus class="h-4 w-4 shrink-0" /><span class="shrink-0 whitespace-nowrap">{{ t(words.add) }}</span> <span class="min-w-0 truncate font-normal text-ink-500">“{{ q.trim() }}”</span>
                </button>
            </li>
        </ul>

        <Modal :open="dialogOpen" :title="t(words.add)" size="md" @close="dialogOpen = false">
            <form class="space-y-3" @submit.prevent="save(false)">
                <template v-if="!matches.length">
                    <label class="block"><span class="field-label">{{ t('Name') }} *</span><input v-model="form.name" class="input" maxlength="200" autofocus required /></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="field-label">{{ t('Phone') }}</span><input v-model="form.phone" type="tel" class="input" maxlength="30" /></label>
                        <label class="block"><span class="field-label">{{ t('Area') }}</span><input v-model="form.area" class="input" maxlength="120" /></label>
                    </div>
                    <p v-if="formError" class="rounded bg-ledger-redSoft px-3 py-2 text-sm text-ledger-red">{{ formError }}</p>
                    <div class="flex justify-end gap-2">
                        <Button type="button" @click="dialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" variant="primary" :loading="saving">{{ t('Add and use') }}</Button>
                    </div>
                </template>
                <template v-else>
                    <p class="text-sm text-ink-700">{{ t(words.maybe) }}</p>
                    <ul class="divide-y divide-ink-100 rounded border border-ink-100">
                        <li v-for="m in matches" :key="m.id" class="flex items-center gap-3 px-3 py-2">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ m.name }} <span class="text-[12px] text-ink-400">{{ m.code }}</span></div>
                                <div class="truncate text-[12.5px] text-ink-500">{{ [m.phone, m.area].filter(Boolean).join(', ') }}</div>
                            </div>
                            <Button type="button" size="sm" variant="primary" :loading="saving" @click="pick(m)">{{ t(words.use) }}</Button>
                        </li>
                    </ul>
                    <div class="flex justify-end gap-2">
                        <Button type="button" @click="matches = []">{{ t('Back') }}</Button>
                        <Button type="button" :loading="saving" @click="save(true)">{{ t(words.anyway) }}</Button>
                    </div>
                </template>
            </form>
        </Modal>
    </div>
</template>