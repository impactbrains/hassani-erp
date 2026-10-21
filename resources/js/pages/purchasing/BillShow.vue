<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { useOptions } from '@/composables/useOptions';
import { date, money, qty } from '@/lib/format';
import { uuid } from '@/lib/http';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; bill: any; openShift: number | null }>();
const options = useOptions(['payment_methods']);
const methods = computed(() => (options.payment_methods ?? []).filter((m: any) => ['cash', 'bank', 'wallet'].includes(m.type)));
const due = computed(() => Number(props.bill.total) - Number(props.bill.amount_paid) - Number(props.bill.amount_credited));
const paying = ref(false);
const pay = useForm({ supplier_id: props.bill.supplier_id, payment_method_id: null as number | null, amount: String(due.value), reference: '', shift_id: props.openShift, bill_ids: [props.bill.id], idempotency_key: uuid(), notes: '' });
function submit() {
    if (!pay.payment_method_id) pay.payment_method_id = methods.value[0]?.id ?? null;
    pay.post(route('purchasing.payments.store'), { preserveScroll: true, onSuccess: () => { paying.value = false; pay.idempotency_key = uuid(); } });
}
</script>

<template>
    <PageHeader :pager="pager" :title="`${t('Bill')} ${bill.number}`" :subtitle="`${bill.supplier?.name} · ${bill.supplier_invoice_no ?? ''} · ${date(bill.bill_date)}`">
        <template #actions>
            <Badge :status="bill.payment_status" />
            <Button :href="route('purchasing.suppliers.show', bill.supplier_id)">{{ t('Supplier') }}</Button>
            <Button v-if="due > 0" variant="primary" @click="paying = true">{{ t('Pay this bill') }}</Button>
        </template>
    </PageHeader>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Item / charge') }}</th><th class="num">{{ t('Quantity') }}</th><th class="num">{{ t('Price') }}</th><th class="num">{{ t('Tax') }}</th><th class="num">{{ t('Amount') }}</th></tr></thead>
            <tbody><tr v-for="l in bill.lines" :key="l.id"><td>{{ l.description }}</td><td class="num">{{ qty(l.quantity) }}</td><td class="num">{{ money(l.unit_price) }}</td><td class="num">{{ money(l.tax_amount) }}</td><td class="num font-medium">{{ money(l.line_total) }}</td></tr></tbody>
        </table>
        <dl class="space-y-1 border-t border-ink-100 p-4 text-sm">
            <div class="flex justify-between"><dt>{{ t('Total') }}</dt><dd class="font-semibold tabular-nums">{{ money(bill.total) }}</dd></div>
            <div class="flex justify-between"><dt>{{ t('Paid') }}</dt><dd class="tabular-nums">{{ money(bill.amount_paid) }}</dd></div>
            <div v-if="Number(bill.amount_credited)" class="flex justify-between"><dt>{{ t('Returned') }}</dt><dd class="tabular-nums">{{ money(bill.amount_credited) }}</dd></div>
            <div class="flex justify-between text-base font-semibold"><dt>{{ t('Still owed') }}</dt><dd class="tabular-nums" :class="due > 0 ? 'text-ledger-red' : ''">{{ money(due) }}</dd></div>
            <div v-if="bill.due_date" class="text-ink-500">{{ t('Due') }} {{ date(bill.due_date) }}</div>
        </dl>
        <p v-if="bill.notes" class="border-t border-ink-100 p-4 text-sm">{{ bill.notes }}</p>
    </section>
    <Modal :open="paying" :title="t('Pay this bill')" size="sm" @close="paying = false">
        <div class="space-y-3">
            <Field label="Paid from"><select v-model="pay.payment_method_id" class="input"><option v-for="m in methods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></Field>
            <Field label="Amount" :error="pay.errors.amount"><input v-model="pay.amount" type="number" min="0" class="input text-end" /></Field>
            <Field label="Cheque / transaction no."><input v-model="pay.reference" class="input" /></Field>
            <p v-for="(e, k) in pay.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="paying = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="pay.processing" @click="submit">{{ t('Pay') }}</Button></template>
    </Modal>
</template>
