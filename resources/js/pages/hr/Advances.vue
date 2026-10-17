<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { useOptions } from '@/composables/useOptions';
import { date, money } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

/** Peshgi: salary advances paid now and recovered from the coming payrolls in instalments. */
const props = defineProps<{ advances: any; openShift: number | null; can: { manage: boolean; approve: boolean } }>();
const options = useOptions(['payment_methods']);
const methods = computed(() => (options.payment_methods ?? []).filter((m: any) => ['cash', 'bank', 'wallet'].includes(m.type)));
const next = new Date();
next.setMonth(next.getMonth() + 1);
const asking = ref(false);
const who = ref<any>(null);
const form = useForm({ contact_id: null as number | null, amount: '', installments: 3, recovery_start_month: next.toISOString().slice(0, 7), reason: '' });
function submit() {
    form.contact_id = who.value?.id ?? null;
    form.post(route('hr.advances.store'), { preserveScroll: true, onSuccess: () => { asking.value = false; form.reset('amount', 'reason'); who.value = null; } });
}
const acting = ref<{ advance: any; action: string } | null>(null);
const act = useForm({ action: '', note: '', payment_method_id: null as number | null, shift_id: props.openShift, amount: '' });
function openAct(advance: any, action: string) {
    acting.value = { advance, action };
    act.action = action;
    act.note = '';
    act.amount = action === 'repay' ? String(Number(advance.amount) - Number(advance.recovered_amount)) : '';
}
function quick(a: any, action: string) {
    router.post(route('hr.advances.action', a.id), { action }, { preserveScroll: true });
}
function save() {
    if (!act.payment_method_id && ['disburse', 'repay'].includes(act.action)) act.payment_method_id = methods.value[0]?.id ?? null;
    act.post(route('hr.advances.action', acting.value!.advance.id), { preserveScroll: true, onSuccess: () => (acting.value = null) });
}
</script>

<template>
    <PageHeader :title="t('Peshgi (salary advances)')" :subtitle="t('Approved Peshgi is paid out, then recovered from salary in equal monthly instalments.')">
        <template #actions><Button variant="primary" @click="asking = true">{{ t('Request Peshgi') }}</Button></template>
    </PageHeader>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Number') }}</th><th>{{ t('Employee') }}</th><th class="num">{{ t('Amount') }}</th><th class="num">{{ t('Per month') }}</th><th>{{ t('Recover from') }}</th><th class="num">{{ t('Recovered') }}</th><th>{{ t('Reason') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="a in advances.data" :key="a.id">
                    <td>{{ a.number }}</td><td class="font-medium">{{ a.contact?.name }}</td><td class="num">{{ money(a.amount) }}</td><td class="num">{{ money(a.installment_amount) }}</td>
                    <td>{{ date(a.recovery_start_month) }}</td><td class="num">{{ money(a.recovered_amount) }}</td><td class="text-ink-600">{{ a.reason }}<div class="text-[12px]">{{ a.requester?.name }}<span v-if="a.approver"> · {{ t('approved by') }} {{ a.approver.name }}</span></div></td><td><Badge :status="a.status" /></td>
                    <td class="whitespace-nowrap">
                        <template v-if="a.status === 'requested' && can.approve"><Button size="sm" variant="primary" @click="quick(a, 'approve')">{{ t('Approve') }}</Button> <Button size="sm" variant="danger" @click="openAct(a, 'reject')">{{ t('Reject') }}</Button></template>
                        <Button v-else-if="a.status === 'approved' && can.manage" size="sm" variant="primary" @click="openAct(a, 'disburse')">{{ t('Pay out') }}</Button>
                        <Button v-else-if="['disbursed', 'recovering'].includes(a.status) && can.manage" size="sm" @click="openAct(a, 'repay')">{{ t('Repaid in cash') }}</Button>
                    </td>
                </tr>
                <tr v-if="!advances.data.length"><td colspan="9" class="py-8 text-center text-ink-500">{{ t('No Peshgi yet.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="advances" />
    </section>
    <Modal :open="asking" :title="t('Request Peshgi')" size="md" @close="asking = false">
        <div class="space-y-3">
            <Field v-if="can.manage" label="For (leave empty for yourself)"><ContactPicker v-model="who" type="employee" /></Field>
            <div class="grid grid-cols-3 gap-3">
                <Field label="Amount" :error="form.errors.amount" required><input v-model="form.amount" type="number" min="0" class="input text-end" /></Field>
                <Field label="Instalments" :error="form.errors.installments"><input v-model="form.installments" type="number" min="1" class="input text-end" /></Field>
                <Field label="Recover from" :error="form.errors.recovery_start_month"><input v-model="form.recovery_start_month" type="month" class="input" /></Field>
            </div>
            <p v-if="form.amount" class="text-sm">{{ money(Number(form.amount) / Number(form.installments || 1)) }} {{ t('per month') }}</p>
            <Field label="Reason"><input v-model="form.reason" class="input" /></Field>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="asking = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="submit">{{ t('Request') }}</Button></template>
    </Modal>
    <Modal :open="!!acting" :title="acting?.action === 'disburse' ? t('Pay out Peshgi') : acting?.action === 'repay' ? t('Repaid in cash') : t('Reject Peshgi')" size="sm" @close="acting = null">
        <div class="space-y-3">
            <Field v-if="acting?.action === 'repay'" label="Amount" :error="act.errors.amount"><input v-model="act.amount" type="number" min="0" class="input text-end" /></Field>
            <Field v-if="['disburse', 'repay'].includes(acting?.action ?? '')" label="Paid from / into"><select v-model="act.payment_method_id" class="input"><option v-for="m in methods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></Field>
            <p v-if="acting?.action === 'disburse' && !openShift" class="text-[12.5px] text-ink-500">{{ t('Cash paid outside a shift comes from the safe.') }}</p>
            <Field label="Note"><input v-model="act.note" class="input" /></Field>
            <p v-for="(e, k) in act.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="acting = null">{{ t('Cancel') }}</Button><Button :variant="acting?.action === 'reject' ? 'danger' : 'primary'" :loading="act.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
