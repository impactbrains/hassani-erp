<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { date, dateTime, money } from '@/lib/format';
import { uuid } from '@/lib/http';
import { localName, t } from '@/lib/i18n';

/** Wasooli: take a payment against a customer's Khata. Oldest bills are cleared first unless you tick specific ones. */
const props = defineProps<{ customer: any | null; recent: any[]; openShift: number | null }>();
const options = useOptions(['payment_methods']);
const picked = ref<any>(props.customer);
watch(picked, (c) => {
    if (c?.id !== props.customer?.id) router.get(route('sales.collections'), c ? { customer_id: c.id } : {}, { preserveScroll: true });
});
const methods = computed(() => (options.payment_methods ?? []).filter((m: any) => m.type !== 'khata' && m.type !== 'credit'));
const form = useForm({ customer_id: props.customer?.id ?? null, payment_method_id: null as number | null, amount: '', reference: '', shift_id: props.openShift, invoice_ids: [] as number[], idempotency_key: uuid(), notes: '' });
const method = computed(() => methods.value.find((m: any) => m.id === form.payment_method_id));
const due = (i: any) => Number(i.total) - Number(i.amount_paid) - Number(i.amount_credited);
const selectedDue = computed(() => (props.customer?.open_invoices ?? []).filter((i: any) => form.invoice_ids.includes(i.id)).reduce((s: number, i: any) => s + due(i), 0));
function submit() {
    if (!form.payment_method_id) form.payment_method_id = methods.value[0]?.id ?? null;
    form.post(route('sales.collections.store'), { preserveScroll: true, onSuccess: () => { form.reset('amount', 'reference', 'notes', 'invoice_ids'); form.idempotency_key = uuid(); } });
}
</script>

<template>
    <PageHeader :title="t('Wasooli (Khata collection)')" :subtitle="t('Receive money a customer owes. Wallet and bank payments wait for verification before the Khata is fully cleared.')" />
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="space-y-5">
            <section class="panel p-4"><Field label="Customer"><ContactPicker v-model="picked" type="customer" /></Field></section>
            <section v-if="customer" class="panel">
                <div class="panel-head">
                    <h2>{{ customer.name }} <span class="text-sm font-normal text-ink-500">{{ customer.code }} · {{ customer.phone }}</span></h2>
                    <div class="text-end"><div class="text-[12px] text-ink-500">{{ t('Balance owed') }}</div><div class="text-xl font-semibold tabular-nums" :class="Number(customer.balance) > 0 ? 'text-ledger-red' : 'text-ledger-green'">{{ money(customer.balance) }}</div></div>
                </div>
                <table class="table-base">
                    <thead><tr><th class="w-8"></th><th>{{ t('Invoice') }}</th><th>{{ t('Date') }}</th><th>{{ t('Due') }}</th><th class="num">{{ t('Total') }}</th><th class="num">{{ t('Still owed') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="i in customer.open_invoices" :key="i.id">
                            <td><input v-model="form.invoice_ids" type="checkbox" :value="i.id" class="rounded border-ink-300" /></td>
                            <td><a :href="route('sales.invoices.show', i.id)" class="link">{{ i.number }}</a></td><td>{{ date(i.business_date) }}</td>
                            <td :class="i.due_date && i.due_date < new Date().toISOString().slice(0, 10) ? 'text-ledger-red' : ''">{{ i.due_date ? date(i.due_date) : '—' }}</td>
                            <td class="num">{{ money(i.total) }}</td><td class="num font-medium">{{ money(due(i)) }}</td>
                        </tr>
                        <tr v-if="!customer.open_invoices.length"><td colspan="6" class="py-6 text-center text-ink-500">{{ t('No unpaid invoices.') }}</td></tr>
                    </tbody>
                </table>
                <p v-if="form.invoice_ids.length" class="border-t border-ink-100 px-4 py-2 text-sm">{{ t('Selected') }}: <b class="tabular-nums">{{ money(selectedDue) }}</b> <button class="link ms-2" @click="form.amount = String(selectedDue)">{{ t('Use this amount') }}</button></p>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Recent collections') }}</h2></div>
                <table class="table-base">
                    <thead><tr><th>{{ t('Number') }}</th><th>{{ t('When') }}</th><th>{{ t('Customer') }}</th><th>{{ t('Method') }}</th><th class="num">{{ t('Amount') }}</th><th>{{ t('Status') }}</th><th>{{ t('By') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="p in recent" :key="p.id">
                            <td>{{ p.number }}</td><td>{{ dateTime(p.received_at) }}</td><td>{{ p.contact?.name }}</td><td>{{ p.method?.name }}<span v-if="p.reference" class="text-[12px] text-ink-500"> · {{ p.reference }}</span></td>
                            <td class="num">{{ money(p.amount) }}</td><td><Badge v-if="p.verification_status" :status="p.verification_status" /></td><td>{{ p.receiver?.name }}</td>
                        </tr>
                        <tr v-if="!recent.length"><td colspan="7" class="py-6 text-center text-ink-500">{{ t('Nothing yet.') }}</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
        <aside>
            <section class="panel space-y-3 p-4">
                <h3>{{ t('Receive payment') }}</h3>
                <Field label="Method" :error="form.errors.payment_method_id"><select v-model="form.payment_method_id" class="input"><option v-for="m in methods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></Field>
                <Field label="Amount" :error="form.errors.amount" required><input v-model="form.amount" type="number" min="0" class="input text-end text-lg tabular-nums" /></Field>
                <Field v-if="method?.requires_reference" label="Transaction ID / reference" :error="form.errors.reference" required><input v-model="form.reference" class="input" /></Field>
                <Field label="Note"><input v-model="form.notes" class="input" /></Field>
                <p v-if="method?.type === 'cash' && !openShift" class="text-[12.5px] text-haldi-800">{{ t('Cash received outside a shift is recorded as safe cash.') }}</p>
                <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
                <Button variant="primary" class="w-full" :disabled="!customer || !form.amount" :loading="form.processing" @click="form.customer_id = customer?.id; submit()">{{ t('Receive') }}</Button>
            </section>
        </aside>
    </div>
</template>
