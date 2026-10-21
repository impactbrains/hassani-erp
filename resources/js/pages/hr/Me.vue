<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { date, money, today as todayStr } from '@/lib/format';
import { attendanceStatus, clock, minutes } from '@/lib/hr';
import { localName, t } from '@/lib/i18n';

/** "My work": clock in/out, my shifts, leave, payslips and Peshgi. */
const props = defineProps<{ employee: any | null; today?: any; breaksToday?: any[]; attendance?: any[]; shifts?: any[]; leave?: any[]; leaveTypes?: any[]; payslips?: any[]; advances?: any[] }>();
function punch(direction: 'in' | 'out') {
    router.post(route('hr.attendance.punch'), { direction }, { preserveScroll: true });
}
const modal = ref<'' | 'leave' | 'advance' | 'fix' | 'break'>('');
const openBreak = () => (props.breaksToday ?? []).find((b: any) => !b.ended_at);
const breakTotal = () => (props.breaksToday ?? []).reduce((n: number, b: any) => n + (b.minutes || 0), 0);
function startBreak(type: string) {
    router.post(route('hr.breaks.start'), { type }, { preserveScroll: true });
}
function endBreak() {
    router.post(route('hr.breaks.end', openBreak().id), {}, { preserveScroll: true });
}
const breakForm = useForm({ date: todayStr(), type: 'meal', from: '', to: '', unpaid: false, note: '' });
const leaveForm = useForm({ leave_type_id: props.leaveTypes?.[0]?.id ?? null, start_date: todayStr(), end_date: todayStr(), half_day: false, reason: '' });
const next = new Date();
next.setMonth(next.getMonth() + 1);
const advForm = useForm({ amount: '', installments: 3, recovery_start_month: next.toISOString().slice(0, 7), reason: '' });
const fixForm = useForm({ date: todayStr(), clock_in: '', clock_out: '', reason: '' });
const done = { preserveScroll: true, onSuccess: () => (modal.value = '') };
</script>

<template>
    <PageHeader :title="t('My work')" :subtitle="employee?.name" />
    <div v-if="!employee" class="panel p-8 text-center text-ink-600">{{ t('Your login is not linked to an employee record yet. Ask the owner to link it under HR → Employees.') }}</div>
    <template v-else>
        <section class="panel mb-5 flex flex-wrap items-center gap-4 p-5">
            <div class="flex-1">
                <div class="text-[13px] text-ink-500">{{ t('Today') }}</div>
                <div class="text-lg font-semibold">
                    <template v-if="today?.clock_in_at">{{ t('In at') }} {{ clock(today.clock_in_at) }}<span v-if="today.clock_out_at"> · {{ t('out at') }} {{ clock(today.clock_out_at) }}</span></template>
                    <template v-else>{{ t('Not clocked in yet') }}</template>
                </div>
                <div v-if="today?.late_minutes" class="text-sm text-haldi-800">{{ minutes(today.late_minutes) }} {{ t('late') }}</div>
                <div v-if="openBreak()" class="text-sm text-haldi-800">{{ t('On a break since') }} {{ clock(openBreak().started_at) }}</div>
                <div v-else-if="breakTotal()" class="text-sm text-ink-600">{{ t('Breaks today') }}: {{ minutes(breakTotal()) }}</div>
            </div>
            <Button v-if="!today?.clock_in_at" variant="primary" size="lg" @click="punch('in')">{{ t('Clock in') }}</Button>
            <template v-else-if="!today?.clock_out_at">
                <Button v-if="openBreak()" variant="primary" size="lg" @click="endBreak">{{ t('End break') }}</Button>
                <template v-else>
                    <Button size="lg" @click="startBreak('meal')">{{ t('Meal break') }}</Button>
                    <Button size="lg" @click="startBreak('prayer')">{{ t('Prayer break') }}</Button>
                    <Button size="lg" variant="ghost" @click="startBreak('other')">{{ t('Other break') }}</Button>
                </template>
                <Button size="lg" @click="punch('out')">{{ t('Clock out') }}</Button>
            </template>
            <Button v-if="today?.clock_in_at" variant="ghost" @click="modal = 'break'">{{ t('Add a missed break') }}</Button>
            <Button variant="ghost" @click="modal = 'fix'">{{ t('Wrong time?') }}</Button>
        </section>
        <div class="grid gap-5 lg:grid-cols-3">
            <section class="panel">
                <div class="panel-head"><h2>{{ t('My shifts') }}</h2></div>
                <ul class="divide-y divide-ink-100 text-sm"><li v-for="s in shifts" :key="s.id" class="flex justify-between px-4 py-2"><span>{{ date(s.work_date) }}</span><span>{{ s.work_shift?.name }} {{ String(s.work_shift?.start_time).slice(0, 5) }}–{{ String(s.work_shift?.end_time).slice(0, 5) }}</span></li><li v-if="!shifts?.length" class="px-4 py-3 text-ink-500">{{ t('No shifts planned.') }}</li></ul>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Leave') }}</h2><Button size="sm" @click="modal = 'leave'">{{ t('Request') }}</Button></div>
                <div class="border-b border-ink-100 px-4 py-2 text-sm"><div v-for="lt in leaveTypes" :key="lt.id" class="flex justify-between"><span>{{ localName(lt) }}</span><span class="tabular-nums">{{ lt.remaining }} {{ t('left') }}</span></div></div>
                <ul class="divide-y divide-ink-100 text-sm"><li v-for="l in leave" :key="l.id" class="flex items-center gap-2 px-4 py-2">{{ l.leave_type?.name }} · {{ date(l.start_date) }} – {{ date(l.end_date) }}<Badge class="ms-auto" :status="l.status" /></li></ul>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Payslips') }}</h2></div>
                <ul class="divide-y divide-ink-100 text-sm"><li v-for="p in payslips" :key="p.id" class="flex justify-between px-4 py-2"><a :href="route('hr.payslips.show', p.id)" class="link" target="_blank">{{ date(p.run?.period_start) }}</a><span class="font-medium tabular-nums">{{ money(p.net_pay) }}</span></li><li v-if="!payslips?.length" class="px-4 py-3 text-ink-500">—</li></ul>
                <div class="panel-head border-t"><h2>{{ t('Peshgi') }}</h2><Button size="sm" @click="modal = 'advance'">{{ t('Request') }}</Button></div>
                <ul class="divide-y divide-ink-100 text-sm"><li v-for="a in advances" :key="a.id" class="flex items-center gap-2 px-4 py-2">{{ money(a.amount) }} · {{ t('recovered') }} {{ money(a.recovered_amount) }}<Badge class="ms-auto" :status="a.status" /></li></ul>
            </section>
            <section class="panel lg:col-span-3">
                <div class="panel-head"><h2>{{ t('My attendance') }}</h2></div>
                <table class="table-base">
                    <thead><tr><th>{{ t('Date') }}</th><th>{{ t('In') }}</th><th>{{ t('Out') }}</th><th>{{ t('Status') }}</th><th class="num">{{ t('Late') }}</th><th class="num">{{ t('Worked') }}</th></tr></thead>
                    <tbody><tr v-for="a in attendance" :key="a.id"><td>{{ date(a.work_date) }}</td><td>{{ clock(a.clock_in_at) }}</td><td>{{ clock(a.clock_out_at) }}</td><td>{{ t(attendanceStatus[a.status] ?? a.status) }}</td><td class="num">{{ minutes(a.late_minutes) }}</td><td class="num">{{ minutes(a.worked_minutes) }}</td></tr></tbody>
                </table>
            </section>
        </div>
    </template>
    <Modal :open="modal === 'leave'" :title="t('Request leave')" size="md" @close="modal = ''">
        <div class="space-y-3">
            <Field label="Type"><select v-model="leaveForm.leave_type_id" class="input"><option v-for="lt in leaveTypes" :key="lt.id" :value="lt.id">{{ localName(lt) }}</option></select></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="From"><input v-model="leaveForm.start_date" type="date" class="input" /></Field><Field label="To" :error="leaveForm.errors.end_date"><input v-model="leaveForm.end_date" type="date" class="input" /></Field></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="leaveForm.half_day" type="checkbox" class="rounded border-ink-300" />{{ t('Half day') }}</label>
            <Field label="Reason"><input v-model="leaveForm.reason" class="input" /></Field>
            <p v-for="(e, k) in leaveForm.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="leaveForm.processing" @click="leaveForm.post(route('hr.leave.store'), done)">{{ t('Request') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'advance'" :title="t('Request Peshgi')" size="sm" @close="modal = ''">
        <div class="space-y-3">
            <Field label="Amount" :error="advForm.errors.amount"><input v-model="advForm.amount" type="number" min="0" class="input text-end" /></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="Instalments"><input v-model="advForm.installments" type="number" min="1" class="input text-end" /></Field><Field label="Recover from"><input v-model="advForm.recovery_start_month" type="month" class="input" /></Field></div>
            <Field label="Reason"><input v-model="advForm.reason" class="input" /></Field>
            <p v-for="(e, k) in advForm.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="advForm.processing" @click="advForm.post(route('hr.advances.store'), done)">{{ t('Request') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'fix'" :title="t('Correct my attendance')" size="sm" @close="modal = ''">
        <div class="space-y-3">
            <Field label="Date" :error="fixForm.errors.date"><input v-model="fixForm.date" type="date" class="input" /></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="In"><input v-model="fixForm.clock_in" type="time" class="input" /></Field><Field label="Out"><input v-model="fixForm.clock_out" type="time" class="input" /></Field></div>
            <Field label="Reason" :error="fixForm.errors.reason"><input v-model="fixForm.reason" class="input" /></Field>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="fixForm.processing" @click="fixForm.transform((d) => ({ ...d, clock_in: d.clock_in || null, clock_out: d.clock_out || null })).post(route('hr.attendance.corrections'), done)">{{ t('Request') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'break'" :title="t('Add a missed break')" size="sm" @close="modal = ''">
        <div class="space-y-3">
            <Field label="Date" :error="breakForm.errors.date"><input v-model="breakForm.date" type="date" class="input" /></Field>
            <Field label="Type"><select v-model="breakForm.type" class="input"><option value="meal">{{ t('Meal') }}</option><option value="prayer">{{ t('Prayer') }}</option><option value="other">{{ t('Other') }}</option></select></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="From" :error="breakForm.errors.from"><input v-model="breakForm.from" type="time" class="input" /></Field><Field label="To" :error="breakForm.errors.to"><input v-model="breakForm.to" type="time" class="input" /></Field></div>
            <p class="text-[12.5px] text-ink-500">{{ t('Your manager can see that you entered this yourself.') }}</p>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="breakForm.processing" @click="breakForm.post(route('hr.breaks.store'), done)">{{ t('Save') }}</Button></template>
    </Modal>
</template>
