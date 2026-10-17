<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { useFilters } from '@/composables/useFilters';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ tab: string; rows: any; filters: Record<string, any>; canManage: boolean }>();
const filters = useFilters('settings.security', { tab: props.tab, q: props.filters.q ?? '', event: props.filters.event ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
function revoke(s: any) {
    router.post(route('settings.users.action', s.user_id), { action: 'revoke_session', session_id: s.id }, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('Security')" :subtitle="t('Who did what, who signed in, where people are signed in, and what was downloaded.')" />
    <Tabs :model-value="filters.tab" :tabs="[{ key: 'audit', label: 'Audit trail' }, { key: 'logins', label: 'Sign-ins' }, { key: 'sessions', label: 'Signed in now' }, { key: 'exports', label: 'Downloads' }]" @update:model-value="(v) => { filters.tab = v; filters.q = ''; filters.event = ''; }" />
    <div v-if="['audit', 'logins'].includes(filters.tab)" class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="filters.tab === 'audit' ? t('Search what happened') : t('E-mail')" />
        <input v-model="filters.event" class="input max-w-[14rem]" :placeholder="filters.tab === 'audit' ? t('Event (e.g. pos., approval.)') : t('Event (e.g. failed)')" />
        <template v-if="filters.tab === 'audit'"><input v-model="filters.from" type="date" class="input max-w-[11rem]" /><input v-model="filters.to" type="date" class="input max-w-[11rem]" /></template>
    </div>
    <section class="panel">
        <table v-if="tab === 'audit'" class="table-base">
            <thead><tr><th>{{ t('When') }}</th><th>{{ t('Who') }}</th><th>{{ t('What') }}</th><th>{{ t('Event') }}</th><th>{{ t('Store') }}</th><th>IP</th></tr></thead>
            <tbody><tr v-for="r in rows.data" :key="r.id"><td class="whitespace-nowrap">{{ dateTime(r.created_at) }}</td><td>{{ r.user?.name ?? t('System') }}</td><td>{{ r.description }}</td><td class="font-mono text-[12px] text-ink-500">{{ r.event }}</td><td>{{ r.store?.name ?? '' }}</td><td class="text-[12px] text-ink-500">{{ r.ip_address }}</td></tr></tbody>
        </table>
        <table v-else-if="tab === 'logins'" class="table-base">
            <thead><tr><th>{{ t('When') }}</th><th>{{ t('E-mail') }}</th><th>{{ t('Result') }}</th><th>IP</th><th>{{ t('Device') }}</th></tr></thead>
            <tbody><tr v-for="r in rows.data" :key="r.id"><td class="whitespace-nowrap">{{ dateTime(r.created_at) }}</td><td>{{ r.email }}</td><td><Badge :tone="String(r.event).includes('fail') || String(r.event).includes('lock') ? 'bad' : 'good'" :label="String(r.event).replaceAll('_', ' ')" /></td><td>{{ r.ip_address }}</td><td class="max-w-xs truncate text-[12px] text-ink-500">{{ r.user_agent }}</td></tr></tbody>
        </table>
        <table v-else-if="tab === 'sessions'" class="table-base">
            <thead><tr><th>{{ t('Person') }}</th><th>{{ t('Last active') }}</th><th>IP</th><th>{{ t('Device') }}</th><th></th></tr></thead>
            <tbody><tr v-for="r in rows.data" :key="r.id"><td>{{ r.name }}<div class="text-[12px] text-ink-500">{{ r.email }}</div></td><td>{{ dateTime(new Date(r.last_activity * 1000).toISOString()) }}</td><td>{{ r.ip_address }}</td><td class="max-w-xs truncate text-[12px] text-ink-500">{{ r.user_agent }}</td><td><Button v-if="canManage" size="sm" @click="revoke(r)">{{ t('Sign out') }}</Button></td></tr></tbody>
        </table>
        <table v-else class="table-base">
            <thead><tr><th>{{ t('When') }}</th><th>{{ t('Who') }}</th><th>{{ t('Report') }}</th><th class="num">{{ t('Rows') }}</th><th>{{ t('Sensitive') }}</th><th>{{ t('Downloaded') }}</th></tr></thead>
            <tbody><tr v-for="r in rows.data" :key="r.id"><td>{{ dateTime(r.created_at) }}</td><td>{{ r.user?.name }}</td><td>{{ r.report_key }}</td><td class="num">{{ r.row_count ?? '—' }}</td><td>{{ r.is_sensitive ? t('Yes') : '' }}</td><td>{{ r.downloaded_at ? dateTime(r.downloaded_at) : '—' }}</td></tr></tbody>
        </table>
        <p v-if="!rows.data.length" class="py-8 text-center text-ink-500">{{ t('Nothing here.') }}</p>
        <Pagination :meta="rows" />
    </section>
</template>
