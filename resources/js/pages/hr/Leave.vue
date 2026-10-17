<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { useFilters } from '@/composables/useFilters';
import { date, today } from '@/lib/format';
import { can } from '@/lib/perms';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ requests: any; types: any[]; holidays: any[]; filters: { status: string }; canApprove: boolean }>();
const page = usePage<any>();
const filters = useFilters('hr.leave', { status: props.filters.status });
const asking = ref(false);
const who = ref<any>(null);
const form = useForm({ contact_id: null as number | null, leave_type_id: props.types[0]?.id ?? null, start_date: today(), end_date: today(), half_day: false, reason: '' });
function submit() {
    form.contact_id = who.value?.id ?? null;
    form.post(route('hr.leave.store'), { preserveScroll: true, onSuccess: () => { asking.value = false; form.reset('reason'); } });
}
function decide(r: any, decision: string) {
    router.post(route('hr.leave.review', r.id), { decision }, { preserveScroll: true });
}
const holidayForm = useForm({ date: today(), name: '', name_ur: '', store_id: null as number | null, is_paid: true });
const addingHoliday = ref(false);
</script>

<template>
    <PageHeader :title="t('Leave')" :subtitle="t('Requests, approvals and public holidays.')">
        <template #actions><Button variant="primary" @click="asking = true">{{ t('Request leave') }}</Button></template>
    </PageHeader>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div>
            <Tabs :model-value="filters.status" :tabs="[{ key: 'pending', label: 'Waiting' }, { key: 'approved', label: 'Approved' }, { key: 'rejected', label: 'Rejected' }, { key: 'all', label: 'All' }]" @update:model-value="(v) => (filters.status = v)" />
            <section class="panel">
                <table class="table-base">
                    <thead><tr><th>{{ t('Employee') }}</th><th>{{ t('Type') }}</th><th>{{ t('Dates') }}</th><th class="num">{{ t('Days') }}</th><th>{{ t('Reason') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id">
                            <td class="font-medium">{{ r.contact?.name }}</td><td>{{ r.leave_type?.name }}</td><td>{{ date(r.start_date) }} – {{ date(r.end_date) }}</td><td class="num">{{ r.days }}</td>
                            <td class="text-ink-600">{{ r.reason }}<div v-if="r.review_note" class="text-[12px]">{{ r.reviewer?.name }}: {{ r.review_note }}</div></td><td><Badge :status="r.status" /></td>
                            <td class="whitespace-nowrap"><template v-if="r.status === 'pending' && canApprove"><Button size="sm" variant="primary" @click="decide(r, 'approve')">{{ t('Approve') }}</Button> <Button size="sm" variant="danger" @click="decide(r, 'reject')">{{ t('Reject') }}</Button></template>
                                <Button v-else-if="['pending', 'approved'].includes(r.status) && r.start_date >= today()" size="sm" variant="ghost" @click="decide(r, 'cancel')">{{ t('Cancel') }}</Button></td>
                        </tr>
                        <tr v-if="!requests.data.length"><td colspan="7" class="py-8 text-center text-ink-500">{{ t('Nothing here.') }}</td></tr>
                    </tbody>
                </table>
                <Pagination :meta="requests" />
            </section>
        </div>
        <aside class="space-y-5">
            <section class="panel">
                <div class="panel-head"><h2>{{ t('Holidays this year') }}</h2><Button v-if="can('hr.policies.manage')" size="sm" @click="addingHoliday = true">{{ t('Add') }}</Button></div>
                <ul class="divide-y divide-ink-100 text-sm"><li v-for="h in holidays" :key="h.id" class="flex gap-2 px-4 py-2"><span class="tabular-nums text-ink-500">{{ date(h.date) }}</span><span>{{ localName(h) }}</span></li><li v-if="!holidays.length" class="px-4 py-3 text-ink-500">—</li></ul>
            </section>
            <section class="panel p-4 text-sm"><h3 class="mb-2">{{ t('Leave types') }}</h3><ul><li v-for="ty in types" :key="ty.id">{{ localName(ty) }} <span class="text-ink-500">{{ ty.is_paid ? t('paid') : t('unpaid') }}</span></li></ul></section>
        </aside>
    </div>
    <Modal :open="asking" :title="t('Request leave')" size="md" @close="asking = false">
        <div class="space-y-3">
            <Field v-if="can('hr.leave.approve') || can('hr.employees.manage')" label="For (leave empty for yourself)"><ContactPicker v-model="who" type="employee" /></Field>
            <Field label="Type"><select v-model="form.leave_type_id" class="input"><option v-for="ty in types" :key="ty.id" :value="ty.id">{{ localName(ty) }}</option></select></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="From" :error="form.errors.start_date"><input v-model="form.start_date" type="date" class="input" /></Field><Field label="To" :error="form.errors.end_date"><input v-model="form.end_date" type="date" class="input" /></Field></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.half_day" type="checkbox" class="rounded border-ink-300" />{{ t('Half day') }}</label>
            <Field label="Reason"><input v-model="form.reason" class="input" /></Field>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="asking = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="submit">{{ t('Request') }}</Button></template>
    </Modal>
    <Modal :open="addingHoliday" :title="t('Add holiday')" size="sm" @close="addingHoliday = false">
        <div class="space-y-3">
            <Field label="Date"><input v-model="holidayForm.date" type="date" class="input" /></Field>
            <Field label="Name" :error="holidayForm.errors.name"><input v-model="holidayForm.name" class="input" :placeholder="t('Eid ul Fitr')" /></Field>
            <Field label="Name in Urdu"><input v-model="holidayForm.name_ur" class="input urdu" dir="rtl" /></Field>
            <Field label="Store"><select v-model="holidayForm.store_id" class="input"><option :value="null">{{ t('All stores') }}</option><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
        </div>
        <template #footer><Button @click="addingHoliday = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="holidayForm.processing" @click="holidayForm.post(route('hr.holidays.store'), { preserveScroll: true, onSuccess: () => (addingHoliday = false) })">{{ t('Save') }}</Button></template>
    </Modal>
</template>
