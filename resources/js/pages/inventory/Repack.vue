<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { dateTime, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Break bulk into retail packs, e.g. one 50 kg rice bag into fifty 1 kg packets. The cost moves with the goods. */
defineProps<{ orders: any }>();
const { here: locations } = useStoreLocations();
const source = ref<any>(null);
const target = ref<any>(null);
const packaging = ref<any>(null);
const form = useForm({ source_variant_id: null as number | null, source_location_id: null as number | null, source_quantity: '', target_variant_id: null as number | null, target_location_id: null as number | null, target_quantity: '', packaging_variant_id: null as number | null, packaging_quantity: '', note: '' });
function submit() {
    form.transform((d) => ({ ...d, packaging_quantity: d.packaging_quantity || null })).post(route('inventory.repack.store'), {
        preserveScroll: true,
        onSuccess: () => { form.reset(); source.value = target.value = packaging.value = null; },
    });
}
</script>

<template>
    <PageHeader :title="t('Repack')" :subtitle="t('Break a bulk item into smaller packs. Stock and cost move from the bulk item to the packs.')" />
    <section class="panel mb-5 grid gap-5 p-4 lg:grid-cols-3">
        <div class="space-y-3">
            <h3>{{ t('1. Take from (bulk)') }}</h3>
            <div v-if="source" class="flex items-center justify-between rounded bg-ink-50 px-3 py-2 text-sm"><span>{{ source.name }} · {{ qty(source.available) }}</span><button class="link" @click="source = null; form.source_variant_id = null">{{ t('Change') }}</button></div>
            <ProductPicker v-else purpose="any" @pick="(p: any) => { source = p; form.source_variant_id = p.variant_id; }" />
            <Field label="Location" :error="form.errors.source_location_id"><select v-model="form.source_location_id" class="input"><option :value="null">—</option><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></Field>
            <Field label="Quantity used" :error="form.errors.source_quantity"><input v-model="form.source_quantity" type="number" min="0" step="any" class="input" /></Field>
        </div>
        <div class="space-y-3">
            <h3>{{ t('2. Make (packs)') }}</h3>
            <div v-if="target" class="flex items-center justify-between rounded bg-ink-50 px-3 py-2 text-sm"><span>{{ target.name }}</span><button class="link" @click="target = null; form.target_variant_id = null">{{ t('Change') }}</button></div>
            <ProductPicker v-else purpose="any" @pick="(p: any) => { target = p; form.target_variant_id = p.variant_id; }" />
            <Field label="Location" :error="form.errors.target_location_id"><select v-model="form.target_location_id" class="input"><option :value="null">—</option><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></Field>
            <Field label="Packs made" :error="form.errors.target_quantity"><input v-model="form.target_quantity" type="number" min="0" step="any" class="input" /></Field>
        </div>
        <div class="space-y-3">
            <h3>{{ t('3. Packing material (optional)') }}</h3>
            <div v-if="packaging" class="flex items-center justify-between rounded bg-ink-50 px-3 py-2 text-sm"><span>{{ packaging.name }}</span><button class="link" @click="packaging = null; form.packaging_variant_id = null">{{ t('Change') }}</button></div>
            <ProductPicker v-else purpose="any" :placeholder="t('Bags, boxes …')" @pick="(p: any) => { packaging = p; form.packaging_variant_id = p.variant_id; }" />
            <Field label="Quantity used" :error="form.errors.packaging_quantity"><input v-model="form.packaging_quantity" type="number" min="0" step="any" class="input" /></Field>
            <Field label="Note"><input v-model="form.note" class="input" /></Field>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            <Button variant="primary" class="w-full" :disabled="!form.source_variant_id || !form.target_variant_id" :loading="form.processing" @click="submit">{{ t('Post repack') }}</Button>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>{{ t('Recent repacks') }}</h2></div>
        <table class="table-base">
            <thead><tr><th>{{ t('Number') }}</th><th>{{ t('When') }}</th><th>{{ t('From') }}</th><th class="num">{{ t('Used') }}</th><th>{{ t('To') }}</th><th class="num">{{ t('Made') }}</th></tr></thead>
            <tbody>
                <tr v-for="o in orders.data" :key="o.id">
                    <td>{{ o.number }}</td><td>{{ dateTime(o.posted_at ?? o.created_at) }}</td>
                    <td>{{ o.source_variant?.display_name }}<div class="text-[12px] text-ink-500">{{ o.source_location?.name }}</div></td><td class="num">{{ qty(o.source_quantity) }}</td>
                    <td>{{ o.target_variant?.display_name }}<div class="text-[12px] text-ink-500">{{ o.target_location?.name }}</div></td><td class="num">{{ qty(o.target_quantity) }}</td>
                </tr>
                <tr v-if="!orders.data.length"><td colspan="6" class="py-8 text-center text-ink-500">{{ t('No repacks yet.') }}</td></tr>
            </tbody>
        </table>
        <Pagination :meta="orders" />
    </section>
</template>
