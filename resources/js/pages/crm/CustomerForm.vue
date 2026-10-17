<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import { can } from '@/lib/perms';
import { t } from '@/lib/i18n';

const props = defineProps<{ customer: any | null; priceBooks: any[]; tags: any[] }>();
const c = props.customer;
const p = c?.customer_profile ?? {};
const agent = ref<any>(null);
const form = useForm<any>({
    name: c?.name ?? '', name_ur: c?.name_ur ?? '', phone: c?.phone ?? '', alt_phone: c?.alt_phone ?? '', email: c?.email ?? '', address: c?.address ?? '', area: c?.area ?? '', city: c?.city ?? 'Karachi',
    delivery_instructions: c?.delivery_instructions ?? '', tax_number: c?.tax_number ?? '', preferred_language: c?.preferred_language ?? 'ur', is_active: c?.is_active ?? true,
    category: p.category ?? 'retail', price_book_id: p.price_book_id ?? null, sales_agent_id: p.sales_agent_id ?? null,
    credit_limit: p.credit_limit ?? '0', payment_terms_days: p.payment_terms_days ?? 30, credit_status: p.credit_status ?? 'active', credit_status_reason: p.credit_status_reason ?? '',
    marketing_consent: p.marketing_consent ?? false, consent_channels: p.consent_channels ?? [], tag_ids: (c?.tags ?? []).map((x: any) => x.id), confirm_not_duplicate: false,
});
const duplicate = computed(() => String(form.errors.business ?? '').includes('already exists'));
const canCredit = can('crm.credit.manage');
function submit() {
    form.sales_agent_id = agent.value?.id ?? form.sales_agent_id;
    c ? form.put(route('crm.customers.update', c.id)) : form.post(route('crm.customers.store'));
}
</script>

<template>
    <PageHeader :title="c ? `${t('Edit')} ${c.name}` : t('Add customer')" />
    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <section class="panel space-y-3 p-4">
            <h3>{{ t('Details') }}</h3>
            <Field label="Name" :error="form.errors.name" required><input v-model="form.name" class="input" /></Field>
            <Field label="Name in Urdu"><input v-model="form.name_ur" class="input urdu" dir="rtl" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Mobile" :error="form.errors.phone"><input v-model="form.phone" class="input" inputmode="tel" placeholder="03xx-xxxxxxx" /></Field>
                <Field label="Other phone"><input v-model="form.alt_phone" class="input" inputmode="tel" /></Field>
            </div>
            <Field label="Address"><textarea v-model="form.address" rows="2" class="input" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Area"><input v-model="form.area" class="input" /></Field>
                <Field label="City"><input v-model="form.city" class="input" /></Field>
            </div>
            <Field label="Delivery instructions"><input v-model="form.delivery_instructions" class="input" /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="E-mail" :error="form.errors.email"><input v-model="form.email" type="email" class="input" /></Field>
                <Field label="NTN / CNIC"><input v-model="form.tax_number" class="input" /></Field>
            </div>
            <Field label="Language for messages"><select v-model="form.preferred_language" class="input"><option value="ur">اردو</option><option value="en">English</option></select></Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('Active') }}</label>
        </section>
        <div class="space-y-5">
            <section class="panel space-y-3 p-4">
                <h3>{{ t('Type and prices') }}</h3>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="Customer type"><select v-model="form.category" class="input"><option value="retail">{{ t('Retail') }}</option><option value="wholesale">{{ t('Wholesale') }}</option><option value="shopkeeper">{{ t('Shopkeeper') }}</option><option value="institution">{{ t('Institution') }}</option></select></Field>
                    <Field label="Price list"><select v-model="form.price_book_id" class="input"><option :value="null">{{ t('Standard retail') }}</option><option v-for="b in priceBooks" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                </div>
                <Field label="Sales agent"><ContactPicker v-model="agent" type="employee" :placeholder="c?.customer_profile?.sales_agent?.name ?? t('None')" /></Field>
                <Field label="Tags">
                    <div class="flex flex-wrap gap-2"><label v-for="tg in tags" :key="tg.id" class="flex items-center gap-1 text-sm"><input v-model="form.tag_ids" type="checkbox" :value="tg.id" class="rounded border-ink-300" />{{ tg.name }}</label><span v-if="!tags.length" class="text-sm text-ink-500">{{ t('Tags are added in Settings → Lists.') }}</span></div>
                </Field>
            </section>
            <section class="panel space-y-3 p-4">
                <h3>{{ t('Khata (credit)') }}</h3>
                <p v-if="!canCredit" class="text-sm text-ink-500">{{ t('Only managers with credit permission can change these.') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="Credit limit (Rs)" :error="form.errors.credit_limit"><input v-model="form.credit_limit" type="number" min="0" class="input text-end" :disabled="!canCredit" /></Field>
                    <Field label="Days to pay"><input v-model="form.payment_terms_days" type="number" min="0" class="input text-end" :disabled="!canCredit" /></Field>
                </div>
                <Field label="Credit status"><select v-model="form.credit_status" class="input" :disabled="!canCredit"><option value="active">{{ t('Allowed') }}</option><option value="on_hold">{{ t('On hold') }}</option><option value="blocked">{{ t('Blocked') }}</option></select></Field>
                <Field v-if="form.credit_status !== 'active'" label="Why" :error="form.errors.credit_status_reason"><input v-model="form.credit_status_reason" class="input" :disabled="!canCredit" /></Field>
            </section>
            <section class="panel space-y-2 p-4">
                <label class="flex items-center gap-2 text-sm"><input v-model="form.marketing_consent" type="checkbox" class="rounded border-ink-300" />{{ t('Customer agreed to offers by SMS / WhatsApp') }}</label>
                <div v-if="form.marketing_consent" class="flex gap-4 ps-6 text-sm"><label v-for="ch in ['sms', 'whatsapp']" :key="ch" class="flex items-center gap-1"><input v-model="form.consent_channels" type="checkbox" :value="ch" class="rounded border-ink-300" />{{ ch === 'sms' ? 'SMS' : 'WhatsApp' }}</label></div>
            </section>
            <div v-if="duplicate" class="rounded border border-haldi-300 bg-haldi-50 p-3 text-sm">
                <p>{{ form.errors.business }}</p>
                <label class="mt-2 flex items-center gap-2 font-medium"><input v-model="form.confirm_not_duplicate" type="checkbox" class="rounded border-ink-300" />{{ t('I checked: this is a different customer') }}</label>
            </div>
            <p v-else-if="form.errors.business" class="text-sm text-ledger-red">{{ form.errors.business }}</p>
            <div class="flex justify-end gap-2"><Button :href="c ? route('crm.customers.show', c.id) : route('crm.customers.index')">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button></div>
        </div>
    </div>
</template>
