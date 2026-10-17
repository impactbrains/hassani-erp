<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import { useFilters } from '@/composables/useFilters';
import { t } from '@/lib/i18n';

const props = defineProps<{ users: any; filters: Record<string, any>; canManage: boolean }>();
const filters = useFilters('settings.users.index', { q: props.filters.q ?? '', status: props.filters.status ?? '' });
const columns = [{ key: 'name', label: 'Name' }, { key: 'email', label: 'E-mail' }, { key: 'roles', label: 'Roles' }, { key: 'last_login_at', label: 'Last sign-in', type: 'datetime' as const }, { key: 'status', label: 'Status', type: 'status' as const }];
const rows = (data: any[]) => data.map((u) => ({ ...u, roles: u.role_assignments.map((a: any) => a.role?.name + (a.store ? ` (${a.store.name})` : '')).join(', ') }));
</script>

<template>
    <PageHeader :title="t('Users')" :subtitle="t('Staff logins. There is no public sign-up: accounts are made here and get a temporary password.')">
        <template #actions><Button v-if="canManage" variant="primary" :href="route('settings.users.create')">{{ t('Add user') }}</Button></template>
    </PageHeader>
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Name or e-mail')" />
        <select v-model="filters.status" class="input max-w-[12rem]"><option value="">{{ t('Any status') }}</option><option value="active">{{ t('active') }}</option><option value="suspended">{{ t('suspended') }}</option><option value="deactivated">{{ t('deactivated') }}</option></select>
    </div>
    <section class="panel"><DataTable :columns="columns" :rows="rows(users.data)" :row-href="(r) => route('settings.users.show', r.id)" /><Pagination :meta="users" /></section>
</template>
