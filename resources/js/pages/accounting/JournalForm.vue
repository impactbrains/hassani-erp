<script setup lang="ts">
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import { useOptions } from '@/composables/useOptions';
import { money, round2, today } from '@/lib/format';
import { t } from '@/lib/i18n';

defineProps<{ mode: string }>();
const page = usePage<any>();
const options = useOptions(['accounts']);
const blank = () => ({ account_id: null as number | null, debit: '', credit: '', memo: '' });
const form = useForm({ entry_date: today(), description: '', store_id: page.props.auth?.store_id ?? null, lines: [blank(), blank()] });
const dr = computed(() => round2(form.lines.reduce((s, l) => s + Number(l.debit || 0), 0)));
const cr = computed(() => round2(form.lines.reduce((s, l) => s + Number(l.credit || 0), 0)));
const balanced = computed(() => dr.value > 0 && dr.value === cr.value);
function submit() {
    form.transform((d) => ({ ...d, lines: d.lines.filter((l) => l.account_id && (Number(l.debit) || Number(l.credit))).map((l) => ({ ...l, debit: l.debit || 0, credit: l.credit || 0 })) })).post(route('accounting.journals.store'));
}
</script>

<template>
    <PageHeader :title="t('Manual journal')" :subtitle="t('For corrections and entries no screen makes (owner’s capital, loans, depreciation). Debits must equal credits.')" />
    <section class="panel mb-4 grid gap-3 p-4 md:grid-cols-3">
        <Field label="Date" :error="form.errors.entry_date"><input v-model="form.entry_date" type="date" class="input" /></Field>
        <Field label="Details" :error="form.errors.description" required><input v-model="form.description" class="input" /></Field>
        <Field label="Store"><select v-model="form.store_id" class="input"><option :value="null">{{ t('Whole business') }}</option><option v-for="s in page.props.auth?.stores ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
    </section>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Account') }}</th><th>{{ t('Memo') }}</th><th class="w-36">{{ t('Debit') }}</th><th class="w-36">{{ t('Credit') }}</th><th class="w-8"></th></tr></thead>
            <tbody>
                <tr v-for="(l, i) in form.lines" :key="i">
                    <td><select v-model="l.account_id" class="input py-1 text-sm"><option :value="null">—</option><option v-for="a in options.accounts ?? []" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></select></td>
                    <td><input v-model="l.memo" class="input py-1 text-sm" /></td>
                    <td><input v-model="l.debit" type="number" min="0" step="0.01" class="input py-1 text-end" @input="l.debit && (l.credit = '')" /></td>
                    <td><input v-model="l.credit" type="number" min="0" step="0.01" class="input py-1 text-end" @input="l.credit && (l.debit = '')" /></td>
                    <td><button v-if="form.lines.length > 2" class="text-ink-400 hover:text-ledger-red" @click="form.lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                </tr>
            </tbody>
            <tfoot><tr><td colspan="2" class="px-3 py-2 text-end font-semibold">{{ t('Total') }}</td><td class="num px-3 py-2 font-semibold">{{ money(dr) }}</td><td class="num px-3 py-2 font-semibold">{{ money(cr) }}</td><td></td></tr></tfoot>
        </table>
        <div class="flex items-center gap-3 border-t border-ink-100 p-3">
            <Button size="sm" @click="form.lines.push(blank())"><Plus class="h-4 w-4" />{{ t('Add line') }}</Button>
            <span v-if="!balanced && dr + cr > 0" class="text-sm text-ledger-red">{{ t('Difference') }}: {{ money(Math.abs(dr - cr)) }}</span>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <div class="ms-auto flex gap-2"><Button :href="route('accounting.journals.index')">{{ t('Cancel') }}</Button><Button variant="primary" :disabled="!balanced || !form.description" :loading="form.processing" @click="submit">{{ t('Post') }}</Button></div>
        </div>
    </section>
</template>
