<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { date, money } from '@/lib/format';
import { t } from '@/lib/i18n';

defineProps<{ targets: any }>();
const page = usePage<any>();
const agent = ref<any>(null);
const first = new Date();
const form = useForm({ store_id: null as number | null, sales_agent_id: null as number | null, metric: 'net_sales', period_start: new Date(first.getFullYear(), first.getMonth(), 1).toISOString().slice(0, 10), period_end: new Date(first.getFullYear(), first.getMonth() + 1, 0).toISOString().slice(0, 10), target_value: '' });
function save() {
    form.sales_agent_id = agent.value?.id ?? null;
    form.post(route('sales.targets.store'), { preserveScroll: true, onSuccess: () => { form.reset('target_value'); agent.value = null; } });
}
</script>

<template>
    <PageHeader :title="t('Sales targets')" :subtitle="t('Monthly targets for a store or an agent. Progress is in Reports → Targets vs actual.')">
        <template #actions><Button :href="route('reports.show', 'sales.targets')">{{ t('Targets vs actual') }}</Button></template>
    </PageHeader>
    <section class="panel mb-5 grid items-end gap-3 p-4 md:grid-cols-6">
        <Field label="Store"><select v-model="form.store_id" class="input"><option :value="null">{{ t('All stores') }}</option><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
        <Field label="Agent (optional)"><ContactPicker v-model="agent" type="employee" /></Field>
        <Field label="Measure"><select v-model="form.metric" class="input"><option value="net_sales">{{ t('Net sales') }}</option><option value="collections">{{ t('Collections (wasooli)') }}</option></select></Field>
        <Field label="From"><input v-model="form.period_start" type="date" class="input" /></Field>
        <Field label="To" :error="form.errors.period_end"><input v-model="form.period_end" type="date" class="input" /></Field>
        <Field label="Target (Rs)" :error="form.errors.target_value"><div class="flex gap-2"><input v-model="form.target_value" type="number" min="0" class="input text-end" /><Button variant="primary" :loading="form.processing" @click="save">{{ t('Add') }}</Button></div></Field>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Period') }}</th><th>{{ t('Store') }}</th><th>{{ t('Agent') }}</th><th>{{ t('Measure') }}</th><th class="num">{{ t('Target') }}</th><th class="w-10"></th></tr></thead>
            <tbody>
                <tr v-for="x in targets.data" :key="x.id">
                    <td>{{ date(x.period_start) }} – {{ date(x.period_end) }}</td><td>{{ x.store?.name ?? t('All stores') }}</td><td>{{ x.sales_agent?.name ?? '—' }}</td>
                    <td>{{ x.metric === 'net_sales' ? t('Net sales') : t('Collections (wasooli)') }}</td><td class="num font-medium">{{ money(x.target_value) }}</td>
                    <td><button class="text-ink-400 hover:text-ledger-red" :aria-label="t('Delete')" @click="router.delete(route('sales.targets.destroy', x.id), { preserveScroll: true })"><Trash2 class="h-4 w-4" /></button></td>
                </tr>
                <tr v-if="!targets.data.length"><td colspan="6" class="py-8 text-center text-ink-500">{{ t('No targets yet.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="targets" />
    </section>
</template>
