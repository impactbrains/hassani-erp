<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ open: boolean; supplier?: any | null }>();
const emit = defineEmits<{ close: [] }>();
const blank = () => ({ name: '', name_ur: '', phone: '', alt_phone: '', email: '', address: '', city: 'Karachi', tax_number: '', is_active: true, lead_time_days: 2, payment_terms_days: 30, min_order_value: '', bank_details: '' });
const form = useForm<any>(blank());
watch(() => props.open, (o) => {
    if (!o) return;
    const s = props.supplier;
    Object.assign(form, s ? { name: s.name, name_ur: s.name_ur ?? '', phone: s.phone ?? '', alt_phone: s.alt_phone ?? '', email: s.email ?? '', address: s.address ?? '', city: s.city ?? '', tax_number: s.tax_number ?? '', is_active: s.is_active,
        lead_time_days: s.supplier_profile?.lead_time_days ?? '', payment_terms_days: s.supplier_profile?.payment_terms_days ?? '', min_order_value: s.supplier_profile?.min_order_value ?? '', bank_details: s.supplier_profile?.bank_details ?? '' } : blank());
    form.clearErrors();
});
function save() {
    form.transform((d: any) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
    const done = { preserveScroll: true, onSuccess: () => emit('close') };
    props.supplier ? form.put(route('purchasing.suppliers.update', props.supplier.id), done) : form.post(route('purchasing.suppliers.store'), done);
}
</script>

<template>
    <Modal :open="open" :title="supplier ? t('Edit supplier') : t('Add supplier')" size="lg" @close="emit('close')">
        <div class="grid gap-3 md:grid-cols-2">
            <Field label="Name" :error="form.errors.name" required><input v-model="form.name" class="input" /></Field>
            <Field label="Name in Urdu"><input v-model="form.name_ur" class="input urdu" dir="rtl" /></Field>
            <Field label="Phone"><input v-model="form.phone" class="input" /></Field>
            <Field label="Other phone"><input v-model="form.alt_phone" class="input" /></Field>
            <Field label="E-mail" :error="form.errors.email"><input v-model="form.email" type="email" class="input" /></Field>
            <Field label="NTN / STRN"><input v-model="form.tax_number" class="input" /></Field>
            <Field label="Address" class="md:col-span-2"><input v-model="form.address" class="input" /></Field>
            <Field label="City"><input v-model="form.city" class="input" /></Field>
            <Field label="Delivery takes (days)"><input v-model="form.lead_time_days" type="number" min="0" class="input" /></Field>
            <Field label="Days to pay"><input v-model="form.payment_terms_days" type="number" min="0" class="input" /></Field>
            <Field label="Minimum order (Rs)"><input v-model="form.min_order_value" type="number" min="0" class="input" /></Field>
            <Field label="Bank account / wallet for payments" class="md:col-span-2"><textarea v-model="form.bank_details" rows="2" class="input" /></Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('Active') }}</label>
        </div>
        <template #footer><Button @click="emit('close')">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
