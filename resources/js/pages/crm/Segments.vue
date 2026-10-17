<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Download, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Field from '@/components/form/Field.vue';
import { money } from '@/lib/format';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

/** Customer groups defined by rules (e.g. spent over Rs 20,000 in 90 days and has not bought for 30 days). */
const props = defineProps<{ segments: any[]; fields: Record<string, string>; operators: string[]; tags: any[]; selected: any | null; members: any | null; canManage: boolean }>();
const editing = ref(false);
const form = useForm<{ id: number | null; name: string; description: string; rules: any[] }>({ id: null, name: '', description: '', rules: [] });
function edit(s: any | null) {
    form.clearErrors();
    form.id = s?.id ?? null;
    form.name = s?.name ?? '';
    form.description = s?.description ?? '';
    form.rules = s ? JSON.parse(JSON.stringify(s.rules)) : [{ field: 'spend_90d', operator: '>=', value: '10000' }];
    editing.value = true;
}
function save() {
    form.post(route('crm.segments.store'), { onSuccess: () => (editing.value = false) });
}
const describe = (r: any) => `${t(props.fields[r.field] ?? r.field)} ${r.operator} ${r.field === 'tag' ? props.tags.find((x) => x.id == r.value)?.name ?? r.value : r.value}`;
</script>

<template>
    <PageHeader :title="t('Customer segments')" :subtitle="t('Groups of customers by rules, for offers and follow-up calls. Members update automatically.')">
        <template #actions><Button v-if="canManage" variant="primary" @click="edit(null)"><Plus class="h-4 w-4" />{{ t('New segment') }}</Button></template>
    </PageHeader>
    <div class="grid gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
        <aside class="panel">
            <ul class="divide-y divide-ink-100">
                <li v-for="s in segments" :key="s.id">
                    <a :href="route('crm.segments', { segment: s.id })" class="block px-4 py-3 hover:bg-haldi-50/50" :class="selected?.id === s.id ? 'bg-haldi-50' : ''">
                        <div class="font-medium">{{ s.name }}</div>
                        <div class="text-[12px] text-ink-500">{{ s.rules.map(describe).join(' · ') }}</div>
                    </a>
                </li>
                <li v-if="!segments.length" class="px-4 py-6 text-sm text-ink-500">{{ t('No segments yet.') }}</li>
            </ul>
        </aside>
        <section class="panel">
            <template v-if="selected && members">
                <div class="panel-head">
                    <div><h2>{{ selected.name }}</h2><p class="text-[12.5px] text-ink-500">{{ selected.description }} · {{ members.total }} {{ t('customers') }}</p></div>
                    <div class="flex gap-2">
                        <a v-if="can('crm.customers.export')" :href="route('crm.segments.export', selected.id)" class="inline-flex items-center gap-1 rounded border border-ink-200 px-3 py-1.5 text-sm"><Download class="h-4 w-4" />CSV</a>
                        <Button v-if="canManage" size="sm" @click="edit(selected)">{{ t('Edit') }}</Button>
                        <Button v-if="canManage" size="sm" variant="danger" @click="router.delete(route('crm.segments.destroy', selected.id))"><Trash2 class="h-4 w-4" /></Button>
                    </div>
                </div>
                <table class="table-base">
                    <thead><tr><th>{{ t('Customer') }}</th><th>{{ t('Area') }}</th><th class="num">{{ t('Spent (90 days)') }}</th><th class="num">{{ t('Purchases (90 days)') }}</th><th class="num">{{ t('Days since last') }}</th><th class="num">{{ t('Khata') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="m in members.data" :key="m.id">
                            <td><a :href="route('crm.customers.show', m.id)" class="link">{{ m.name }}</a> <span class="text-[12px] text-ink-500">{{ m.code }}</span></td><td>{{ m.area ?? '—' }}</td>
                            <td class="num">{{ money(m.spend_90d) }}</td><td class="num">{{ m.orders_90d }}</td><td class="num">{{ m.days_since_purchase ?? '—' }}</td><td class="num" :class="Number(m.khata_balance) > 0 ? 'text-ledger-red' : ''">{{ money(m.khata_balance) }}</td>
                        </tr>
                        <tr v-if="!members.data.length"><td colspan="6" class="py-8 text-center text-ink-500">{{ t('No customer matches these rules right now.') }}</td></tr>
                    </tbody>
                </table>
                <Pagination :meta="members" />
            </template>
            <p v-else class="p-8 text-center text-ink-500">{{ t('Choose a segment to see its customers.') }}</p>
        </section>
    </div>

    <div v-if="editing" class="fixed inset-0 z-40 flex items-center justify-center bg-ink-900/40 p-4" @click.self="editing = false">
        <div class="panel w-full max-w-2xl space-y-3 p-5">
            <h2 class="text-lg font-semibold">{{ form.id ? t('Edit segment') : t('New segment') }}</h2>
            <Field label="Name" :error="form.errors.name" required><input v-model="form.name" class="input" /></Field>
            <Field label="Description"><input v-model="form.description" class="input" /></Field>
            <div class="space-y-2">
                <span class="field-label">{{ t('Customers who match all of these') }}</span>
                <div v-for="(r, i) in form.rules" :key="i" class="flex gap-2">
                    <select v-model="r.field" class="input flex-1"><option v-for="(l, k) in fields" :key="k" :value="k">{{ t(l) }}</option></select>
                    <select v-model="r.operator" class="input w-20"><option v-for="o in operators" :key="o" :value="o">{{ o }}</option></select>
                    <select v-if="r.field === 'tag'" v-model="r.value" class="input w-40"><option v-for="tg in tags" :key="tg.id" :value="tg.id">{{ tg.name }}</option></select>
                    <select v-else-if="r.field === 'category'" v-model="r.value" class="input w-40"><option value="retail">{{ t('Retail') }}</option><option value="wholesale">{{ t('Wholesale') }}</option><option value="shopkeeper">{{ t('Shopkeeper') }}</option><option value="institution">{{ t('Institution') }}</option></select>
                    <select v-else-if="r.field === 'credit_status'" v-model="r.value" class="input w-40"><option value="active">{{ t('Allowed') }}</option><option value="on_hold">{{ t('On hold') }}</option><option value="blocked">{{ t('Blocked') }}</option></select>
                    <select v-else-if="r.field === 'marketing_consent'" v-model="r.value" class="input w-40"><option :value="true">{{ t('Yes') }}</option><option :value="false">{{ t('No') }}</option></select>
                    <input v-else v-model="r.value" class="input w-40" />
                    <button class="text-ink-400 hover:text-ledger-red" @click="form.rules.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
                </div>
                <Button size="sm" @click="form.rules.push({ field: 'days_since_purchase', operator: '>=', value: '30' })">{{ t('Add rule') }}</Button>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <div class="flex justify-end gap-2"><Button @click="editing = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></div>
        </div>
    </div>
</template>
