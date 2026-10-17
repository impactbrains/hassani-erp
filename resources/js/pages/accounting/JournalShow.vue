<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { date, dateTime, money, today } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ journal: any; source: { type: string; id: number } | null; canReverse: boolean }>();
const reversing = ref(false);
const form = useForm({ date: today(), reason: '' });
const routes: Record<string, string> = { SalesInvoice: 'sales.invoices.show', SalesOrder: 'sales.orders.show', GoodsReceipt: 'purchasing.receipts.show', SupplierBill: 'purchasing.bills.show', Shift: 'pos.shifts.show', StockAdjustment: 'inventory.adjustments.show', StockTransfer: 'inventory.transfers.show', StockCount: 'inventory.counts.show', PayrollRun: 'hr.payroll.show' };
const sourceHref = computed(() => (props.source && routes[props.source.type] ? route(routes[props.source.type], props.source.id) : null));
const dr = computed(() => props.journal.lines.reduce((s: number, l: any) => s + Number(l.debit), 0));
const cr = computed(() => props.journal.lines.reduce((s: number, l: any) => s + Number(l.credit), 0));
</script>

<template>
    <PageHeader :title="`${t('Journal')} ${journal.number}`" :subtitle="`${date(journal.entry_date)} · ${journal.description}`">
        <template #actions>
            <Badge :status="journal.status" />
            <Button v-if="sourceHref" :href="sourceHref">{{ t('Open the document') }}</Button>
            <Button v-if="canReverse && journal.kind !== 'auto' && !journal.reversal" variant="danger" @click="reversing = true">{{ t('Reverse') }}</Button>
        </template>
    </PageHeader>
    <section class="panel mb-4 grid gap-4 p-4 text-sm md:grid-cols-4">
        <div><span class="field-label">{{ t('Type') }}</span>{{ journal.kind }} <span v-if="journal.purpose" class="text-ink-500">· {{ journal.purpose }}</span></div>
        <div><span class="field-label">{{ t('Store') }}</span>{{ journal.store?.name ?? t('Whole business') }}</div>
        <div><span class="field-label">{{ t('Posted by') }}</span>{{ journal.creator?.name ?? t('System') }} · {{ dateTime(journal.posted_at ?? journal.created_at) }}</div>
        <div>
            <span class="field-label">{{ t('Linked') }}</span>
            <span v-if="journal.reversal_of">{{ t('Reverses') }} <a :href="route('accounting.journals.show', journal.reversal_of.id)" class="link">{{ journal.reversal_of.number }}</a></span>
            <span v-else-if="journal.reversal">{{ t('Reversed by') }} <a :href="route('accounting.journals.show', journal.reversal.id)" class="link">{{ journal.reversal.number }}</a></span>
            <span v-else>—</span>
        </div>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Account') }}</th><th>{{ t('Party') }}</th><th>{{ t('Memo') }}</th><th class="num">{{ t('Debit') }}</th><th class="num">{{ t('Credit') }}</th></tr></thead>
            <tbody>
                <tr v-for="l in journal.lines" :key="l.id"><td><a :href="route('accounting.accounts.show', l.account_id)" class="link">{{ l.account?.code }} {{ l.account?.name }}</a></td><td>{{ l.contact?.name ?? '' }}</td><td class="text-ink-600">{{ l.memo }}</td><td class="num">{{ Number(l.debit) ? money(l.debit) : '' }}</td><td class="num">{{ Number(l.credit) ? money(l.credit) : '' }}</td></tr>
            </tbody>
            <tfoot><tr><td colspan="3" class="px-3 py-2 text-end font-semibold">{{ t('Total') }}</td><td class="num px-3 py-2 font-semibold">{{ money(dr) }}</td><td class="num px-3 py-2 font-semibold">{{ money(cr) }}</td></tr></tfoot>
        </table>
    </section>
    <Modal :open="reversing" :title="t('Reverse this entry')" size="sm" @close="reversing = false">
        <div class="space-y-3">
            <p class="text-sm text-ink-600">{{ t('A new entry with the opposite amounts is posted. The original stays for the record.') }}</p>
            <Field label="Date of reversal" :error="form.errors.date"><input v-model="form.date" type="date" class="input" /></Field>
            <Field label="Reason" :error="form.errors.reason" required><input v-model="form.reason" class="input" /></Field>
        </div>
        <template #footer><Button @click="reversing = false">{{ t('Cancel') }}</Button><Button variant="danger" :loading="form.processing" @click="form.post(route('accounting.journals.reverse', journal.id))">{{ t('Reverse') }}</Button></template>
    </Modal>
</template>
