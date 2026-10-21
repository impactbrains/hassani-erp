<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { date } from '@/lib/format';
import { attendanceStatus, clock, minutes } from '@/lib/hr';
import { t } from '@/lib/i18n';

/** Today's board: who is in, late or absent; clock people in and out; review correction requests. */
const props = defineProps<{ date: string; employees: any[]; records: Record<string, any>; roster: Record<string, any[]>; breaks: Record<string, any[]>; breakAllowed: number; corrections: any[]; can: { manage: boolean; approve: boolean } }>();
const day = ref(props.date);
function go() {
    router.get(route('hr.attendance'), { date: day.value }, { preserveScroll: true });
}
function punch(id: number, direction: 'in' | 'out') {
    router.post(route('hr.attendance.punch'), { contact_id: id, direction }, { preserveScroll: true });
}
const openBreak = (id: number) => (props.breaks[id] ?? []).find((b: any) => !b.ended_at);
function startBreak(id: number, ev: Event) {
    const el = ev.target as HTMLSelectElement;
    if (!el.value) return;
    router.post(route('hr.breaks.start'), { contact_id: id, type: el.value }, { preserveScroll: true });
    el.value = '';
}
function endBreak(b: any) {
    router.post(route('hr.breaks.end', b.id), {}, { preserveScroll: true });
}
const fixing = ref<any>(null);
const fix = useForm({ contact_id: null as number | null, date: props.date, clock_in: '', clock_out: '', reason: '' });
function openFix(e: any) {
    fixing.value = e;
    fix.contact_id = e.id;
    fix.date = props.date;
    fix.clock_in = '';
    fix.clock_out = '';
    fix.reason = '';
}
function saveFix() {
    fix.transform((d) => ({ ...d, clock_in: d.clock_in || null, clock_out: d.clock_out || null })).post(route('hr.attendance.corrections'), { preserveScroll: true, onSuccess: () => (fixing.value = null) });
}
function review(c: any, approve: boolean) {
    router.post(route('hr.attendance.corrections.review', c.id), { approve }, { preserveScroll: true });
}
const tone = (s: string) => (s === 'absent' ? 'bad' : s === 'late' ? 'warn' : ['present', 'half_day'].includes(s) ? 'good' : 'neutral');
</script>

<template>
    <PageHeader :title="t('Attendance')" :subtitle="date(props.date)">
        <template #actions>
            <input v-model="day" type="date" class="input w-44" @change="go" />
            <Button :href="route('hr.breaks')">{{ t('Breaks') }}</Button>
            <Button :href="route('hr.roster')">{{ t('Roster') }}</Button>
        </template>
    </PageHeader>
    <section v-if="corrections.length && can.approve" class="panel mb-5">
        <div class="panel-head"><h2>{{ t('Correction requests') }}</h2></div>
        <ul class="divide-y divide-ink-100">
            <li v-for="c in corrections" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                <span class="font-medium">{{ c.contact?.name }}</span><span>{{ date(c.work_date) }}</span><span>{{ t('In') }} {{ c.requested_clock_in ? clock(c.requested_clock_in) : '—' }} · {{ t('Out') }} {{ c.requested_clock_out ? clock(c.requested_clock_out) : '—' }}</span>
                <span class="text-ink-600">{{ c.reason }}</span><span class="text-[12px] text-ink-500">{{ c.requester?.name }}</span>
                <span class="ms-auto flex gap-2"><Button size="sm" variant="primary" @click="review(c, true)">{{ t('Approve') }}</Button><Button size="sm" variant="danger" @click="review(c, false)">{{ t('Reject') }}</Button></span>
            </li>
        </ul>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Employee') }}</th><th>{{ t('Shift') }}</th><th>{{ t('In') }}</th><th>{{ t('Out') }}</th><th>{{ t('Status') }}</th><th class="num">{{ t('Late') }}</th><th class="num">{{ t('Break') }}</th><th class="num">{{ t('Worked') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="e in employees" :key="e.id">
                    <td class="font-medium">{{ e.name }}</td>
                    <td class="text-[12.5px]">{{ (roster[e.id] ?? []).map((r: any) => `${r.work_shift?.name} ${String(r.work_shift?.start_time).slice(0, 5)}–${String(r.work_shift?.end_time).slice(0, 5)}`).join(', ') || '—' }}</td>
                    <td>{{ clock(records[e.id]?.clock_in_at) }}</td><td>{{ clock(records[e.id]?.clock_out_at) }}</td>
                    <td><Badge v-if="records[e.id]" :tone="tone(records[e.id].status)" :label="t(attendanceStatus[records[e.id].status] ?? records[e.id].status)" /><span v-else class="text-ink-400">—</span></td>
                    <td class="num">{{ minutes(records[e.id]?.late_minutes) }}</td><td class="num">
                        {{ minutes(records[e.id]?.break_minutes) }}
                        <Badge v-if="records[e.id]?.break_over_minutes > 0" tone="warn" :label="`+${records[e.id].break_over_minutes}m ${t('over')}`" :title="`${t('Allowed')}: ${breakAllowed}m`" />
                        <span v-if="openBreak(e.id)" class="ms-1 text-[12px] text-haldi-800">{{ t('on break') }}</span>
                    </td>
                    <td class="num">{{ minutes(records[e.id]?.worked_minutes) }}</td>
                    <td class="whitespace-nowrap text-end">
                        <template v-if="can.manage && props.date === new Date().toISOString().slice(0, 10)">
                            <Button v-if="!records[e.id]?.clock_in_at" size="sm" variant="primary" @click="punch(e.id, 'in')">{{ t('Clock in') }}</Button>
                            <template v-else-if="!records[e.id]?.clock_out_at">
                                <Button v-if="openBreak(e.id)" size="sm" variant="primary" @click="endBreak(openBreak(e.id))">{{ t('End break') }}</Button>
                                <select v-else class="input inline-block w-28 py-1 text-[13px]" :aria-label="t('Start break')" @change="startBreak(e.id, $event)"><option value="">{{ t('Start break…') }}</option><option value="meal">{{ t('Meal') }}</option><option value="prayer">{{ t('Prayer') }}</option><option value="other">{{ t('Other') }}</option></select>
                                <Button size="sm" @click="punch(e.id, 'out')">{{ t('Clock out') }}</Button>
                            </template>
                        </template>
                        <Button v-if="can.manage" size="sm" variant="ghost" @click="openFix(e)">{{ t('Correct') }}</Button>
                    </td>
                </tr>
                <tr v-if="!employees.length"><td colspan="9" class="py-8 text-center text-ink-500">{{ t('No employees in this store.') }}</td></tr>
            </tbody>
        </table>
    </section>
    <Modal :open="!!fixing" :title="`${t('Correct attendance')}: ${fixing?.name ?? ''}`" size="sm" @close="fixing = null">
        <div class="space-y-3">
            <Field label="Date" :error="fix.errors.date"><input v-model="fix.date" type="date" class="input" /></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="In" :error="fix.errors.clock_in"><input v-model="fix.clock_in" type="time" class="input" /></Field><Field label="Out" :error="fix.errors.clock_out"><input v-model="fix.clock_out" type="time" class="input" /></Field></div>
            <Field label="Reason" :error="fix.errors.reason" required><input v-model="fix.reason" class="input" :placeholder="t('Forgot to clock out')" /></Field>
            <p class="text-[12.5px] text-ink-500">{{ t('Another manager approves the correction; the original times are kept.') }}</p>
        </div>
        <template #footer><Button @click="fixing = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="fix.processing" @click="saveFix">{{ t('Request') }}</Button></template>
    </Modal>
</template>
