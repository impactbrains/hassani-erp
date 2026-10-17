<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ContactPicker from '@/components/app/ContactPicker.vue';
import LineEditor from '@/components/app/LineEditor.vue';
import { money, round2 } from '@/lib/format';
import { uuid } from '@/lib/http';
import { t } from '@/lib/i18n';

const props = defineProps<{ order: any | null; customer?: any | null }>();
const editing = !!props.order;
const customer = ref<any>(props.order?.customer ?? props.customer ?? null);
const agent = ref<any>(null);
const form = useForm<any>({
    customer_id: props.order?.customer_id ?? props.customer?.id ?? null,
    channel: props.order?.channel ?? 'phone',
    fulfillment_type: props.order?.fulfillment_type ?? 'delivery',
    delivery_address: props.order?.delivery_address ?? props.customer?.address ?? '',
    delivery_area: props.order?.delivery_area ?? props.customer?.area ?? '',
    delivery_phone: props.order?.delivery_phone ?? props.customer?.phone ?? '',
    requested_delivery_at: props.order?.requested_delivery_at ? String(props.order.requested_delivery_at).slice(0, 16) : '',
    payment_preference: props.order?.payment_preference ?? 'cod',
    delivery_charge: props.order?.delivery_charge ?? '0',
    sales_agent_id: props.order?.sales_agent_id ?? null,
    notes: props.order?.notes ?? '',
    idempotency_key: uuid(),
    lines: (props.order?.lines ?? []).map((l: any) => ({ variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount_amount ?? '0', name: l.variant?.display_name ?? l.description, sku: l.variant?.sku, unit_name: l.variant_unit?.unit?.code })),
});
watch(customer, (c) => {
    form.customer_id = c?.id ?? null;
    if (c && !editing) {
        form.delivery_area = form.delivery_area || c.area || '';
        form.delivery_phone = form.delivery_phone || c.phone || '';
    }
});
watch(agent, (a) => (form.sales_agent_id = a?.id ?? null));
const subtotal = computed(() => round2(form.lines.reduce((s: number, l: any) => s + Number(l.quantity || 0) * Number(l.unit_price || 0) - Number(l.discount || 0), 0)));
const total = computed(() => round2(subtotal.value + Number(form.delivery_charge || 0)));

function submit() {
    form.transform((d: any) => ({ ...d, requested_delivery_at: d.requested_delivery_at || null, lines: d.lines.map((l: any) => ({ variant_id: l.variant_id, variant_unit_id: l.variant_unit_id, quantity: l.quantity, unit_price: l.unit_price === '' ? null : l.unit_price, discount: l.discount || 0 })) }));
    editing ? form.put(route('sales.orders.update', props.order.id)) : form.post(route('sales.orders.store'));
}
</script>

<template>
    <PageHeader :title="editing ? `${t('Edit order')} ${order.number}` : t('New order')" :subtitle="t('Saved as a draft. Confirm it on the next screen to reserve the stock.')" />
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-5">
            <section class="panel grid gap-3 p-4 md:grid-cols-2">
                <Field label="Customer" :error="form.errors.customer_id" required><ContactPicker v-model="customer" type="customer" /></Field>
                <Field label="Channel"><select v-model="form.channel" class="input"><option value="phone">{{ t('Phone') }}</option><option value="whatsapp">WhatsApp</option><option value="field_agent">{{ t('Field agent') }}</option><option value="counter">{{ t('Counter') }}</option><option value="ecommerce">{{ t('Online') }}</option></select></Field>
                <Field label="Sales agent"><ContactPicker v-model="agent" type="employee" :placeholder="order?.sales_agent?.name ?? t('None')" /></Field>
                <Field label="Payment"><select v-model="form.payment_preference" class="input"><option value="cod">{{ t('Cash on delivery') }}</option><option value="khata">{{ t('Khata (credit)') }}</option><option value="wallet">{{ t('Easypaisa / JazzCash') }}</option><option value="prepaid">{{ t('Paid in advance') }}</option></select></Field>
            </section>
            <LineEditor v-model="form.lines" purpose="sale" price-field="unit_price" :customer-id="form.customer_id" :errors="form.errors" :fields="[{ key: 'unit_price', label: 'Price', type: 'number', width: 'w-28' }, { key: 'discount', label: 'Discount', type: 'number', width: 'w-24', default: '0' }]" />
            <Field label="Notes"><textarea v-model="form.notes" rows="2" class="input" /></Field>
        </div>
        <aside class="space-y-5">
            <section class="panel space-y-3 p-4">
                <div class="flex gap-2">
                    <label class="flex flex-1 items-center gap-2 rounded border px-3 py-2 text-sm" :class="form.fulfillment_type === 'delivery' ? 'border-haldi-500 bg-haldi-50' : 'border-ink-200'"><input v-model="form.fulfillment_type" type="radio" value="delivery" />{{ t('Delivery') }}</label>
                    <label class="flex flex-1 items-center gap-2 rounded border px-3 py-2 text-sm" :class="form.fulfillment_type === 'pickup' ? 'border-haldi-500 bg-haldi-50' : 'border-ink-200'"><input v-model="form.fulfillment_type" type="radio" value="pickup" />{{ t('Pickup') }}</label>
                </div>
                <template v-if="form.fulfillment_type === 'delivery'">
                    <Field label="Address" :error="form.errors.delivery_address"><textarea v-model="form.delivery_address" rows="2" class="input" /></Field>
                    <div class="grid grid-cols-2 gap-2">
                        <Field label="Area"><input v-model="form.delivery_area" class="input" /></Field>
                        <Field label="Phone"><input v-model="form.delivery_phone" class="input" /></Field>
                    </div>
                    <Field label="Delivery charge"><input v-model="form.delivery_charge" type="number" min="0" class="input text-end" /></Field>
                </template>
                <Field label="Wanted by"><input v-model="form.requested_delivery_at" type="datetime-local" class="input" /></Field>
            </section>
            <section class="panel space-y-1 p-4 text-sm">
                <div class="flex justify-between"><span>{{ t('Items') }}</span><span class="tabular-nums">{{ money(subtotal) }}</span></div>
                <div v-if="form.fulfillment_type === 'delivery'" class="flex justify-between"><span>{{ t('Delivery') }}</span><span class="tabular-nums">{{ money(form.delivery_charge) }}</span></div>
                <div class="flex justify-between border-t border-ink-100 pt-2 text-base font-semibold"><span>{{ t('Total') }}</span><span class="tabular-nums">{{ money(total) }}</span></div>
                <p class="pt-1 text-[12px] text-ink-500">{{ t('Prices are re-checked against the price list when saved.') }}</p>
            </section>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <div class="flex gap-2"><Button class="flex-1" :href="editing ? route('sales.orders.show', order.id) : route('sales.orders.index')">{{ t('Cancel') }}</Button><Button class="flex-1" variant="primary" :disabled="!form.lines.length || !form.customer_id" :loading="form.processing" @click="submit">{{ t('Save order') }}</Button></div>
        </aside>
    </div>
</template>
