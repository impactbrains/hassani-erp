<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Field from '@/components/form/Field.vue';
import { date } from '@/lib/format';
import { clock, minutes } from '@/lib/hr';
import { t } from '@/lib/i18n';

/** Breaks tab: every break in a date range, who typed it in, and days above the allowed minutes. */
const props = defineProps<{ filters: { from: string; to: string; type: string | null; contact_id: number | null; over_only: boolean }; breaks: any; dayTotals: Record<string, { total: number; unpaid: number; over: number }>; allowed: number; employees: any[]; canManage: boolean; today: string }>();
const f = ref({ ...props.filters, type: props.filters.type ?? '', contact_id: props.filters.contact_id ?? '' });
function apply() {
    router.get(route('hr.breaks'), { ...f.value, type: f.value.type || undefined, contact_id: f.value.contact_id || undefined, over_only: f.value.over_only ? 1 : undefined }, { preserveScroll: true });
}
const typeLabel: Record<string, string> = { meal: 'Meal', prayer: 'Prayer', other: 'Other' };
const dayKey = (b: any) => `${b.contact_id}|${String(b.work_date).slice(0, 10)}`;
const over = (b: any) => props.dayTotals[dayKey(b)]?.over ?? 0;
const adding = ref(false);
const form = useForm({ contact_id: null as number | null, date: props.today, type: 'meal', from: '', to: '', unpaid: false, note: '' });
function save() {
    form.post(route('hr.breaks.store'), { preserveScroll: true, onSuccess: () => { adding.value = false; form.reset('from', 'to', 'note'); } });
}
function remove(b: any) {
    if (confirm(t('Remove this break?'))) router.delete(route('hr.breaks.destroy', b.id), { preserveScroll: true });
}
function endNow(b: any) {
    router.post(route('hr.breaks.end', b.id), {}, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('Breaks')" :subtitle="`${date(filters.from)} – ${date(filters.to)} · ${t('Allowed per day')}: ${allowed ? minutes(allowed) : t('no limit')}`">
        <template #actions>
            <Button :href="route('hr.attendance')">{{ t('Attendance') }}</Button>
            <Button v-if="canManage" variant="primary" @click="adding = true">{{ t('Add a break') }}</Button>
        </template>
    </PageHeader>
    <section class="panel mb-4 flex flex-wrap items-end gap-3 p-4">
        <Field label="From date"><input v-model="f.from" type="date" class="input w-40" /></Field>
        <Field label="To date"><input v-model="f.to" type="date" class="input w-40" /></Field>
        <Field label="Employee"><select v-model="f.contact_id" class="input w-48"><option value="">{{ t('Everyone') }}</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select></Field>
        <Field label="Type"><select v-model="f.type" class="input w-32"><option value="">{{ t('All') }}</option><option v-for="(l, k) in typeLabel" :key="k" :value="k">{{ t(l) }}</option></select></Field>
        <label class="flex items-center gap-2 pb-2 text-sm"><input v-model="f.over_only" type="checkbox" />{{ t('Only days over the limit') }}</label>
        <Button variant="primary" @click="apply">{{ t('Show') }}</Button>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Employee') }}</th><th>{{ t('Type') }}</th><th>{{ t('From') }}</th><th>{{ t('To') }}</th><th class="num">{{ t('Length') }}</th><th>{{ t('Pay') }}</th><th>{{ t('Day total') }}</th><th>{{ t('Entered by') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="b in breaks.data" :key="b.id">
                    <td>{{ date(b.work_date) }}</td>
                    <td class="font-medium">{{ b.contact?.name }}</td>
                    <td>{{ t(typeLabel[b.type] ?? b.type) }}</td>
                    <td>{{ clock(b.started_at) }}</td>
                    <td>{{ b.ended_at ? clock(b.ended_at) : '—' }}<span v-if="!b.ended_at" class="ms-1 text-[12px] text-haldi-800">{{ t('on break') }}</span></td>
                    <td class="num">{{ b.ended_at ? minutes(b.minutes) : '—' }}</td>
                    <td><Badge :tone="b.is_unpaid ? 'warn' : 'neutral'" :label="b.is_unpaid ? t('Unpaid') : t('Paid')" /></td>
                    <td>{{ minutes(dayTotals[dayKey(b)]?.total) }}<Badge v-if="over(b) > 0" class="ms-1" tone="bad" :label="`+${over(b)}m ${t('over')}`" /></td>
                    <td class="text-[12.5px]">{{ b.entered_by?.name ?? '—' }}<span class="text-ink-500"> · {{ b.entered_by_role === 'employee' ? t('by the employee') : t('by a manager') }}</span></td>
                    <td class="whitespace-nowrap text-end">
                        <Button v-if="!b.ended_at && canManage" size="sm" @click="endNow(b)">{{ t('End break') }}</Button>
                        <Button v-if="canManage" size="sm" variant="ghost" @click="remove(b)">{{ t('Remove') }}</Button>
                    </td>
                </tr>
                <tr v-if="!breaks.data.length"><td colspan="10" class="py-8 text-center text-ink-500">{{ t('No breaks in this period.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="breaks" />
    </section>
    <Modal :open="adding" :title="t('Add a break')" size="sm" @close="adding = false">
        <div class="space-y-3">
            <Field label="Employee" :error="form.errors.contact_id" required><select v-model="form.contact_id" class="input"><option :value="null">—</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select></Field>
            <Field label="Date" :error="form.errors.date"><input v-model="form.date" type="date" class="input" /></Field>
            <Field label="Type"><select v-model="form.type" class="input"><option v-for="(l, k) in typeLabel" :key="k" :value="k">{{ t(l) }}</option></select></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="From" :error="form.errors.from"><input v-model="form.from" type="time" class="input" /></Field><Field label="To" :error="form.errors.to"><input v-model="form.to" type="time" class="input" /></Field></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.unpaid" type="checkbox" />{{ t('Unpaid break') }}</label>
            <Field label="Note"><input v-model="form.note" class="input" /></Field>
        </div>
        <template #footer><Button @click="adding = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
