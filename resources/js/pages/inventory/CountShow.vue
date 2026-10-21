<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { ApiError, post, api } from '@/lib/http';
import { dateTime, money, qty } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/**
 * Counting screen. Each quantity saves as soon as you leave the box, so
 * several people can count different aisles at the same time.
 */
const props = defineProps<{ pager?: any; count: any; hideExpected: boolean; needsSecondCount: number[]; can: { manage: boolean; approve: boolean } }>();
const options = useOptions(['reasons:adjustment', 'reasons:write_off']);
const reasonList = computed(() => [...(options['reasons:adjustment'] ?? []), ...(options['reasons:write_off'] ?? [])]);
const { here: locations } = useStoreLocations();
const status = computed(() => props.count.status);
const entries = reactive<Record<number, string>>({});
const saved = reactive<Record<number, 'ok' | 'error' | 'saving'>>({});
const reasons = reactive<Record<number, number | null>>({});
const filter = ref('');
const onlyOpen = ref(false);
const addLocation = ref<number | null>(null);
const error = ref('');
props.count.lines.forEach((l: any) => {
    const second = props.needsSecondCount.includes(l.id);
    entries[l.id] = second ? (l.second_count_quantity ?? '') : (l.counted_quantity ?? '');
    reasons[l.id] = l.reason_code_id ?? null;
});

const lines = computed(() => props.count.lines.filter((l: any) => {
    if (onlyOpen.value && l.counted_quantity !== null && !props.needsSecondCount.includes(l.id)) return false;
    const q = filter.value.trim().toLowerCase();
    return !q || `${l.variant?.display_name} ${l.variant?.sku} ${l.variant?.name_ur ?? ''}`.toLowerCase().includes(q);
}));
const progress = computed(() => {
    const total = props.count.lines.length;
    const done = props.count.lines.filter((l: any) => l.counted_quantity !== null).length;
    return { total, done };
});
const editable = (l: any) => status.value === 'in_progress' || (status.value === 'submitted' && props.needsSecondCount.includes(l.id));

async function record(l: any) {
    const v = entries[l.id];
    if (v === '' || v === null) return;
    saved[l.id] = 'saving';
    try {
        const res = await api('PUT', route('inventory.counts.record', l.id), { quantity: v, second: status.value === 'submitted' });
        l.counted_quantity = res.counted_quantity;
        l.second_count_quantity = res.second_count_quantity;
        saved[l.id] = 'ok';
    } catch (e) {
        saved[l.id] = 'error';
        error.value = (e as Error).message;
    }
}
function act(action: string) {
    router.post(route('inventory.counts.action', props.count.id), { action, reasons: action === 'approve' ? reasons : undefined }, { preserveScroll: true });
}
async function addLine(p: any) {
    if (!addLocation.value) { error.value = t('Choose the location first.'); return; }
    try {
        await post(route('inventory.counts.lines', props.count.id), { location_id: addLocation.value, variant_id: p.variant_id });
        router.reload({ only: ['count'] });
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : String(e);
    }
}
</script>

<template>
    <PageHeader :pager="pager" :title="`${t('Count')} ${count.number}`" :subtitle="`${count.store?.name} · ${count.assignee?.name ?? t('Anyone')} · ${count.is_blind ? t('Blind') : t('Open')}`">
        <template #actions>
            <Badge :status="status" />
            <Button v-if="status === 'draft' && can.manage" variant="primary" @click="act('start')">{{ t('Start counting') }}</Button>
            <Button v-if="status === 'in_progress'" variant="primary" :disabled="progress.done === 0" @click="act('submit')">{{ t('Submit count') }}</Button>
            <Button v-if="status === 'submitted' && can.approve" variant="primary" @click="act('approve')">{{ t('Approve and fix stock') }}</Button>
            <Button :href="route('inventory.counts.index')">{{ t('All counts') }}</Button>
        </template>
    </PageHeader>
    <p v-if="status === 'draft'" class="panel mb-4 p-4 text-sm text-ink-700">{{ t('Press “Start counting” when you are ready. The expected stock is noted at that moment; sales during the count are allowed for automatically.') }}</p>
    <p v-if="status === 'submitted' && needsSecondCount.length" class="panel mb-4 border-haldi-300 bg-haldi-50 p-4 text-sm">{{ t('Some lines have a big difference and need a second count before approval. They are highlighted.') }}</p>
    <p v-if="error" class="mb-4 rounded bg-ledger-redSoft px-3 py-2 text-sm text-ledger-red">{{ error }}</p>

    <div class="panel mb-4 flex flex-wrap items-center gap-3 p-3">
        <input v-model="filter" type="search" class="input max-w-xs" :placeholder="t('Find a product')" />
        <label class="flex items-center gap-2 text-sm"><input v-model="onlyOpen" type="checkbox" class="rounded border-ink-300" />{{ t('Only lines not yet counted') }}</label>
        <span class="ms-auto text-sm text-ink-600">{{ t('Counted') }}: <b class="tabular-nums">{{ progress.done }} / {{ progress.total }}</b></span>
    </div>
    <div v-if="status === 'in_progress'" class="panel mb-4 flex flex-wrap items-center gap-3 p-3">
        <span class="text-sm text-ink-600">{{ t('Found something not on the list?') }}</span>
        <select v-model="addLocation" class="input max-w-[12rem] py-1.5"><option :value="null">{{ t('Location') }}</option><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
        <div class="min-w-[16rem] flex-1"><ProductPicker purpose="any" :show-stock="!hideExpected" @pick="addLine" /></div>
    </div>

    <section class="panel">
        <table class="table-base">
            <thead>
                <tr>
                    <th>{{ t('Product') }}</th><th>{{ t('Location') }}</th>
                    <th v-if="!hideExpected" class="num">{{ t('Expected') }}</th>
                    <th class="w-32">{{ status === 'submitted' ? t('Counted / recount') : t('Counted') }}</th>
                    <th v-if="!hideExpected && status !== 'in_progress'" class="num">{{ t('Difference') }}</th>
                    <th v-if="!hideExpected && status !== 'in_progress' && count.lines.some((l: any) => l.variance_value !== undefined)" class="num">{{ t('Value') }}</th>
                    <th v-if="status === 'submitted' && can.approve">{{ t('Reason') }}</th>
                    <th>{{ t('Counted by') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="l in lines" :key="l.id" :class="needsSecondCount.includes(l.id) ? 'bg-haldi-50' : ''">
                    <td><div class="font-medium">{{ l.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ l.variant?.sku }}<span v-if="l.batch"> · {{ l.batch.batch_no }}</span></div></td>
                    <td>{{ l.location?.name }}</td>
                    <td v-if="!hideExpected" class="num">{{ l.expected_quantity !== null ? qty(l.expected_quantity) : '—' }}</td>
                    <td>
                        <div v-if="editable(l)" class="flex items-center gap-1">
                            <input v-model="entries[l.id]" type="number" min="0" step="any" class="input py-1 text-end" @change="record(l)" @keydown.enter="($event.target as HTMLInputElement).blur()" />
                            <span class="w-3 text-[12px]" :class="saved[l.id] === 'error' ? 'text-ledger-red' : 'text-ledger-green'">{{ saved[l.id] === 'ok' ? '✓' : saved[l.id] === 'error' ? '!' : '' }}</span>
                        </div>
                        <span v-else class="tabular-nums">{{ l.counted_quantity !== null ? qty(l.counted_quantity) : '—' }}<span v-if="l.second_count_quantity !== null" class="text-ink-500"> / {{ qty(l.second_count_quantity) }}</span></span>
                    </td>
                    <td v-if="!hideExpected && status !== 'in_progress'" class="num" :class="Number(l.variance_quantity) < 0 ? 'text-ledger-red' : Number(l.variance_quantity) > 0 ? 'text-ledger-green' : ''">{{ l.variance_quantity !== null && l.variance_quantity !== undefined ? qty(l.variance_quantity) : '—' }}</td>
                    <td v-if="!hideExpected && status !== 'in_progress' && count.lines.some((x: any) => x.variance_value !== undefined)" class="num">{{ l.variance_value != null ? money(l.variance_value) : '—' }}</td>
                    <td v-if="status === 'submitted' && can.approve">
                        <select v-if="Number(l.variance_quantity)" v-model="reasons[l.id]" class="input py-1 text-sm"><option :value="null">—</option><option v-for="r in reasonList" :key="r.id" :value="r.id">{{ localName(r) }}</option></select>
                    </td>
                    <td class="text-[13px] text-ink-600">{{ l.counter?.name ?? '' }}<div v-if="l.counted_at" class="text-[11px]">{{ dateTime(l.counted_at) }}</div></td>
                </tr>
                <tr v-if="!lines.length"><td colspan="8" class="py-8 text-center text-ink-500">{{ status === 'draft' ? t('Lines appear when counting starts.') : t('Nothing to show.') }}</td></tr>
            </tbody>
        </table>
    </section>
</template>
