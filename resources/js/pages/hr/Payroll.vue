<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { date, money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ runs: any; adjustments: any[]; can: { manage: boolean; approve: boolean } }>();
const page = usePage<any>();
const last = new Date();
last.setMonth(last.getMonth() - 1);
const prep = useForm({ month: last.toISOString().slice(0, 7), store_id: null as number | null });
const preparing = ref(false);
const adding = ref(false);
const who = ref<any>(null);
const adj = useForm({ contact_id: null as number | null, type: 'bonus', category: 'bonus', amount: '', payroll_month: new Date().toISOString().slice(0, 7), reason: '', investigation_reference: '' });
function saveAdj() {
    adj.contact_id = who.value?.id ?? null;
    adj.post(route('hr.payroll.adjustments'), { preserveScroll: true, onSuccess: () => { adding.value = false; adj.reset('amount', 'reason', 'investigation_reference'); who.value = null; } });
}
const categories: Record<string, string> = { bonus: 'Bonus', incentive: 'Incentive', commission: 'Commission', arrears: 'Arrears', shortage_recovery: 'Cash shortage recovery', damage_recovery: 'Damage recovery', other: 'Other' };
const columns = [
    { key: 'number', label: 'Payroll' }, { key: 'period_start', label: 'Month', type: 'date' as const }, { key: 'store.name', label: 'Store' }, { key: 'payslips_count', label: 'People', type: 'int' as const },
    { key: 'gross_total', label: 'Gross', type: 'money' as const }, { key: 'net_total', label: 'Net pay', type: 'money' as const }, { key: 'status', label: 'Status', type: 'status' as const },
];
</script>

<template>
    <PageHeader :title="t('Payroll')" :subtitle="t('Monthly salaries from attendance, leave, Peshgi recovery, bonuses and deductions. Prepare → check → approve → post → pay.')">
        <template #actions>
            <Button v-if="can.manage" @click="adding = true">{{ t('Bonus or deduction') }}</Button>
            <Button v-if="can.manage" variant="primary" @click="preparing = true">{{ t('Prepare payroll') }}</Button>
        </template>
    </PageHeader>
    <section v-if="adjustments.length" class="panel mb-5">
        <div class="panel-head"><h2>{{ t('Bonuses and deductions for coming payrolls') }}</h2></div>
        <table class="table-base">
            <thead><tr><th>{{ t('Employee') }}</th><th>{{ t('Month') }}</th><th>{{ t('Kind') }}</th><th class="num">{{ t('Amount') }}</th><th>{{ t('Reason') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="a in adjustments" :key="a.id">
                    <td>{{ a.contact?.name }}</td><td>{{ date(a.payroll_month) }}</td><td>{{ t(categories[a.category] ?? a.category) }}</td>
                    <td class="num" :class="a.type === 'deduction' ? 'text-ledger-red' : 'text-ledger-green'">{{ a.type === 'deduction' ? '−' : '+' }}{{ money(a.amount) }}</td>
                    <td class="text-ink-600">{{ a.reason }}<span v-if="a.investigation_reference" class="text-[12px]"> · {{ a.investigation_reference }}</span></td><td><Badge :status="a.status" /></td>
                    <td class="whitespace-nowrap"><template v-if="a.status === 'pending' && can.approve"><Button size="sm" variant="primary" @click="router.post(route('hr.payroll.adjustments.review', a.id), { approve: true }, { preserveScroll: true })">{{ t('Approve') }}</Button> <Button size="sm" variant="danger" @click="router.post(route('hr.payroll.adjustments.review', a.id), { approve: false }, { preserveScroll: true })">{{ t('Reject') }}</Button></template></td>
                </tr>
            </tbody>
        </table>
    </section>
    <section class="panel">
        <DataTable :columns="columns" :rows="runs.data" :row-href="(r) => route('hr.payroll.show', r.id)" empty="No payroll yet." />
        <Pagination :meta="runs" />
    </section>
    <Modal :open="preparing" :title="t('Prepare payroll')" size="sm" @close="preparing = false">
        <div class="space-y-3">
            <Field label="Month" :error="prep.errors.month"><input v-model="prep.month" type="month" class="input" /></Field>
            <Field label="Store"><select v-model="prep.store_id" class="input"><option :value="null">{{ t('All stores') }}</option><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
            <p class="text-[12.5px] text-ink-500">{{ t('Uses attendance, approved leave, approved bonuses/deductions and Peshgi instalments for that month. Nothing is posted until you approve.') }}</p>
            <p v-for="(e, k) in prep.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="preparing = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="prep.processing" @click="prep.post(route('hr.payroll.prepare'))">{{ t('Prepare') }}</Button></template>
    </Modal>
    <Modal :open="adding" :title="t('Bonus or deduction')" size="md" @close="adding = false">
        <div class="space-y-3">
            <Field label="Employee" :error="adj.errors.contact_id" required><ContactPicker v-model="who" type="employee" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Add or deduct"><select v-model="adj.type" class="input"><option value="bonus">{{ t('Bonus') }}</option><option value="allowance">{{ t('Allowance') }}</option><option value="reimbursement">{{ t('Reimbursement') }}</option><option value="deduction">{{ t('Deduction') }}</option></select></Field>
                <Field label="Kind"><select v-model="adj.category" class="input"><option v-for="(l, k) in categories" :key="k" :value="k">{{ t(l) }}</option></select></Field>
                <Field label="Amount" :error="adj.errors.amount"><input v-model="adj.amount" type="number" min="0" class="input text-end" /></Field>
                <Field label="Payroll month"><input v-model="adj.payroll_month" type="month" class="input" /></Field>
            </div>
            <Field label="Reason" :error="adj.errors.reason" required><input v-model="adj.reason" class="input" /></Field>
            <Field v-if="['shortage_recovery', 'damage_recovery'].includes(adj.category)" label="Shift / investigation reference" :error="adj.errors.investigation_reference"><input v-model="adj.investigation_reference" class="input" :placeholder="t('Shift number or note of the review')" /></Field>
            <p v-if="['shortage_recovery', 'damage_recovery'].includes(adj.category)" class="text-[12.5px] text-haldi-800">{{ t('Recoveries need another manager to approve, and should follow a reviewed shortage. They are never deducted automatically.') }}</p>
        </div>
        <template #footer><Button @click="adding = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="adj.processing" @click="saveAdj">{{ t('Save') }}</Button></template>
    </Modal>
</template>
