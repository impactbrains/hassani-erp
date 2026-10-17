<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { useFilters } from '@/composables/useFilters';
import { date } from '@/lib/format';
import { roleTypes } from '@/lib/hr';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ employees: any; filters: Record<string, any>; departments: any[] }>();
const page = usePage<any>();
const filters = useFilters('hr.employees.index', { q: props.filters.q ?? '', status: props.filters.status ?? 'current', store_id: props.filters.store_id ?? '', department_id: props.filters.department_id ?? '' });
</script>

<template>
    <PageHeader :title="t('Employees')" :subtitle="t('Staff records, documents, salary and history.')">
        <template #actions>
            <Button v-if="can('hr.attendance.view')" :href="route('hr.attendance')">{{ t('Today’s attendance') }}</Button>
            <Button v-if="can('hr.employees.manage')" variant="primary" :href="route('hr.employees.create')">{{ t('Add employee') }}</Button>
        </template>
    </PageHeader>
    <div class="panel mb-4 flex flex-wrap gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Name, phone or employee no.')" />
        <select v-model="filters.status" class="input max-w-[12rem]"><option value="current">{{ t('Current staff') }}</option><option value="all">{{ t('Everyone') }}</option><option v-for="s in ['onboarding', 'probation', 'active', 'suspended', 'resigned', 'terminated']" :key="s" :value="s">{{ t(s) }}</option></select>
        <select v-model="filters.store_id" class="input max-w-[12rem]"><option value="">{{ t('All stores') }}</option><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <select v-model="filters.department_id" class="input max-w-[12rem]"><option value="">{{ t('All departments') }}</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
    </div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Employee') }}</th><th>{{ t('Job') }}</th><th>{{ t('Store') }}</th><th>{{ t('Department') }}</th><th>{{ t('Joined') }}</th><th>{{ t('Login') }}</th><th>{{ t('Status') }}</th></tr></thead>
            <tbody>
                <tr v-for="e in employees.data" :key="e.id">
                    <td><Link :href="route('hr.employees.show', e.id)" class="font-medium hover:underline">{{ e.name }}</Link><div class="text-[12px] text-ink-500">{{ e.employee_detail?.employee_no }} · {{ e.phone }}</div></td>
                    <td>{{ e.employee_detail?.job_title ?? t(roleTypes[e.employee_detail?.role_type] ?? '—') }}</td><td>{{ e.employee_detail?.store?.name ?? '—' }}</td><td>{{ e.employee_detail?.department?.name ?? '—' }}</td>
                    <td>{{ e.employee_detail?.joining_date ? date(e.employee_detail.joining_date) : '—' }}</td><td class="text-[12.5px]">{{ e.user?.email ?? '—' }}</td><td><Badge :status="e.employee_detail?.status" /></td>
                </tr>
                <tr v-if="!employees.data.length"><td colspan="7" class="py-8 text-center text-ink-500">{{ t('No employees found.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="employees" />
    </section>
</template>
