<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Pencil } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Stat from '@/components/ui/Stat.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import SupplierFormModal from '@/components/app/SupplierFormModal.vue';
import { useOptions } from '@/composables/useOptions';
import { date, money } from '@/lib/format';
import { uuid } from '@/lib/http';
import { can } from '@/lib/perms';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; supplier: any; orders: any[]; receipts: any[]; bills: any[]; payable: string }>();
const editing = ref(false);
const paying = ref(false);
const options = useOptions(['payment_methods']);
const methods = computed(() => (options.payment_methods ?? []).filter((m: any) => ['cash', 'bank', 'wallet'].includes(m.type)));
const openBills = computed(() => props.bills.filter((b) => b.payment_status !== 'paid'));
const pay = useForm({ supplier_id: props.supplier.id, payment_method_id: null as number | null, amount: '', reference: '', shift_id: null as number | null, bill_ids: [] as number[], idempotency_key: uuid(), notes: '' });
function submitPay() {
    if (!pay.payment_method_id) pay.payment_method_id = methods.value[0]?.id ?? null;
    pay.post(route('purchasing.payments.store'), { preserveScroll: true, onSuccess: () => { paying.value = false; pay.reset('amount', 'reference', 'notes', 'bill_ids'); pay.idempotency_key = uuid(); } });
}
const p = props.supplier.supplier_profile ?? {};
</script>

<template>
    <PageHeader :pager="pager" :title="supplier.name" :subtitle="`${supplier.code} · ${supplier.phone ?? ''} · ${supplier.city ?? ''}`">
        <template #actions>
            <Button v-if="can('purchasing.suppliers.manage')" @click="editing = true"><Pencil class="h-4 w-4" />{{ t('Edit') }}</Button>
            <Button v-if="can('purchasing.returns.manage')" :href="route('purchasing.returns.create', { supplier_id: supplier.id })">{{ t('Return goods') }}</Button>
            <Button v-if="can('purchasing.bills.manage')" :href="route('purchasing.bills.create', { supplier_id: supplier.id })">{{ t('Enter bill') }}</Button>
            <Button v-if="can('purchasing.bills.manage') && Number(payable) > 0" variant="primary" @click="paying = true">{{ t('Pay supplier') }}</Button>
            <Button v-if="can('purchasing.orders.manage')" :href="route('purchasing.orders.create', { prefill: JSON.stringify({ supplier: { id: supplier.id, name: supplier.name } }) })">{{ t('New purchase order') }}</Button>
        </template>
    </PageHeader>
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat :label="t('We owe')" :value="money(payable)" :tone="Number(payable) > 0 ? 'bad' : null" />
        <Stat :label="t('Delivery takes')" :value="p.lead_time_days != null ? `${p.lead_time_days} ${t('days')}` : '—'" />
        <Stat :label="t('Days to pay')" :value="p.payment_terms_days ?? '—'" />
        <Stat :label="t('Minimum order')" :value="p.min_order_value ? money(p.min_order_value) : '—'" />
    </div>
    <div class="grid gap-5 xl:grid-cols-3">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Purchase orders') }}</h2></div>
            <ul class="divide-y divide-ink-100 text-sm">
                <li v-for="o in orders" :key="o.id" class="flex items-center gap-2 px-4 py-2"><a :href="route('purchasing.orders.show', o.id)" class="link">{{ o.number }}</a><span class="text-ink-500">{{ date(o.order_date) }}</span><span class="ms-auto tabular-nums">{{ money(o.total) }}</span><Badge :status="o.status" /></li>
                <li v-if="!orders.length" class="px-4 py-4 text-ink-500">—</li>
            </ul>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Goods received') }}</h2></div>
            <ul class="divide-y divide-ink-100 text-sm">
                <li v-for="r in receipts" :key="r.id" class="flex items-center gap-2 px-4 py-2"><a :href="route('purchasing.receipts.show', r.id)" class="link">{{ r.number }}</a><span class="text-ink-500">{{ date(r.business_date) }}</span><span class="ms-auto tabular-nums">{{ r.goods_value !== undefined ? money(r.goods_value) : '' }}</span></li>
                <li v-if="!receipts.length" class="px-4 py-4 text-ink-500">—</li>
            </ul>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Bills') }}</h2></div>
            <ul class="divide-y divide-ink-100 text-sm">
                <li v-for="b in bills" :key="b.id" class="px-4 py-2">
                    <div class="flex items-center gap-2"><a :href="route('purchasing.bills.show', b.id)" class="link">{{ b.number }}</a><span class="text-ink-500">{{ b.supplier_invoice_no }}</span><span class="ms-auto tabular-nums">{{ money(b.total) }}</span><Badge :status="b.payment_status" /></div>
                    <div class="text-[12px] text-ink-500">{{ date(b.bill_date) }}<span v-if="b.due_date"> · {{ t('due') }} {{ date(b.due_date) }}</span> · {{ t('paid') }} {{ money(b.amount_paid) }}</div>
                </li>
                <li v-if="!bills.length" class="px-4 py-4 text-ink-500">—</li>
            </ul>
        </section>
    </div>
    <section v-if="p.bank_details || supplier.address" class="panel mt-5 grid gap-4 p-4 text-sm md:grid-cols-2">
        <div><span class="field-label">{{ t('Address') }}</span>{{ supplier.address ?? '—' }}</div>
        <div><span class="field-label">{{ t('Pay to') }}</span><span class="whitespace-pre-line">{{ p.bank_details ?? '—' }}</span></div>
    </section>

    <SupplierFormModal :open="editing" :supplier="supplier" @close="editing = false" />
    <Modal :open="paying" :title="`${t('Pay')} ${supplier.name}`" size="md" @close="paying = false">
        <div class="space-y-3">
            <p class="text-sm">{{ t('We owe') }}: <b class="tabular-nums">{{ money(payable) }}</b></p>
            <div v-if="openBills.length"><span class="field-label">{{ t('Against bills (optional, oldest first otherwise)') }}</span>
                <label v-for="b in openBills" :key="b.id" class="flex items-center gap-2 text-sm"><input v-model="pay.bill_ids" type="checkbox" :value="b.id" class="rounded border-ink-300" />{{ b.number }} · {{ money(Number(b.total) - Number(b.amount_paid)) }}</label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Paid from"><select v-model="pay.payment_method_id" class="input"><option v-for="m in methods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></Field>
                <Field label="Amount" :error="pay.errors.amount"><input v-model="pay.amount" type="number" min="0" class="input text-end" /></Field>
            </div>
            <Field label="Cheque / transaction no."><input v-model="pay.reference" class="input" /></Field>
            <Field label="Note"><input v-model="pay.notes" class="input" /></Field>
            <p v-for="(e, k) in pay.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="paying = false">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!pay.amount" :loading="pay.processing" @click="submitPay">{{ t('Pay') }}</Button></template>
    </Modal>
</template>
