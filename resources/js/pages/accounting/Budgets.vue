<script setup lang="ts">
import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Monthly budget per income and expense account. Compare with actuals in Reports → Profit and loss. */
const props = defineProps<{ year: number; accounts: any[]; budgets: any[]; canManage: boolean }>();
const months = Array.from({ length: 12 }, (_, i) => `${props.year}-${String(i + 1).padStart(2, '0')}-01`);
const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const grid = reactive<Record<string, string>>({});
props.budgets.forEach((b) => (grid[`${b.account_id}|${String(b.month).slice(0, 10)}`] = String(Number(b.amount))));
const rowTotal = (id: number) => months.reduce((s, m) => s + Number(grid[`${id}|${m}`] || 0), 0);
const groups = computed(() => [{ key: 'income', label: 'Income' }, { key: 'expense', label: 'Expenses' }].map((g) => ({ ...g, rows: props.accounts.filter((a) => a.type === g.key) })));
function fill(id: number) {
    const v = grid[`${id}|${months[0]}`];
    months.forEach((m) => (grid[`${id}|${m}`] = v));
}
function save() {
    const rows = props.accounts.flatMap((a) => months.map((m) => ({ account_id: a.id, month: m, amount: grid[`${a.id}|${m}`] === '' || grid[`${a.id}|${m}`] === undefined ? null : grid[`${a.id}|${m}`] }))).filter((r) => r.amount !== null || props.budgets.some((b) => b.account_id === r.account_id && String(b.month).slice(0, 10) === r.month));
    router.put(route('accounting.budgets.save'), { rows }, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="`${t('Budget')} ${year}`" :subtitle="t('Plan income and spending per month.')">
        <template #actions>
            <Button :href="route('accounting.budgets', { year: year - 1 })">‹ {{ year - 1 }}</Button>
            <Button :href="route('accounting.budgets', { year: year + 1 })">{{ year + 1 }} ›</Button>
            <Button v-if="canManage" variant="primary" @click="save">{{ t('Save budget') }}</Button>
        </template>
    </PageHeader>
    <section v-for="g in groups" :key="g.key" class="panel mb-5 overflow-x-auto">
        <table class="table-base text-[13px]">
            <thead><tr><th class="min-w-[14rem]">{{ t(g.label) }}</th><th v-for="(n, i) in names" :key="i" class="num">{{ t(n) }}</th><th class="num">{{ t('Year') }}</th></tr></thead>
            <tbody>
                <tr v-for="a in g.rows" :key="a.id">
                    <td>{{ a.code }} {{ a.name }} <button v-if="canManage" class="link ms-1 text-[11px]" :title="t('Copy January to all months')" @click="fill(a.id)">→</button></td>
                    <td v-for="m in months" :key="m" class="p-1"><input v-model="grid[`${a.id}|${m}`]" type="number" min="0" class="input w-24 px-1.5 py-1 text-end text-[13px]" :disabled="!canManage" /></td>
                    <td class="num font-medium">{{ money(rowTotal(a.id)) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
