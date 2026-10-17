<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import { useOptions } from '@/composables/useOptions';
import { today } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Rent, electricity, tea, transport … paid from cash, bank or a wallet. */
defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const options = useOptions(['accounts']);
const expenseAccounts = computed(() => (options.accounts ?? []).filter((a: any) => a.type === 'expense'));
const moneyAccounts = computed(() => (options.accounts ?? []).filter((a: any) => ['cash', 'bank', 'wallet'].includes(a.subtype)));
const form = useForm({ entry_date: today(), expense_account_id: null as number | null, paid_from_account_id: null as number | null, amount: '', description: '', contact_id: null });
function save() {
    form.post(route('accounting.expenses.store'), { preserveScroll: true, onSuccess: () => { form.reset(); emit('close'); } });
}
</script>

<template>
    <Modal :open="open" :title="t('Record an expense')" size="md" @close="emit('close')">
        <div class="space-y-3">
            <Field label="What for" :error="form.errors.description" required><input v-model="form.description" class="input" :placeholder="t('K-Electric bill October')" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Expense type" :error="form.errors.expense_account_id" required><select v-model="form.expense_account_id" class="input"><option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></Field>
                <Field label="Paid from" :error="form.errors.paid_from_account_id" required><select v-model="form.paid_from_account_id" class="input"><option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></Field>
                <Field label="Amount" :error="form.errors.amount" required><input v-model="form.amount" type="number" min="0" class="input text-end" /></Field>
                <Field label="Date" :error="form.errors.entry_date"><input v-model="form.entry_date" type="date" class="input" /></Field>
            </div>
            <p class="text-[12.5px] text-ink-500">{{ t('Cash paid out of a POS drawer is recorded on the POS as a payout instead.') }}</p>
        </div>
        <template #footer><Button @click="emit('close')">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
