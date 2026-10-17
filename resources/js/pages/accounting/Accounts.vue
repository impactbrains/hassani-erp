<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ExpenseModal from '@/components/app/ExpenseModal.vue';
import { money } from '@/lib/format';
import { can } from '@/lib/perms';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ accounts: any[]; canManage: boolean }>();
const types: Record<string, string> = { asset: 'Assets (what the business owns)', liability: 'Liabilities (what it owes)', equity: 'Owner’s capital', income: 'Income', expense: 'Expenses' };
const q = ref('');
const grouped = computed(() => Object.keys(types).map((k) => ({ key: k, rows: props.accounts.filter((a) => a.type === k && (!q.value || `${a.code} ${a.name} ${a.name_ur ?? ''}`.toLowerCase().includes(q.value.toLowerCase()))) })));
const editing = ref<any>(null);
const expense = ref(false);
const form = useForm({ code: '', name: '', name_ur: '', type: 'expense', subtype: '', parent_id: null as number | null, is_active: true });
function edit(a: any | null) {
    editing.value = a ?? {};
    Object.assign(form, a ? { code: a.code, name: a.name, name_ur: a.name_ur ?? '', type: a.type, subtype: a.subtype ?? '', parent_id: a.parent_id, is_active: a.is_active } : { code: '', name: '', name_ur: '', type: 'expense', subtype: '', parent_id: null, is_active: true });
    form.clearErrors();
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? form.put(route('accounting.accounts.update', editing.value.id), opts) : form.post(route('accounting.accounts.store'), opts);
}
</script>

<template>
    <PageHeader :title="t('Chart of accounts')" :subtitle="t('Every sale, purchase, payment and stock change posts here automatically.')">
        <template #actions>
            <Button v-if="can('accounting.journals.manage')" @click="expense = true">{{ t('Record expense') }}</Button>
            <Button v-if="can('accounting.journals.manage')" :href="route('accounting.journals.create')">{{ t('Manual journal') }}</Button>
            <Button v-if="canManage" variant="primary" @click="edit(null)">{{ t('Add account') }}</Button>
        </template>
    </PageHeader>
    <input v-model="q" type="search" class="input mb-4 max-w-xs" :placeholder="t('Find an account')" />
    <div class="space-y-5">
        <section v-for="g in grouped" :key="g.key" class="panel">
            <div class="panel-head"><h2>{{ t(types[g.key]) }}</h2><span class="font-semibold tabular-nums">{{ money(g.rows.filter((r) => r.is_postable).reduce((s, r) => s + Number(r.balance), 0)) }}</span></div>
            <table class="table-base">
                <tbody>
                    <tr v-for="a in g.rows" :key="a.id" :class="a.is_active ? '' : 'opacity-50'">
                        <td class="w-24 tabular-nums text-ink-500">{{ a.code }}</td>
                        <td><Link :href="route('accounting.accounts.show', a.id)" class="hover:underline" :class="a.is_postable ? '' : 'font-semibold'">{{ localName(a) }}</Link><span v-if="a.system_key" class="ms-2 text-[11px] text-ink-400">{{ t('system') }}</span></td>
                        <td class="num" :class="Number(a.balance) < 0 ? 'text-ledger-red' : ''">{{ a.is_postable ? money(a.balance) : '' }}</td>
                        <td class="w-16"><button v-if="canManage" class="link text-[12.5px]" @click="edit(a)">{{ t('Edit') }}</button></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
    <Modal :open="!!editing" :title="editing?.id ? t('Edit account') : t('Add account')" size="md" @close="editing = null">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Code" :error="form.errors.code" required><input v-model="form.code" class="input" /></Field>
            <Field label="Type"><select v-model="form.type" class="input" :disabled="!!editing?.system_key"><option v-for="(l, k) in types" :key="k" :value="k">{{ t(l) }}</option></select></Field>
            <Field label="Name" :error="form.errors.name" required class="col-span-2"><input v-model="form.name" class="input" /></Field>
            <Field label="Name in Urdu" class="col-span-2"><input v-model="form.name_ur" class="input urdu" dir="rtl" /></Field>
            <Field label="Kind (optional)"><select v-model="form.subtype" class="input"><option value="">—</option><option value="cash">{{ t('Cash') }}</option><option value="bank">{{ t('Bank') }}</option><option value="wallet">{{ t('Wallet (Easypaisa / JazzCash)') }}</option></select></Field>
            <Field label="Under"><select v-model="form.parent_id" class="input"><option :value="null">—</option><option v-for="a in accounts.filter((x) => !x.is_postable && x.type === form.type)" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option></select></Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('Active') }}</label>
        </div>
        <template #footer><Button @click="editing = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
    <ExpenseModal :open="expense" @close="expense = false" />
</template>
