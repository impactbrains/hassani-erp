<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { useFilters } from '@/composables/useFilters';
import { date, qty, today } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ batches: any; filters: Record<string, any>; canManage: boolean }>();
const filters = useFilters('inventory.batches', { q: props.filters.q ?? '', status: props.filters.status ?? '' });
const editing = ref<any>(null);
const form = useForm({ status: 'quarantined', reason: '' });
const now = today();
function daysLeft(d: string | null) {
    if (!d) return null;
    return Math.round((new Date(d).getTime() - new Date(now).getTime()) / 86400000);
}
function open(b: any) {
    editing.value = b;
    form.status = b.status === 'available' ? 'quarantined' : 'available';
    form.reason = '';
}
function save() {
    form.post(route('inventory.batches.status', editing.value.id), { onSuccess: () => (editing.value = null), preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('Batches and expiry')" :subtitle="t('Sorted by expiry date: sell the earliest first. A quarantined or recalled batch cannot be sold.')" />
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Product name or SKU')" />
        <select v-model="filters.status" class="input max-w-[12rem]"><option value="">{{ t('Any status') }}</option><option value="available">{{ t('available') }}</option><option value="quarantined">{{ t('quarantined') }}</option><option value="recalled">{{ t('recalled') }}</option></select>
    </div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Product') }}</th><th>{{ t('Batch') }}</th><th>{{ t('Expiry') }}</th><th class="num">{{ t('Days left') }}</th><th class="num">{{ t('On hand') }}</th><th>{{ t('Supplier') }}</th><th>{{ t('Status') }}</th><th v-if="canManage"></th></tr></thead>
            <tbody>
                <tr v-for="b in batches.data" :key="b.id">
                    <td><div class="font-medium">{{ b.variant?.display_name }}</div><div class="text-[12px] text-ink-500">{{ b.variant?.sku }}</div></td>
                    <td>{{ b.batch_no }}</td>
                    <td>{{ b.expiry_date ? date(b.expiry_date) : '—' }}</td>
                    <td class="num" :class="(daysLeft(b.expiry_date) ?? 999) < 0 ? 'font-semibold text-ledger-red' : (daysLeft(b.expiry_date) ?? 999) <= 30 ? 'text-haldi-800' : ''">{{ daysLeft(b.expiry_date) ?? '—' }}</td>
                    <td class="num">{{ qty(b.on_hand ?? 0) }}</td>
                    <td>{{ b.supplier?.name ?? '—' }}</td>
                    <td><Badge :status="b.status" /><div v-if="b.status_reason" class="text-[12px] text-ink-500">{{ b.status_reason }}</div></td>
                    <td v-if="canManage"><Button size="sm" @click="open(b)">{{ t('Change') }}</Button></td>
                </tr>
                <tr v-if="!batches.data.length"><td colspan="8" class="py-8 text-center text-ink-500">{{ t('No batches. Batches are created when stock with a batch number or expiry date is received.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="batches" />
    </section>
    <Modal :open="!!editing" :title="`${t('Batch')} ${editing?.batch_no ?? ''}`" size="sm" @close="editing = null">
        <div class="space-y-3">
            <Field label="Status"><select v-model="form.status" class="input"><option value="available">{{ t('Available for sale') }}</option><option value="quarantined">{{ t('Quarantined (hold)') }}</option><option value="recalled">{{ t('Recalled') }}</option></select></Field>
            <Field label="Reason" :error="form.errors.reason" required><input v-model="form.reason" class="input" /></Field>
        </div>
        <template #footer><Button @click="editing = null">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!form.reason" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
