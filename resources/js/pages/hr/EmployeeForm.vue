<script setup lang="ts">
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import { today } from '@/lib/format';
import { employmentTypes, roleTypes } from '@/lib/hr';
import { t } from '@/lib/i18n';

const props = defineProps<{ employee: any | null; showSensitive: boolean; departments: any[]; positions: any[]; managers: any[]; canCreateLogin?: boolean; loginRoles?: { id: number; name: string; description: string | null }[]; hasLogin?: string | null; minPasswordLength?: number }>();
const page = usePage<any>();
const e = props.employee;
const presetRole = new URLSearchParams(page.url.split('?')[1] ?? '').get('role_type'); // e.g. the Riders page sends role_type=rider
const d = e?.employee_detail ?? {};
const sensitiveKeys = ['cnic', 'cnic_expiry', 'bank_name', 'bank_account', 'date_of_birth', 'father_name', 'private_address', 'eobi_no', 'social_security_no'];
const base: Record<string, any> = {
    name: e?.name ?? '', name_ur: e?.name_ur ?? '', phone: e?.phone ?? '', email: e?.email ?? '', address: e?.address ?? '', city: e?.city ?? 'Karachi',
    store_id: d.store_id ?? page.props.auth?.store_id ?? null, department_id: d.department_id ?? null, job_position_id: d.job_position_id ?? null, job_title: d.job_title ?? '', role_type: d.role_type ?? (presetRole && presetRole in roleTypes ? presetRole : 'cashier'),
    manager_contact_id: d.manager_contact_id ?? null, employment_type: d.employment_type ?? 'probation', joining_date: d.joining_date ? String(d.joining_date).slice(0, 10) : today(), probation_end_date: d.probation_end_date ? String(d.probation_end_date).slice(0, 10) : '',
    create_login: false, login_role_id: null as number | null, login_password: '',
    gender: d.gender ?? null, marital_status: d.marital_status ?? null, emergency_contact_name: d.emergency_contact_name ?? '', emergency_contact_phone: d.emergency_contact_phone ?? '', emergency_contact_relation: d.emergency_contact_relation ?? '', education_level: d.education_level ?? '', note: d.note ?? '',
};
if (props.showSensitive) sensitiveKeys.forEach((k) => (base[k] = d[k] ? (k.includes('date') || k.includes('expiry') ? String(d[k]).slice(0, 10) : d[k]) : ''));
const form = useForm<any>(base);
const positions = computed(() => props.positions.filter((p) => !form.department_id || !p.department_id || p.department_id === form.department_id));
function submit() {
    form.transform((x: any) => Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v === '' ? null : v])));
    e ? form.put(route('hr.employees.update', e.id)) : form.post(route('hr.employees.store'));
}
</script>

<template>
    <PageHeader :title="e ? `${t('Edit')} ${e.name}` : t('Add employee')" />
    <div class="grid max-w-6xl gap-5 lg:grid-cols-2">
        <section class="panel space-y-3 p-4">
            <h3>{{ t('Personal') }}</h3>
            <Field label="Full name" :error="form.errors.name" required><input v-model="form.name" class="input" /></Field>
            <Field label="Name in Urdu"><input v-model="form.name_ur" class="input urdu" dir="rtl" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Mobile"><input v-model="form.phone" class="input" /></Field>
                <Field label="E-mail" :error="form.errors.email"><input v-model="form.email" type="email" class="input" /></Field>
                <Field label="Gender"><select v-model="form.gender" class="input"><option :value="null">—</option><option value="male">{{ t('Male') }}</option><option value="female">{{ t('Female') }}</option><option value="other">{{ t('Other') }}</option></select></Field>
                <Field label="Marital status"><select v-model="form.marital_status" class="input"><option :value="null">—</option><option value="single">{{ t('Single') }}</option><option value="married">{{ t('Married') }}</option><option value="widowed">{{ t('Widowed') }}</option><option value="divorced">{{ t('Divorced') }}</option></select></Field>
            </div>
            <Field label="Address"><input v-model="form.address" class="input" /></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="City"><input v-model="form.city" class="input" /></Field><Field label="Education"><input v-model="form.education_level" class="input" /></Field></div>
            <h3 class="pt-2">{{ t('Emergency contact') }}</h3>
            <div class="grid grid-cols-3 gap-3"><Field label="Name"><input v-model="form.emergency_contact_name" class="input" /></Field><Field label="Phone"><input v-model="form.emergency_contact_phone" class="input" /></Field><Field label="Relation"><input v-model="form.emergency_contact_relation" class="input" /></Field></div>
        </section>
        <div class="space-y-5">
            <section class="panel space-y-3 p-4">
                <h3>{{ t('Job') }}</h3>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="Store"><select v-model="form.store_id" class="input"><option :value="null">—</option><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
                    <Field label="Job type"><select v-model="form.role_type" class="input"><option v-for="(l, k) in roleTypes" :key="k" :value="k">{{ t(l) }}</option></select></Field>
                    <Field label="Department"><select v-model="form.department_id" class="input"><option :value="null">—</option><option v-for="x in departments" :key="x.id" :value="x.id">{{ x.name }}</option></select></Field>
                    <Field label="Position"><select v-model="form.job_position_id" class="input"><option :value="null">—</option><option v-for="x in positions" :key="x.id" :value="x.id">{{ x.name }}</option></select></Field>
                    <Field label="Job title"><input v-model="form.job_title" class="input" /></Field>
                    <Field label="Reports to"><select v-model="form.manager_contact_id" class="input"><option :value="null">—</option><option v-for="m in managers.filter((x) => x.id !== e?.id)" :key="m.id" :value="m.id">{{ m.name }}</option></select></Field>
                    <Field label="Employment" :error="form.errors.employment_type"><select v-model="form.employment_type" class="input"><option v-for="(l, k) in employmentTypes" :key="k" :value="k">{{ t(l) }}</option></select></Field>
                    <Field label="Joining date" :error="form.errors.joining_date" required><input v-model="form.joining_date" type="date" class="input" /></Field>
                    <Field label="Probation ends" :error="form.errors.probation_end_date"><input v-model="form.probation_end_date" type="date" class="input" /></Field>
                </div>
                <p class="text-[12.5px] text-ink-500">{{ t('Riders appear on the rider board, sales agents can be chosen on orders.') }}</p>
            </section>
            <section v-if="showSensitive" class="panel space-y-3 border-haldi-300 p-4">
                <h3>{{ t('Identity and bank (private)') }}</h3>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="CNIC" :error="form.errors.cnic"><input v-model="form.cnic" class="input" placeholder="42101-1234567-1" /></Field>
                    <Field label="CNIC expiry"><input v-model="form.cnic_expiry" type="date" class="input" /></Field>
                    <Field label="Father’s name"><input v-model="form.father_name" class="input" /></Field>
                    <Field label="Date of birth" :error="form.errors.date_of_birth"><input v-model="form.date_of_birth" type="date" class="input" /></Field>
                    <Field label="Bank"><input v-model="form.bank_name" class="input" /></Field>
                    <Field label="Account / IBAN"><input v-model="form.bank_account" class="input" /></Field>
                    <Field label="EOBI no."><input v-model="form.eobi_no" class="input" /></Field>
                    <Field label="Social security no."><input v-model="form.social_security_no" class="input" /></Field>
                </div>
                <Field label="Home address (private)"><input v-model="form.private_address" class="input" /></Field>
            </section>
            <section v-if="canCreateLogin" class="panel space-y-3 p-4">
                <label class="flex items-center gap-2 font-medium"><input v-model="form.create_login" type="checkbox" />{{ t('Also give this employee a login') }}</label>
                <p class="text-[12.5px] text-ink-500">{{ t('They sign in with the e-mail above. You choose a temporary password (or leave it empty and one is made for you); they must change it the first time they sign in. If you later resign or terminate the employee, the login is switched off.') }}</p>
                <template v-if="form.create_login">
                    <Field label="Role" :error="form.errors.login_role_id" required>
                        <select v-model="form.login_role_id" class="input"><option :value="null">—</option><option v-for="r in loginRoles ?? []" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                    </Field>
                    <p class="text-[12.5px] text-ink-500">{{ t('The role applies to the store chosen above. You can only give roles whose permissions you hold yourself.') }}</p>
                    <Field label="Temporary password" :error="form.errors.login_password"><input v-model="form.login_password" type="text" autocomplete="off" class="input" :placeholder="t('Leave empty to make one for me')" /></Field>
                    <p class="text-[12.5px] text-ink-500">{{ t('At least :n characters with letters and numbers.', { n: Math.max(8, minPasswordLength ?? 10) }) }}</p>
                </template>
            </section>
            <p v-else-if="hasLogin" class="text-[12.5px] text-ink-500">{{ t('Login') }}: {{ hasLogin }}</p>
            <Field label="Notes"><textarea v-model="form.note" rows="2" class="input" /></Field>
            <p v-for="(er, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ er }}</p>
            <div class="flex justify-end gap-2"><Button :href="e ? route('hr.employees.show', e.id) : route('hr.employees.index')">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button></div>
        </div>
    </div>
</template>
