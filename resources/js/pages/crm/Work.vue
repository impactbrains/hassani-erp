<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { date, dateTime, money, today } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ followUps: any[]; cases: any[]; promises: any[]; filters: { mine: string } }>();
const now = today();
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
            <div class="panel-head"><h2>{{ t('Open complaints') }}</h2><span class="text-sm text-ink-500">{{ cases.length }}</span></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="k in cases" :key="k.id" class="px-4 py-2.5 text-sm">
                    <div class="flex items-center gap-2"><a :href="route('crm.customers.show', k.contact_id)" class="font-medium hover:underline">{{ k.contact?.name }}</a><Badge :tone="['urgent', 'high'].includes(k.priority) ? 'bad' : 'neutral'" :label="t(k.priority)" /><Badge :status="k.status" /></div>
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
</template>
