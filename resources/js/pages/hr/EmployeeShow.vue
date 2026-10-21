<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { FileText, Pencil, Plus, Trash2, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { get } from '@/lib/http';
import { date, dateTime, money, today } from '@/lib/format';
import { attendanceStatus, clock, docTypes, employmentTypes, minutes, roleTypes } from '@/lib/hr';
import { t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; employee: any; events: any[]; documents: any[] | null; attendance: any[]; leave: any[]; leaveBalances: any[]; payslips: any[] | null; salary: any[] | null; advances: any[] | null; logins: any[] | null; can: Record<string, boolean> }>();
const page = usePage<any>();
// Computed, not a one-time copy: after terminate / rehire the page props change and everything below must follow.
const d = computed<any>(() => props.employee.employee_detail ?? {});
const tab = ref('overview');
const modal = ref<'' | 'transition' | 'salary' | 'document' | 'login'>('');
const steps: Record<string, string> = { start_probation: 'Start probation', confirm: 'Confirm (make permanent)', suspend: 'Suspend', reinstate: 'Reinstate', transfer: 'Transfer', resign: 'Resigned', terminate: 'Terminate', rehire: 'Rehire (new employment period)' };
const allowed: Record<string, string[]> = { onboarding: ['start_probation', 'confirm', 'resign', 'terminate', 'transfer'], probation: ['confirm', 'suspend', 'resign', 'terminate', 'transfer'], active: ['suspend', 'resign', 'terminate', 'transfer'], suspended: ['reinstate', 'resign', 'terminate'], resigned: ['rehire'], terminated: ['rehire'] };
const tr = useForm({ action: '', effective_date: today(), note: '', insights: '', store_id: d.value.store_id ?? null, department_id: d.value.department_id ?? null, job_position_id: d.value.job_position_id ?? null });
const current = computed(() => props.salary?.find((s: any) => !s.effective_to) ?? props.salary?.[0]);
const sal = useForm<any>({ effective_from: today(), pay_basis: current.value?.pay_basis ?? 'monthly', base_amount: current.value?.base_amount ?? '', overtime_hourly_rate: current.value?.overtime_hourly_rate ?? '', components: JSON.parse(JSON.stringify(current.value?.components ?? [])), eobi_employee: current.value?.eobi_employee ?? '', eobi_employer: current.value?.eobi_employer ?? '', social_security_employer: current.value?.social_security_employer ?? '' });
const doc = useForm<{ file: File | null; type: string; title: string; expires_on: string }>({ file: null, type: 'cnic', title: '', expires_on: '' });
const loginId = ref<number | null>(null);
const ins = useForm({ insights: d.value.employer_insights ?? '' });
watch(() => d.value.employer_insights, (v) => ins.defaults({ insights: v ?? '' }).reset());
function saveInsights() {
    ins.post(route('hr.employees.insights', props.employee.id), { preserveScroll: true });
}
function openStep(a: string) {
    tr.action = a;
    modal.value = 'transition';
}
const close = () => (modal.value = '');
function saveStep() {
    tr.post(route('hr.employees.transition', props.employee.id), { preserveScroll: true, onSuccess: close });
}
function saveSalary() {
    sal.transform((x: any) => Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v === '' ? null : v]))).post(route('hr.employees.salary', props.employee.id), { preserveScroll: true, onSuccess: close });
}
function saveDoc() {
    doc.post(route('hr.employees.documents', props.employee.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { doc.reset(); close(); } });
}
async function openDoc(id: number) {
    const res = await get(route('hr.documents.link', id));
    window.open(res.url, '_blank');
}
function linkLogin() {
    router.post(route('hr.employees.login', props.employee.id), { user_id: loginId.value }, { preserveScroll: true, onSuccess: close });
}
const leaveTotal = (l: any) => `${l.days} ${t('days')}`;
</script>

<template>
    <PageHeader :pager="pager" :title="employee.name" :subtitle="`${d.employee_no ?? ''} · ${d.job_title ?? t(roleTypes[d.role_type] ?? '')} · ${d.store?.name ?? ''}`">
        <template #actions>
            <Badge :status="d.status" />
            <template v-if="can.export">
                <a :href="route('hr.employees.export', { employee: employee.id, format: 'xlsx' })" class="inline-flex h-10 items-center rounded border border-ink-200 bg-white px-4 text-sm font-medium text-ink-800 hover:bg-ink-50">{{ t('Export Excel') }}</a>
                <a :href="route('hr.employees.export', { employee: employee.id, format: 'print' })" target="_blank" class="inline-flex h-10 items-center rounded border border-ink-200 bg-white px-4 text-sm font-medium text-ink-800 hover:bg-ink-50">{{ t('Export PDF') }}</a>
            </template>
            <Button v-if="can.manage" :href="route('hr.employees.edit', employee.id)"><Pencil class="h-4 w-4" />{{ t('Edit') }}</Button>
            <template v-if="can.lifecycle"><Button v-for="a in allowed[d.status] ?? []" :key="a" size="sm" :variant="['terminate'].includes(a) ? 'danger' : 'secondary'" @click="openStep(a)">{{ t(steps[a]) }}</Button></template>
        </template>
    </PageHeader>
    <div v-if="employee.name_ur" class="urdu -mt-3 mb-3 text-lg" dir="rtl" style="text-align: start">{{ employee.name_ur }}</div>

    <Tabs v-model="tab" :tabs="[{ key: 'overview', label: 'Overview' }, { key: 'attendance', label: 'Attendance and leave' }, ...(payslips ? [{ key: 'pay', label: 'Salary and pay' }] : []), ...(documents ? [{ key: 'documents', label: 'Documents', count: documents.length }] : []), { key: 'history', label: 'History' }]" />

    <div v-if="tab === 'overview'" class="grid gap-5 lg:grid-cols-3">
        <section class="panel space-y-2 p-4 text-sm">
            <h3>{{ t('Job') }}</h3>
            <div><span class="field-label">{{ t('Employment') }}</span>{{ t(employmentTypes[d.employment_type] ?? d.employment_type) }}</div>
            <div><span class="field-label">{{ t('Department / position') }}</span>{{ d.department?.name ?? '—' }} · {{ d.position?.name ?? '—' }}</div>
            <div><span class="field-label">{{ t('Reports to') }}</span>{{ d.manager?.name ?? '—' }}</div>
            <div><span class="field-label">{{ t('Joined') }}</span>{{ d.joining_date ? date(d.joining_date) : '—' }}<span v-if="d.probation_end_date"> · {{ t('probation ends') }} {{ date(d.probation_end_date) }}</span></div>
            <div v-if="d.exit_date"><span class="field-label">{{ t('Left') }}</span>{{ date(d.exit_date) }} · {{ d.exit_reason }}</div>
        </section>
        <section class="panel space-y-2 p-4 text-sm">
            <h3>{{ t('Contact') }}</h3>
            <div><span class="field-label">{{ t('Mobile') }}</span>{{ employee.phone ?? '—' }}</div>
            <div><span class="field-label">{{ t('Address') }}</span>{{ employee.address ?? '—' }} {{ employee.city }}</div>
            <div><span class="field-label">{{ t('Emergency contact') }}</span>{{ d.emergency_contact_name ?? '—' }} {{ d.emergency_contact_phone }} <span class="text-ink-500">{{ d.emergency_contact_relation }}</span></div>
            <div><span class="field-label">{{ t('Login') }}</span>{{ employee.user?.email ?? t('No login') }}<button v-if="!employee.user && can.users && logins?.length" class="link ms-2" @click="modal = 'login'">{{ t('Link a login') }}</button><a v-if="!employee.user && can.users" :href="route('hr.employees.edit', employee.id)" class="link ms-2">{{ t('Create login') }}</a></div>
        </section>
        <section v-if="can.sensitive" class="panel space-y-2 border-haldi-300 p-4 text-sm">
            <h3>{{ t('Private') }}</h3>
            <div><span class="field-label">CNIC</span>{{ d.cnic ?? '—' }}<span v-if="d.cnic_expiry" class="text-ink-500"> · {{ t('expires') }} {{ date(d.cnic_expiry) }}</span></div>
            <div><span class="field-label">{{ t('Father’s name') }}</span>{{ d.father_name ?? '—' }}</div>
            <div><span class="field-label">{{ t('Date of birth') }}</span>{{ d.date_of_birth ? date(d.date_of_birth) : '—' }}</div>
            <div><span class="field-label">{{ t('Bank') }}</span>{{ d.bank_name ?? '—' }} {{ d.bank_account }}</div>
            <div><span class="field-label">EOBI / SESSI</span>{{ d.eobi_no ?? '—' }} / {{ d.social_security_no ?? '—' }}</div>
        </section>
        <section v-if="can.lifecycle" class="panel space-y-2 p-4 text-sm lg:col-span-3">
            <h3>{{ t("Employer's insights") }}</h3>
            <textarea v-model="ins.insights" rows="3" class="input" :placeholder="t('Private notes for HR managers. They are included in the leaving export.')" />
            <div class="flex items-center gap-3"><Button size="sm" variant="primary" :loading="ins.processing" @click="saveInsights">{{ t('Save') }}</Button><span v-if="d.insights_at" class="text-[12px] text-ink-500">{{ t('Last saved') }} {{ dateTime(d.insights_at) }}</span></div>
        </section>
        <section class="panel p-4 text-sm lg:col-span-3" v-if="d.note"><span class="field-label">{{ t('Notes') }}</span><p class="whitespace-pre-line">{{ d.note }}</p></section>
    </div>

    <div v-if="tab === 'attendance'" class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Last 31 days') }}</h2></div>
            <table class="table-base">
                <thead><tr><th>{{ t('Date') }}</th><th>{{ t('In') }}</th><th>{{ t('Out') }}</th><th>{{ t('Status') }}</th><th class="num">{{ t('Late') }}</th><th class="num">{{ t('Worked') }}</th><th class="num">{{ t('Overtime') }}</th></tr></thead>
                <tbody>
                    <tr v-for="a in attendance" :key="a.id"><td>{{ date(a.work_date) }}</td><td>{{ clock(a.clock_in_at) }}</td><td>{{ clock(a.clock_out_at) }}</td><td><Badge :tone="a.status === 'absent' ? 'bad' : a.status === 'late' ? 'warn' : 'good'" :label="t(attendanceStatus[a.status] ?? a.status)" /><span v-if="a.is_corrected" class="ms-1 text-[11px] text-ink-500">{{ t('corrected') }}</span></td><td class="num">{{ minutes(a.late_minutes) }}</td><td class="num">{{ minutes(a.worked_minutes) }}</td><td class="num">{{ minutes(a.overtime_minutes) }}</td></tr>
                    <tr v-if="!attendance.length"><td colspan="7" class="py-6 text-center text-ink-500">{{ t('No attendance recorded.') }}</td></tr>
                </tbody>
            </table>
        </section>
        <div class="space-y-5">
            <section class="panel p-4">
                <h3 class="mb-2">{{ t('Leave balance this year') }}</h3>
                <table class="w-full text-sm"><tr v-for="b in leaveBalances" :key="b.type"><td>{{ b.type }}</td><td class="num">{{ b.remaining }} / {{ b.allocated }}</td></tr></table>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Leave') }}</h2><a :href="route('hr.leave')" class="link text-[13px]">{{ t('Leave page') }}</a></div>
                <ul class="divide-y divide-ink-100 text-sm"><li v-for="l in leave" :key="l.id" class="flex items-center gap-2 px-4 py-2">{{ l.leave_type?.name }} · {{ date(l.start_date) }}–{{ date(l.end_date) }} · {{ leaveTotal(l) }}<Badge class="ms-auto" :status="l.status" /></li><li v-if="!leave.length" class="px-4 py-3 text-ink-500">—</li></ul>
            </section>
        </div>
    </div>

    <div v-if="tab === 'pay' && payslips" class="grid gap-5 lg:grid-cols-2">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Salary') }}</h2><Button v-if="can.payroll" size="sm" variant="primary" @click="modal = 'salary'">{{ current ? t('Change salary') : t('Set salary') }}</Button></div>
            <table class="table-base">
                <thead><tr><th>{{ t('From') }}</th><th>{{ t('To') }}</th><th>{{ t('Basis') }}</th><th class="num">{{ t('Basic') }}</th><th>{{ t('Allowances / deductions') }}</th></tr></thead>
                <tbody>
                    <tr v-for="s in salary" :key="s.id"><td>{{ date(s.effective_from) }}</td><td>{{ s.effective_to ? date(s.effective_to) : t('now') }}</td><td>{{ t(s.pay_basis) }}</td><td class="num font-medium">{{ money(s.base_amount) }}</td><td class="text-[12.5px]">{{ (s.components ?? []).map((c: any) => `${c.name} ${c.type === 'deduction' ? '−' : '+'}${money(c.amount)}`).join(', ') || '—' }}</td></tr>
                    <tr v-if="!salary?.length"><td colspan="5" class="py-6 text-center text-ink-500">{{ t('No salary set. Payroll skips this employee until a salary is set.') }}</td></tr>
                </tbody>
            </table>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Payslips') }}</h2></div>
            <table class="table-base">
                <thead><tr><th>{{ t('Month') }}</th><th class="num">{{ t('Gross') }}</th><th class="num">{{ t('Deductions') }}</th><th class="num">{{ t('Net') }}</th><th>{{ t('Status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="p in payslips" :key="p.id"><td><a :href="route('hr.payslips.show', p.id)" class="link">{{ date(p.run?.period_start) }}</a></td><td class="num">{{ money(p.gross_pay) }}</td><td class="num">{{ money(p.total_deductions) }}</td><td class="num font-semibold">{{ money(p.net_pay) }}</td><td><Badge :status="p.status" /></td></tr>
                    <tr v-if="!payslips.length"><td colspan="5" class="py-6 text-center text-ink-500">—</td></tr>
                </tbody>
            </table>
        </section>
        <section class="panel lg:col-span-2">
            <div class="panel-head"><h2>{{ t('Peshgi (advances)') }}</h2><a :href="route('hr.advances')" class="link text-[13px]">{{ t('All Peshgi') }}</a></div>
            <table class="table-base">
                <thead><tr><th>{{ t('Number') }}</th><th class="num">{{ t('Amount') }}</th><th class="num">{{ t('Per month') }}</th><th class="num">{{ t('Recovered') }}</th><th>{{ t('From') }}</th><th>{{ t('Status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="a in advances" :key="a.id"><td>{{ a.number }}</td><td class="num">{{ money(a.amount) }}</td><td class="num">{{ money(a.installment_amount) }}</td><td class="num">{{ money(a.recovered_amount) }}</td><td>{{ date(a.recovery_start_month) }}</td><td><Badge :status="a.status" /></td></tr>
                    <tr v-if="!advances?.length"><td colspan="6" class="py-6 text-center text-ink-500">—</td></tr>
                </tbody>
            </table>
        </section>
    </div>

    <section v-if="tab === 'documents' && documents" class="panel">
        <div class="panel-head"><h2>{{ t('Documents (private storage)') }}</h2><Button size="sm" variant="primary" @click="modal = 'document'"><Upload class="h-4 w-4" />{{ t('Upload') }}</Button></div>
        <ul class="divide-y divide-ink-100">
            <li v-for="x in documents" :key="x.id" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                <FileText class="h-4 w-4 text-ink-400" />
                <button class="link" @click="openDoc(x.id)">{{ x.title }}</button><span class="text-ink-500">{{ t(docTypes[x.type] ?? x.type) }}</span>
                <span v-if="x.expires_on" :class="x.expires_on < today() ? 'text-ledger-red' : 'text-ink-500'">{{ t('expires') }} {{ date(x.expires_on) }}</span>
                <span class="ms-auto text-[12px] text-ink-500">{{ x.uploader?.name }} · {{ date(x.created_at) }}</span>
            </li>
            <li v-if="!documents.length" class="px-4 py-6 text-center text-sm text-ink-500">{{ t('No documents yet.') }}</li>
        </ul>
        <p class="border-t border-ink-100 px-4 py-2 text-[12px] text-ink-500">{{ t('Files open through a link that works for five minutes and only for you. Every opening is logged.') }}</p>
    </section>

    <section v-if="tab === 'history'" class="panel">
        <ul class="divide-y divide-ink-100">
            <li v-for="ev in events" :key="ev.id" class="px-4 py-2.5 text-sm"><div class="font-medium">{{ t(ev.type.replaceAll('_', ' ')) }} · {{ date(ev.effective_date) }}</div><div v-if="ev.note" class="text-ink-600">{{ ev.note }}</div><div class="text-[12px] text-ink-500">{{ ev.recorder?.name }} · {{ dateTime(ev.created_at) }}</div></li>
            <li v-if="!events.length" class="px-4 py-6 text-center text-sm text-ink-500">—</li>
        </ul>
    </section>

    <Modal :open="modal === 'transition'" :title="t(steps[tr.action] ?? '')" size="md" @close="close">
        <div class="space-y-3">
            <Field label="From date" :error="tr.errors.effective_date"><input v-model="tr.effective_date" type="date" class="input" /></Field>
            <template v-if="tr.action === 'transfer'">
                <Field label="Store"><select v-model="tr.store_id" class="input"><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
            </template>
            <Field label="Note"><textarea v-model="tr.note" rows="2" class="input" /></Field>
            <Field v-if="['resign', 'terminate'].includes(tr.action) && can.lifecycle" label="Employer's insights (optional)"><textarea v-model="tr.insights" rows="3" class="input" :placeholder="t('Your own notes about this person: strengths, concerns, would you rehire…')" /></Field>
            <p v-if="tr.action === 'rehire'" class="text-[12.5px] text-haldi-800">{{ t('This starts a new employment period from the date above. The earlier period stays in the history, and their login is switched back on.') }}</p>
            <p v-if="['resign', 'terminate'].includes(tr.action)" class="text-[12.5px] text-ink-500">{{ t('After saving you can download their full record as Excel or PDF from this page.') }}</p>
            <p v-if="['resign', 'terminate'].includes(tr.action)" class="text-[12.5px] text-haldi-800">{{ t('Their login is switched off and they are left out of future payrolls. Final settlement is paid with the next payroll.') }}</p>
            <p v-for="(er, k) in tr.errors" :key="k" class="text-sm text-ledger-red">{{ er }}</p>
        </div>
        <template #footer><Button @click="close">{{ t('Cancel') }}</Button><Button :variant="tr.action === 'terminate' ? 'danger' : 'primary'" :loading="tr.processing" @click="saveStep">{{ t('Save') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'salary'" :title="t('Salary')" size="lg" @close="close">
        <div class="space-y-3">
            <div class="grid grid-cols-3 gap-3">
                <Field label="From" :error="sal.errors.effective_from"><input v-model="sal.effective_from" type="date" class="input" /></Field>
                <Field label="Paid"><select v-model="sal.pay_basis" class="input"><option value="monthly">{{ t('Monthly') }}</option><option value="daily">{{ t('Daily') }}</option><option value="hourly">{{ t('Hourly') }}</option></select></Field>
                <Field label="Basic (Rs)" :error="sal.errors.base_amount" required><input v-model="sal.base_amount" type="number" min="0" class="input text-end" /></Field>
                <Field label="Overtime per hour"><input v-model="sal.overtime_hourly_rate" type="number" min="0" class="input text-end" /></Field>
                <Field label="EOBI (employee)"><input v-model="sal.eobi_employee" type="number" min="0" class="input text-end" /></Field>
                <Field label="EOBI (employer)"><input v-model="sal.eobi_employer" type="number" min="0" class="input text-end" /></Field>
                <Field label="Social security (employer)"><input v-model="sal.social_security_employer" type="number" min="0" class="input text-end" /></Field>
            </div>
            <div>
                <span class="field-label">{{ t('Fixed allowances and deductions every month') }}</span>
                <div v-for="(c, i) in sal.components" :key="i" class="mb-2 flex gap-2">
                    <select v-model="c.type" class="input w-36 py-1"><option value="allowance">{{ t('Allowance') }}</option><option value="deduction">{{ t('Deduction') }}</option></select>
                    <input v-model="c.code" class="input w-24 py-1" :placeholder="t('Code')" /><input v-model="c.name" class="input flex-1 py-1" :placeholder="t('e.g. Conveyance')" /><input v-model="c.amount" type="number" min="0" class="input w-28 py-1 text-end" />
                    <button class="text-ink-400 hover:text-ledger-red" @click="sal.components.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
                </div>
                <Button size="sm" @click="sal.components.push({ type: 'allowance', code: '', name: '', amount: '' })"><Plus class="h-4 w-4" />{{ t('Add') }}</Button>
            </div>
            <p v-for="(er, k) in sal.errors" :key="k" class="text-sm text-ledger-red">{{ er }}</p>
        </div>
        <template #footer><Button @click="close">{{ t('Cancel') }}</Button><Button variant="primary" :loading="sal.processing" @click="saveSalary">{{ t('Save') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'document'" :title="t('Upload document')" size="md" @close="close">
        <div class="space-y-3">
            <Field label="File (PDF or photo, up to 8 MB)" :error="doc.errors.file" required><input type="file" accept=".pdf,image/*" class="block w-full text-sm" @change="doc.file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Type"><select v-model="doc.type" class="input"><option v-for="(l, k) in docTypes" :key="k" :value="k">{{ t(l) }}</option></select></Field>
                <Field label="Expires on"><input v-model="doc.expires_on" type="date" class="input" /></Field>
            </div>
            <Field label="Title" :error="doc.errors.title" required><input v-model="doc.title" class="input" /></Field>
        </div>
        <template #footer><Button @click="close">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!doc.file || !doc.title" :loading="doc.processing" @click="saveDoc">{{ t('Upload') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'login'" :title="t('Link a login')" size="sm" @close="close">
        <select v-model="loginId" class="input"><option :value="null">—</option><option v-for="u in logins ?? []" :key="u.id" :value="u.id">{{ u.name }} · {{ u.email }}</option></select>
        <template #footer><Button @click="close">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!loginId" @click="linkLogin">{{ t('Link') }}</Button></template>
    </Modal>
</template>