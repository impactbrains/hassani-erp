<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Pencil, Printer } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Stat from '@/components/ui/Stat.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { date, dateTime, money, today } from '@/lib/format';
import { can as hasPerm } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ pager?: any; customer: any; khata: any | null; promises: any[]; interactions: any[]; followUps: any[]; cases: any[]; invoices: any[]; orders: any[]; stats: any; filters: { from: string; to: string }; can: Record<string, boolean> }>();
const tab = ref(props.khata ? 'khata' : 'activity');
const period = ref({ ...props.filters });
function reloadStatement() {
    router.get(route('crm.customers.show', props.customer.id), period.value, { preserveScroll: true, preserveState: true, only: ['khata', 'filters'] });
}
const buckets: Record<string, string> = { current: 'Not yet due', '1_30': '1–30 days late', '31_60': '31–60 days', '61_90': '61–90 days', over_90: 'Over 90 days' };

const note = useForm({ kind: 'interaction', channel: 'call', summary: '', is_sensitive: false });
const follow = useForm({ kind: 'follow_up', title: '', notes: '', due_at: today(), priority: 'normal', assigned_to: null as number | null });
const kase = useForm({ kind: 'case', type: 'complaint', subject: '', description: '', priority: 'normal', due_at: '' });
const promise = useForm({ kind: 'promise', amount: '', promised_date: today(), note: '' });
const modal = ref<'' | 'follow' | 'case' | 'promise' | 'merge'>('');
const dup = ref<any>(null);
const caseEdit = ref<any>(null);
const caseForm = useForm({ status: 'in_progress', resolution: '' });

function save(form: any) {
    form.post(route('crm.customers.activity', props.customer.id), { preserveScroll: true, onSuccess: () => { form.reset(); modal.value = ''; } });
}
function merge() {
    if (!dup.value) return;
    router.post(route('crm.customers.merge', props.customer.id), { duplicate_id: dup.value.id }, { onSuccess: () => (modal.value = '') });
}
/** The complaints that can still be updated. The arrows in the Update window step through these, wrapping round at the ends. */
const openCases = computed(() => props.cases.filter((k: any) => !['resolved', 'closed'].includes(k.status)));
const casePosition = computed(() => openCases.value.findIndex((k: any) => k.id === caseEdit.value?.id));
function openCase(k: any) {
    caseEdit.value = k;
    caseForm.status = 'resolved';
    caseForm.resolution = '';
    caseForm.clearErrors();
}
function stepCase(by: number) {
    const n = openCases.value.length;
    if (n > 1 && casePosition.value >= 0) openCase(openCases.value[(casePosition.value + by + n) % n]);
}
function saveCase() {
    caseForm.put(route('crm.cases.update', caseEdit.value.id), { preserveScroll: true, onSuccess: () => (caseEdit.value = null) });
}
function print() {
    window.print();
}
const channels: Record<string, string> = { call: 'Call', visit: 'Visit', whatsapp: 'WhatsApp', sms: 'SMS', note: 'Note', email: 'E-mail' };
</script>

<template>
    <PageHeader :pager="pager" :title="customer.name" :subtitle="`${customer.code}${customer.area ? ' · ' + customer.area : ''}${customer.phone ? ' · ' + customer.phone : ''}`">
        <template #actions>
            <Badge v-if="!customer.is_active" status="inactive" />
            <Badge v-if="customer.customer_profile?.credit_status && customer.customer_profile.credit_status !== 'active'" :status="customer.customer_profile.credit_status" />
            <Button v-if="can.collect" :href="route('sales.collections', { customer_id: customer.id })">{{ t('Receive payment') }}</Button>
            <Button v-if="can.order" :href="route('sales.orders.create', { customer_id: customer.id })">{{ t('New order') }}</Button>
            <Button v-if="can.manage" :href="route('crm.customers.edit', customer.id)"><Pencil class="h-4 w-4" />{{ t('Edit') }}</Button>
            <Button v-if="can.merge" variant="ghost" @click="modal = 'merge'">{{ t('Merge duplicate') }}</Button>
        </template>
    </PageHeader>
    <div v-if="customer.urdu_name || customer.name_ur" class="urdu -mt-3 mb-4 text-lg text-ink-700" dir="rtl" style="text-align: start">{{ customer.name_ur }}</div>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        <Stat v-if="khata" :label="t('Khata balance')" :value="money(khata.balance)" :tone="Number(khata.balance) > 0 ? 'bad' : 'good'" />
        <Stat :label="t('Credit limit')" :value="money(customer.customer_profile?.credit_limit ?? 0)" :note="customer.customer_profile ? `${customer.customer_profile.payment_terms_days ?? 0} ${t('days to pay')}` : null" />
        <Stat :label="t('Total bought')" :value="money(stats?.lifetime ?? 0)" :note="`${stats?.orders ?? 0} ${t('invoices')}`" />
        <Stat :label="t('First purchase')" :value="stats?.first ? date(stats.first) : '—'" />
        <Stat :label="t('Last purchase')" :value="stats?.last ? date(stats.last) : '—'" />
    </div>

    <Tabs v-model="tab" :tabs="[...(khata ? [{ key: 'khata', label: 'Khata statement' }] : []), { key: 'activity', label: 'Notes and follow-ups', count: followUps.filter((f) => f.status === 'open').length }, { key: 'cases', label: 'Complaints', count: cases.filter((c) => ['open', 'in_progress'].includes(c.status)).length }, { key: 'sales', label: 'Invoices and orders' }, { key: 'details', label: 'Details' }]" />

    <template v-if="tab === 'khata' && khata">
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <div v-for="(l, k) in buckets" :key="k" class="panel p-3"><div class="text-[12.5px] text-ink-500">{{ t(l) }}</div><div class="text-lg font-semibold tabular-nums" :class="k !== 'current' && Number(khata.aging.buckets[k]) > 0 ? 'text-ledger-red' : ''">{{ money(khata.aging.buckets[k]) }}</div></div>
        </div>
        <section class="panel mb-4">
            <div class="panel-head no-print">
                <div class="flex flex-wrap items-end gap-2">
                    <label><span class="field-label">{{ t('From') }}</span><input v-model="period.from" type="date" class="input py-1.5" /></label>
                    <label><span class="field-label">{{ t('To') }}</span><input v-model="period.to" type="date" class="input py-1.5" /></label>
                    <Button size="sm" @click="reloadStatement">{{ t('Show') }}</Button>
                </div>
                <Button size="sm" @click="print"><Printer class="h-4 w-4" />{{ t('Print statement') }}</Button>
            </div>
            <div class="hidden p-4 print:block"><h2 class="text-lg font-semibold">{{ t('Khata statement') }} · {{ customer.name }} ({{ customer.code }})</h2><p>{{ date(khata.statement.from) }} – {{ date(khata.statement.to) }}</p></div>
            <table class="table-base khata">
                <thead><tr><th class="ps-12">{{ t('Date') }}</th><th>{{ t('Details') }}</th><th class="num">{{ t('Bought (debit)') }}</th><th class="num">{{ t('Paid (credit)') }}</th><th class="num">{{ t('Balance') }}</th></tr></thead>
                <tbody>
                    <tr class="bg-ink-50/50"><td class="ps-12">{{ date(khata.statement.from) }}</td><td class="font-medium">{{ t('Opening balance') }}</td><td></td><td></td><td class="num font-semibold">{{ money(khata.statement.opening) }}</td></tr>
                    <tr v-for="(l, i) in khata.statement.lines" :key="i">
                        <td class="ps-12">{{ date(l.date) }}</td><td>{{ l.description }} <span class="text-[12px] text-ink-500">{{ l.journal }}</span></td>
                        <td class="num">{{ Number(l.debit) ? money(l.debit) : '' }}</td><td class="num text-ledger-green">{{ Number(l.credit) ? money(l.credit) : '' }}</td>
                        <td class="num font-medium" :class="Number(l.balance) > 0 ? 'text-ledger-red' : ''">{{ money(l.balance) }}</td>
                    </tr>
                    <tr class="bg-ink-50/50"><td class="ps-12">{{ date(khata.statement.to) }}</td><td class="font-medium">{{ t('Closing balance') }}</td><td></td><td></td><td class="num text-base font-semibold">{{ money(khata.statement.closing) }}</td></tr>
                </tbody>
            </table>
        </section>
        <section class="panel no-print">
            <div class="panel-head"><h2>{{ t('Payment promises') }}</h2><Button size="sm" @click="modal = 'promise'">{{ t('Record promise') }}</Button></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="p in promises" :key="p.id" class="flex items-center gap-3 px-4 py-2 text-sm"><span class="font-medium tabular-nums">{{ money(p.amount) }}</span><span>{{ t('by') }} {{ date(p.promised_date) }}</span><Badge :status="p.status" /><span class="text-ink-500">{{ p.note }}</span><span class="ms-auto text-[12px] text-ink-500">{{ p.recorder?.name }}</span></li>
                <li v-if="!promises.length" class="px-4 py-4 text-sm text-ink-500">{{ t('No promises recorded.') }}</li>
            </ul>
        </section>
    </template>

    <div v-if="tab === 'activity'" class="grid gap-5 lg:grid-cols-2">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Notes, calls and visits') }}</h2></div>
            <form class="space-y-2 border-b border-ink-100 p-3" @submit.prevent="save(note)">
                <div class="flex gap-2"><select v-model="note.channel" class="input w-32 py-1.5"><option v-for="(l, k) in channels" :key="k" :value="k">{{ t(l) }}</option></select><input v-model="note.summary" class="input flex-1 py-1.5" :placeholder="t('What was said or agreed?')" /></div>
                <div class="flex items-center justify-between"><label v-if="hasPerm('crm.notes.sensitive')" class="flex items-center gap-2 text-[12.5px]"><input v-model="note.is_sensitive" type="checkbox" class="rounded border-ink-300" />{{ t('Private (managers only)') }}</label><span v-else></span><Button type="submit" size="sm" variant="primary" :disabled="!note.summary">{{ t('Add note') }}</Button></div>
            </form>
            <ul class="divide-y divide-ink-100">
                <li v-for="n in interactions" :key="n.id" class="px-4 py-2.5 text-sm"><div class="text-[12px] text-ink-500">{{ dateTime(n.occurred_at) }} · {{ t(channels[n.channel] ?? n.channel) }} · {{ n.user?.name }}<span v-if="n.is_sensitive"> · 🔒</span></div><p class="whitespace-pre-line">{{ n.summary }}</p></li>
                <li v-if="!interactions.length" class="px-4 py-4 text-sm text-ink-500">{{ t('No notes yet.') }}</li>
            </ul>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Follow-ups') }}</h2><Button v-if="hasPerm('crm.followups.manage')" size="sm" @click="modal = 'follow'">{{ t('Add follow-up') }}</Button></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="f in followUps" :key="f.id" class="flex items-start gap-3 px-4 py-2.5 text-sm" :class="f.status === 'done' ? 'text-ink-400' : ''">
                    <div class="flex-1"><div class="font-medium" :class="f.status === 'done' ? 'line-through' : ''">{{ f.title }}</div><div class="text-[12px]">{{ dateTime(f.due_at) }} · {{ f.assignee?.name }} · {{ t(f.priority) }}</div><p v-if="f.notes" class="text-[13px]">{{ f.notes }}</p></div>
                    <Button v-if="f.status !== 'done' && hasPerm('crm.followups.manage')" size="sm" @click="router.post(route('crm.follow-ups.done', f.id), {}, { preserveScroll: true })">{{ t('Done') }}</Button>
                </li>
                <li v-if="!followUps.length" class="px-4 py-4 text-sm text-ink-500">{{ t('Nothing planned.') }}</li>
            </ul>
        </section>
    </div>

    <section v-if="tab === 'cases'" class="panel">
        <div class="panel-head"><h2>{{ t('Complaints and requests') }}</h2><Button v-if="hasPerm('crm.cases.manage')" size="sm" variant="primary" @click="modal = 'case'">{{ t('New complaint') }}</Button></div>
        <table class="table-base">
            <thead><tr><th>{{ t('Number') }}</th><th>{{ t('Opened') }}</th><th>{{ t('Type') }}</th><th>{{ t('Subject') }}</th><th>{{ t('Priority') }}</th><th>{{ t('Owner') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="k in cases" :key="k.id">
                    <td>{{ k.number }}</td><td>{{ date(k.created_at) }}</td><td>{{ t(k.type.replace('_', ' ')) }}</td><td><div class="font-medium">{{ k.subject }}</div><div v-if="k.resolution" class="text-[12.5px] text-ink-600">{{ k.resolution }}</div></td>
                    <td><Badge :tone="k.priority === 'urgent' || k.priority === 'high' ? 'bad' : 'neutral'" :label="t(k.priority)" /></td><td>{{ k.owner?.name }}</td><td><Badge :status="k.status" /></td>
                    <td><Button v-if="hasPerm('crm.cases.manage') && !['resolved', 'closed'].includes(k.status)" size="sm" @click="openCase(k)">{{ t('Update') }}</Button></td>
                </tr>
                <tr v-if="!cases.length"><td colspan="8" class="py-6 text-center text-ink-500">{{ t('No complaints.') }}</td></tr>
            </tbody>
        </table>
    </section>

    <div v-if="tab === 'sales'" class="grid gap-5 lg:grid-cols-2">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Invoices') }}</h2></div>
            <table class="table-base">
                <thead><tr><th>{{ t('Number') }}</th><th>{{ t('Date') }}</th><th class="num">{{ t('Total') }}</th><th class="num">{{ t('Still owed') }}</th><th>{{ t('Status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="i in invoices" :key="i.id"><td><a :href="route('sales.invoices.show', i.id)" class="link">{{ i.number }}</a></td><td>{{ date(i.business_date) }}</td><td class="num">{{ money(i.total) }}</td><td class="num">{{ money(Number(i.total) - Number(i.amount_paid) - Number(i.amount_credited)) }}</td><td><Badge :status="i.payment_status" /></td></tr>
                    <tr v-if="!invoices.length"><td colspan="5" class="py-6 text-center text-ink-500">{{ t('No invoices yet.') }}</td></tr>
                </tbody>
            </table>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Orders') }}</h2></div>
            <table class="table-base">
                <thead><tr><th>{{ t('Number') }}</th><th>{{ t('Date') }}</th><th class="num">{{ t('Total') }}</th><th>{{ t('Status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders" :key="o.id"><td><a :href="route('sales.orders.show', o.id)" class="link">{{ o.number }}</a></td><td>{{ date(o.business_date) }}</td><td class="num">{{ money(o.total) }}</td><td><Badge :status="o.status" /></td></tr>
                    <tr v-if="!orders.length"><td colspan="4" class="py-6 text-center text-ink-500">{{ t('No orders yet.') }}</td></tr>
                </tbody>
            </table>
        </section>
    </div>

    <section v-if="tab === 'details'" class="panel grid gap-4 p-4 text-sm md:grid-cols-3">
        <div><span class="field-label">{{ t('Address') }}</span>{{ customer.address ?? '—' }}<br />{{ customer.area }} {{ customer.city }}</div>
        <div><span class="field-label">{{ t('Phones') }}</span>{{ customer.phone ?? '—' }} {{ customer.alt_phone ? ' / ' + customer.alt_phone : '' }}</div>
        <div><span class="field-label">{{ t('E-mail') }}</span>{{ customer.email ?? '—' }}</div>
        <div><span class="field-label">{{ t('Delivery instructions') }}</span>{{ customer.delivery_instructions ?? '—' }}</div>
        <div><span class="field-label">{{ t('Price list') }}</span>{{ customer.customer_profile?.price_book?.name ?? t('In-store (standard)') }}</div>
        <div><span class="field-label">{{ t('Sales agent') }}</span>{{ customer.customer_profile?.sales_agent?.name ?? '—' }}</div>
        <div><span class="field-label">{{ t('NTN / CNIC') }}</span>{{ customer.tax_number ?? '—' }}</div>
        <div><span class="field-label">{{ t('Offers by SMS / WhatsApp') }}</span>{{ customer.customer_profile?.marketing_consent ? t('Yes') : t('No') }}</div>
        <div v-if="customer.customer_profile?.credit_status_reason"><span class="field-label">{{ t('Credit note') }}</span>{{ customer.customer_profile.credit_status_reason }}</div>
    </section>

    <Modal :open="modal === 'follow'" :title="t('Add follow-up')" size="md" @close="modal = ''">
        <div class="space-y-3">
            <Field label="What to do" :error="follow.errors.title" required><input v-model="follow.title" class="input" :placeholder="t('Call about overdue Khata')" /></Field>
            <div class="grid grid-cols-2 gap-3"><Field label="When" :error="follow.errors.due_at"><input v-model="follow.due_at" type="date" class="input" /></Field><Field label="Priority"><select v-model="follow.priority" class="input"><option value="low">{{ t('low') }}</option><option value="normal">{{ t('normal') }}</option><option value="high">{{ t('high') }}</option></select></Field></div>
            <Field label="Notes"><textarea v-model="follow.notes" rows="2" class="input" /></Field>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="follow.processing" @click="save(follow)">{{ t('Save') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'case'" :title="t('New complaint')" size="md" @close="modal = ''">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <Field label="Type"><select v-model="kase.type" class="input"><option value="complaint">{{ t('Complaint') }}</option><option value="return_request">{{ t('Return request') }}</option><option value="quality">{{ t('Quality') }}</option><option value="delivery">{{ t('Delivery') }}</option><option value="billing">{{ t('Billing') }}</option><option value="other">{{ t('Other') }}</option></select></Field>
                <Field label="Priority"><select v-model="kase.priority" class="input"><option value="low">{{ t('low') }}</option><option value="normal">{{ t('normal') }}</option><option value="high">{{ t('high') }}</option><option value="urgent">{{ t('urgent') }}</option></select></Field>
            </div>
            <Field label="Subject" :error="kase.errors.subject" required><input v-model="kase.subject" class="input" /></Field>
            <Field label="Details"><textarea v-model="kase.description" rows="3" class="input" /></Field>
            <Field label="Resolve by"><input v-model="kase.due_at" type="date" class="input" /></Field>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="kase.processing" @click="save(kase)">{{ t('Save') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'promise'" :title="t('Payment promise')" size="sm" @close="modal = ''">
        <div class="space-y-3">
            <Field label="Amount" :error="promise.errors.amount" required><input v-model="promise.amount" type="number" min="0" class="input text-end" /></Field>
            <Field label="Will pay by" :error="promise.errors.promised_date"><input v-model="promise.promised_date" type="date" class="input" /></Field>
            <Field label="Note"><input v-model="promise.note" class="input" /></Field>
        </div>
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="primary" :loading="promise.processing" @click="save(promise)">{{ t('Save') }}</Button></template>
    </Modal>
    <Modal :open="modal === 'merge'" :title="t('Merge a duplicate into this customer')" size="md" @close="modal = ''">
        <p class="mb-3 text-sm text-ink-600">{{ t('The duplicate’s invoices, Khata and notes move to') }} <b>{{ customer.name }}</b>. {{ t('The duplicate is closed. This cannot be undone.') }}</p>
        <ContactPicker v-model="dup" type="customer" />
        <template #footer><Button @click="modal = ''">{{ t('Cancel') }}</Button><Button variant="danger" :disabled="!dup || dup.id === customer.id" @click="merge">{{ t('Merge') }}</Button></template>
    </Modal>
    <Modal :open="!!caseEdit" :title="`${t('Complaint')} ${caseEdit?.number ?? ''}`" size="md" @close="caseEdit = null">
        <div class="space-y-3">
            <Field label="Status"><select v-model="caseForm.status" class="input"><option value="in_progress">{{ t('Working on it') }}</option><option value="resolved">{{ t('Resolved') }}</option><option value="closed">{{ t('Closed') }}</option></select></Field>
            <Field label="What was done" :error="caseForm.errors.resolution"><textarea v-model="caseForm.resolution" rows="3" class="input" /></Field>
        </div>
        <template #footer>
            <div v-if="openCases.length > 1 && casePosition >= 0" class="me-auto inline-flex items-center rounded border border-ink-200 bg-white text-ink-700">
                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-s hover:bg-ink-50" :title="t('Previous complaint')" :aria-label="t('Previous complaint')" @click="stepCase(-1)"><ChevronLeft class="h-4 w-4 rtl:rotate-180" /></button>
                <span class="min-w-[4.5rem] select-none border-x border-ink-200 px-2 text-center text-[13px] leading-8 tabular-nums text-ink-600">{{ t(':position of :total', { position: casePosition + 1, total: openCases.length }) }}</span>
                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-e hover:bg-ink-50" :title="t('Next complaint')" :aria-label="t('Next complaint')" @click="stepCase(1)"><ChevronRight class="h-4 w-4 rtl:rotate-180" /></button>
            </div>
            <Button @click="caseEdit = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="caseForm.processing" @click="saveCase">{{ t('Save') }}</Button></template>
    </Modal>
</template>
