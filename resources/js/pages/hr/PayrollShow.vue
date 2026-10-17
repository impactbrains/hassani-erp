<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Stat from '@/components/ui/Stat.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { useOptions } from '@/composables/useOptions';
import { date, money } from '@/lib/format';
import { can as hasPerm } from '@/lib/perms';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ run: any; payslips: any[]; can: { approve: boolean } }>();
const options = useOptions(['payment_methods']);
const methods = computed(() => (options.payment_methods ?? []).filter((m: any) => ['cash', 'bank', 'wallet'].includes(m.type)));
const open = ref<number | null>(null);
const paying = ref(false);
const methodId = ref<number | null>(null);
const reference = ref('');
const chosen = ref<number[]>(props.payslips.filter((p) => p.status !== 'paid').map((p) => p.id));
function act(action: string, extra: Record<string, any> = {}) {
    router.post(route('hr.payroll.action', props.run.id), { action, ...extra }, { preserveScroll: true, onSuccess: () => (paying.value = false) });
}
const lines = (p: any, type: string) => p.lines.filter((l: any) => l.type === type);
</script>

<template>
    <PageHeader :title="`${t('Payroll')} ${run.number}`" :subtitle="`${date(run.period_start)} – ${date(run.period_end)} · ${run.store?.name ?? t('All stores')}`">
        <template #actions>
            <Badge :status="run.status" />
            <Button v-if="run.status === 'draft' && can.approve" variant="primary" @click="act('approve')">{{ t('Approve') }}</Button>
            <Button v-if="run.status === 'approved' && hasPerm('hr.payroll.manage')" variant="primary" @click="act('post')">{{ t('Post to accounts') }}</Button>
            <Button v-if="['posted', 'partially_paid'].includes(run.status) && hasPerm('hr.payroll.manage')" variant="primary" @click="paying = true">{{ t('Pay salaries') }}</Button>
        </template>
    </PageHeader>
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat :label="t('Gross')" :value="money(run.gross_total)" />
        <Stat :label="t('Deductions')" :value="money(run.deduction_total)" />
        <Stat :label="t('Net pay')" :value="money(run.net_total)" tone="good" />
        <Stat :label="t('Employer cost')" :value="money(run.employer_cost_total)" :note="`${run.working_days} ${t('working days')}`" />
    </div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Employee') }}</th><th class="num">{{ t('Present') }}</th><th class="num">{{ t('Absent') }}</th><th class="num">{{ t('Leave') }}</th><th class="num">{{ t('Late') }}</th><th class="num">{{ t('Gross') }}</th><th class="num">{{ t('Deductions') }}</th><th class="num">{{ t('Net') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
                <template v-for="p in payslips" :key="p.id">
                    <tr class="cursor-pointer" @click="open = open === p.id ? null : p.id">
                        <td class="font-medium">{{ p.contact?.name }}</td><td class="num">{{ p.days_present }}</td><td class="num" :class="Number(p.days_absent) ? 'text-ledger-red' : ''">{{ p.days_absent }}</td>
                        <td class="num">{{ Number(p.days_paid_leave) + Number(p.days_unpaid_leave) }}</td><td class="num">{{ p.late_count }}</td>
                        <td class="num">{{ money(p.gross_pay) }}</td><td class="num">{{ money(p.total_deductions) }}</td><td class="num font-semibold">{{ money(p.net_pay) }}</td>
                        <td><Badge :status="p.status" /></td><td><a :href="route('hr.payslips.show', p.id)" class="link text-[12.5px]" target="_blank" @click.stop>{{ t('Payslip') }}</a></td>
                    </tr>
                    <tr v-if="open === p.id" class="bg-ink-50/40">
                        <td colspan="10" class="p-4">
                            <div class="grid gap-4 text-[13px] md:grid-cols-3">
                                <div><h3 class="mb-1">{{ t('Earnings') }}</h3><div v-for="l in lines(p, 'earning')" :key="l.id" class="flex justify-between"><span>{{ l.description }}</span><span class="tabular-nums">{{ money(l.amount) }}</span></div></div>
                                <div><h3 class="mb-1">{{ t('Deductions') }}</h3><div v-for="l in lines(p, 'deduction')" :key="l.id" class="flex justify-between"><span>{{ l.description }}</span><span class="tabular-nums text-ledger-red">{{ money(l.amount) }}</span></div><p v-if="!lines(p, 'deduction').length" class="text-ink-500">—</p></div>
                                <div><h3 class="mb-1">{{ t('Paid by the business') }}</h3><div v-for="l in lines(p, 'employer')" :key="l.id" class="flex justify-between"><span>{{ l.description }}</span><span class="tabular-nums">{{ money(l.amount) }}</span></div><p v-if="!lines(p, 'employer').length" class="text-ink-500">—</p>
                                    <p v-if="p.payment_method" class="mt-2 text-ink-600">{{ t('Paid by') }} {{ p.payment_method.name }} {{ p.payment_reference }}</p></div>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr v-if="!payslips.length"><td colspan="10" class="py-8 text-center text-ink-500">{{ t('Nobody with a salary in this period.') }}</td></tr>
            </tbody>
        </table>
    </section>
    <Modal :open="paying" :title="t('Pay salaries')" size="md" @close="paying = false">
        <div class="space-y-3">
            <div class="max-h-60 overflow-y-auto rounded border border-ink-100 p-2">
                <label v-for="p in payslips.filter((x) => x.status !== 'paid')" :key="p.id" class="flex items-center gap-2 py-0.5 text-sm"><input v-model="chosen" type="checkbox" :value="p.id" class="rounded border-ink-300" />{{ p.contact?.name }}<span class="ms-auto tabular-nums">{{ money(p.net_pay) }}</span></label>
            </div>
            <Field label="Paid from"><select v-model="methodId" class="input"><option v-for="m in methods" :key="m.id" :value="m.id">{{ localName(m) }}</option></select></Field>
            <Field label="Cheque / transfer reference"><input v-model="reference" class="input" /></Field>
        </div>
        <template #footer><Button @click="paying = false">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!methodId || !chosen.length" @click="act('pay', { payment_method_id: methodId, reference, payslip_ids: chosen })">{{ t('Mark paid') }}</Button></template>
    </Modal>
</template>
