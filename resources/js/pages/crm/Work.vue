<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { date, dateTime, money, today } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ followUps: any[]; cases: any[]; promises: any[]; filters: { mine: string } }>();
const now = today();
// New complaint. No customer picked = a walk-in customer (name and phone are optional).
const adding = ref(false);
const customer = ref<any | null>(null);
const kase = useForm({ type: 'complaint', priority: 'normal', subject: '', description: '', due_at: '', walk_in_name: '', walk_in_phone: '' });
function saveCase() {
    kase.transform((d) => ({ ...d, customer_id: customer.value?.id ?? null, walk_in_name: customer.value ? null : d.walk_in_name || null, walk_in_phone: customer.value ? null : d.walk_in_phone || null, description: d.description || null, due_at: d.due_at || null }))
        .post(route('crm.cases.store'), { preserveScroll: true, onSuccess: () => { kase.reset(); customer.value = null; adding.value = false; } });
}
function toggleMine() {
    router.get(route('crm.work'), { mine: props.filters.mine === '1' ? '0' : '1' }, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('Follow-ups')" :subtitle="t('Calls to make, complaints to solve and payments promised in the next 3 days.')">
        <template #actions><Button v-if="can('crm.followups.manage')" @click="toggleMine">{{ filters.mine === '1' ? t('Show everyone’s') : t('Show only mine') }}</Button></template>
    </PageHeader>
    <div class="grid gap-5 xl:grid-cols-3">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Follow-ups') }}</h2><span class="text-sm text-ink-500">{{ followUps.length }}</span></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="f in followUps" :key="f.id" class="flex items-start gap-2 px-4 py-2.5 text-sm">
                    <div class="flex-1">
                        <a :href="route('crm.customers.show', f.contact_id)" class="font-medium hover:underline">{{ f.contact?.name }}</a>
                        <div>{{ f.title }}</div>
                        <div class="text-[12px]" :class="String(f.due_at).slice(0, 10) < now ? 'font-medium text-ledger-red' : 'text-ink-500'">{{ dateTime(f.due_at) }} · {{ f.assignee?.name }}</div>
                    </div>
                    <Button size="sm" @click="router.post(route('crm.follow-ups.done', f.id), {}, { preserveScroll: true })">{{ t('Done') }}</Button>
                </li>
                <li v-if="!followUps.length" class="px-4 py-6 text-center text-sm text-ink-500">{{ t('All done.') }}</li>
            </ul>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Open complaints') }}</h2><span class="ms-auto text-sm text-ink-500">{{ cases.length }}</span><Button v-if="can('crm.cases.manage')" size="sm" variant="primary" @click="adding = true">{{ t('New complaint') }}</Button></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="k in cases" :key="k.id" class="px-4 py-2.5 text-sm">
                    <div class="flex items-center gap-2"><a v-if="k.contact" :href="route('crm.customers.show', k.contact_id)" class="font-medium hover:underline">{{ k.contact.name }}</a><span v-else class="font-medium">{{ t('Walk-in customer') }}<span v-if="k.walk_in_name || k.walk_in_phone" class="font-normal text-ink-600"> · {{ [k.walk_in_name, k.walk_in_phone].filter(Boolean).join(', ') }}</span></span><Badge :tone="['urgent', 'high'].includes(k.priority) ? 'bad' : 'neutral'" :label="t(k.priority)" /><Badge :status="k.status" /></div>
                    <div>{{ k.number }} · {{ k.subject }}</div>
                    <div class="text-[12px] text-ink-500">{{ k.owner?.name }}<span v-if="k.due_at"> · {{ t('by') }} {{ date(k.due_at) }}</span></div>
                </li>
                <li v-if="!cases.length" class="px-4 py-6 text-center text-sm text-ink-500">{{ t('No open complaints.') }}</li>
            </ul>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Promised payments') }}</h2><span class="text-sm text-ink-500">{{ promises.length }}</span></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="p in promises" :key="p.id" class="flex items-center gap-2 px-4 py-2.5 text-sm">
                    <div class="flex-1"><a :href="route('crm.customers.show', p.contact_id)" class="font-medium hover:underline">{{ p.contact?.name }}</a><div class="text-[12px]" :class="p.promised_date < now ? 'text-ledger-red' : 'text-ink-500'">{{ date(p.promised_date) }}<span v-if="p.note"> · {{ p.note }}</span></div></div>
                    <span class="font-semibold tabular-nums">{{ money(p.amount) }}</span>
                    <Button v-if="can('sales.payments.receive')" size="sm" :href="route('sales.collections', { customer_id: p.contact_id })">{{ t('Receive') }}</Button>
                </li>
                <li v-if="!promises.length" class="px-4 py-6 text-center text-sm text-ink-500">{{ t('No promises due.') }}</li>
            </ul>
        </section>
    </div>

    <Modal :open="adding" :title="t('New complaint')" size="md" @close="adding = false">
        <div class="space-y-3">
            <Field label="Customer">
                <ContactPicker v-model="customer" type="customer" :placeholder="t('Search customers, or leave empty for a walk-in customer')" :create-route="can('crm.customers.manage') ? 'crm.customers.quick' : undefined" />
            </Field>
            <template v-if="!customer">
                <p class="text-[12.5px] text-ink-500">{{ t('No customer picked: this will be saved as a walk-in customer.') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="Walk-in customer’s name (optional)"><input v-model="kase.walk_in_name" class="input" maxlength="120" /></Field>
                    <Field label="Walk-in customer’s phone (optional)"><input v-model="kase.walk_in_phone" type="tel" class="input" maxlength="30" /></Field>
                </div>
            </template>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Type"><select v-model="kase.type" class="input"><option value="complaint">{{ t('Complaint') }}</option><option value="return_request">{{ t('Return request') }}</option><option value="quality">{{ t('Quality') }}</option><option value="delivery">{{ t('Delivery') }}</option><option value="billing">{{ t('Billing') }}</option><option value="other">{{ t('Other') }}</option></select></Field>
                <Field label="Priority"><select v-model="kase.priority" class="input"><option value="low">{{ t('low') }}</option><option value="normal">{{ t('normal') }}</option><option value="high">{{ t('high') }}</option><option value="urgent">{{ t('urgent') }}</option></select></Field>
            </div>
            <Field label="Subject" :error="kase.errors.subject" required><input v-model="kase.subject" class="input" maxlength="200" /></Field>
            <Field label="Details"><textarea v-model="kase.description" rows="3" class="input" /></Field>
            <Field label="Resolve by"><input v-model="kase.due_at" type="date" class="input" /></Field>
        </div>
        <template #footer><Button @click="adding = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="kase.processing" @click="saveCase">{{ t('Save') }}</Button></template>
    </Modal>
</template>
