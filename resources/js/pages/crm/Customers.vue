<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { Link } from '@inertiajs/vue3';
import { useFilters } from '@/composables/useFilters';
import { money } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';
import { colorHex } from '@/lib/colors';

const props = defineProps<{ customers: any; filters: Record<string, any>; canExport: boolean }>();
const filters = useFilters('crm.customers.index', { q: props.filters.q ?? '', category: props.filters.category ?? '', with_balance: props.filters.with_balance ?? '', area: props.filters.area ?? '', sort: props.filters.sort ?? '' });
const categories: Record<string, string> = { retail: 'Retail', wholesale: 'Wholesale', shopkeeper: 'Shopkeeper', institution: 'Institution' };
</script>

<template>
    <PageHeader :title="t('Customers')" :subtitle="t('Customers and their Khata. Red balances are money owed to the shop.')">
        <template #actions>
            <Button v-if="can('sales.payments.receive')" :href="route('sales.collections')">{{ t('Wasooli') }}</Button>
            <Button v-if="can('crm.customers.manage')" variant="primary" :href="route('crm.customers.create')">{{ t('Add customer') }}</Button>
        </template>
    </PageHeader>
    <div class="panel mb-4 flex flex-wrap items-center gap-3 p-3">
        <input v-model="filters.q" type="search" class="input max-w-xs" :placeholder="t('Name, phone or code')" />
        <select v-model="filters.category" class="input max-w-[12rem]"><option value="">{{ t('All types') }}</option><option v-for="(l, k) in categories" :key="k" :value="k">{{ t(l) }}</option></select>
        <input v-model="filters.area" class="input max-w-[12rem]" :placeholder="t('Area')" />
        <label class="flex items-center gap-2 text-sm"><input v-model="filters.with_balance" type="checkbox" true-value="1" false-value="" class="rounded border-ink-300" />{{ t('Only with Khata balance') }}</label>
        <select v-model="filters.sort" class="input ms-auto max-w-[12rem]"><option value="">{{ t('Sort by name') }}</option><option value="balance">{{ t('Biggest balance first') }}</option></select>
    </div>
    <section class="panel">
        <table class="table-base khata">
            <thead><tr><th class="ps-12">{{ t('Customer') }}</th><th>{{ t('Phone') }}</th><th>{{ t('Area') }}</th><th>{{ t('Type') }}</th><th class="num">{{ t('Credit limit') }}</th><th class="num">{{ t('Khata balance') }}</th><th>{{ t('Credit') }}</th></tr></thead>
            <tbody>
                <tr v-for="c in customers.data" :key="c.id">
                    <td class="ps-12"><Link :href="route('crm.customers.show', c.id)" class="font-medium text-ink-900 hover:underline">{{ c.name }}</Link><div class="text-[12px] text-ink-500">{{ c.code }}<span v-if="c.name_ur" class="urdu ms-2">{{ c.name_ur }}</span></div>
                        <div v-if="c.tags?.length" class="mt-0.5 flex gap-1"><span v-for="tg in c.tags" :key="tg.id" class="rounded px-1.5 text-[11px]" :style="{ background: colorHex(tg.color) + '22', color: colorHex(tg.color) }">{{ tg.name }}</span></div></td>
                    <td>{{ c.phone ?? '—' }}</td><td>{{ c.area ?? '—' }}</td><td>{{ t(categories[c.customer_profile?.category] ?? '—') }}</td>
                    <td class="num">{{ money(c.customer_profile?.credit_limit ?? 0) }}</td>
                    <td class="num font-semibold" :class="Number(c.khata_balance) > 0 ? 'text-ledger-red' : Number(c.khata_balance) < 0 ? 'text-ledger-green' : 'text-ink-400'">{{ c.khata_balance !== undefined ? money(c.khata_balance ?? 0) : '•••' }}</td>
                    <td><Badge v-if="c.customer_profile?.credit_status && c.customer_profile.credit_status !== 'active'" :status="c.customer_profile.credit_status" /></td>
                </tr>
                <tr v-if="!customers.data.length"><td colspan="7" class="py-8 text-center text-ink-500">{{ t('No customers found.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="customers" />
    </section>
</template>
