<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import ProductPicker from '@/components/app/ProductPicker.vue';
import { useFilters } from '@/composables/useFilters';
import { useOptions } from '@/composables/useOptions';
import { useStoreLocations } from '@/composables/useStoreLocations';
import { t } from '@/lib/i18n';

const props = defineProps<{ counts: any; filters: Record<string, any>; canPlan: boolean }>();
const filters = useFilters('inventory.counts.index', { status: props.filters.status ?? '' });
const options = useOptions(['users']);
const { here: locations } = useStoreLocations();
const planning = ref(false);
const picked = ref<any[]>([]);
const form = useForm({ scope: 'full', location_ids: [] as number[], variant_ids: [] as number[], is_blind: true, requires_second_count: false, assigned_to: null as number | null, note: '' });
const scopes: Record<string, string> = { full: 'Everything in the store', location: 'One or more locations', products: 'Chosen products (spot check)' };
const columns = [
    { key: 'number', label: 'Count' }, { key: 'created_at', label: 'Planned', type: 'date' as const }, { key: 'scope_label', label: 'What' }, { key: 'store.name', label: 'Store' },
    { key: 'assignee.name', label: 'Counter' }, { key: 'lines_count', label: 'Lines', type: 'int' as const }, { key: 'is_blind', label: 'Blind', type: 'bool' as const }, { key: 'status', label: 'Status', type: 'status' as const },
];
function addProduct(p: any) {
    if (!form.variant_ids.includes(p.variant_id)) { form.variant_ids.push(p.variant_id); picked.value.push(p); }
}
function submit() {
    form.post(route('inventory.counts.store'));
}
</script>

<template>
    <PageHeader :title="t('Stock counts')" :subtitle="t('Count what is on the shelf and fix the difference. With a blind count, counters do not see the expected quantity.')">
        <template #actions><Button v-if="canPlan" variant="primary" @click="planning = true">{{ t('Plan a count') }}</Button></template>
    </PageHeader>
    <div class="panel mb-4 flex gap-3 p-3">
        <select v-model="filters.status" class="input max-w-[14rem]"><option value="">{{ t('Any status') }}</option><option v-for="s in ['draft', 'in_progress', 'submitted', 'posted']" :key="s" :value="s">{{ t(s.replace('_', ' ')) }}</option></select>
    </div>
    <section class="panel">
        <DataTable :columns="columns" :rows="counts.data.map((r: any) => ({ ...r, scope_label: t(scopes[r.scope] ?? r.scope) }))" :row-href="(r) => route('inventory.counts.show', r.id)" empty="No counts yet." />
        <Pagination :meta="counts" />
    </section>

    <Modal :open="planning" :title="t('Plan a count')" size="lg" @close="planning = false">
        <div class="space-y-4">
            <Field label="What to count">
                <select v-model="form.scope" class="input"><option v-for="(l, k) in scopes" :key="k" :value="k">{{ t(l) }}</option></select>
            </Field>
            <Field v-if="form.scope === 'location'" label="Locations" :error="form.errors.location_ids">
                <div class="flex flex-wrap gap-3"><label v-for="l in locations" :key="l.id" class="flex items-center gap-1.5 text-sm"><input v-model="form.location_ids" type="checkbox" :value="l.id" class="rounded border-ink-300" />{{ l.name }}</label></div>
            </Field>
            <div v-if="form.scope === 'products'">
                <ProductPicker purpose="any" @pick="addProduct" />
                <ul class="mt-2 flex flex-wrap gap-2 text-sm"><li v-for="p in picked" :key="p.variant_id" class="rounded bg-ink-50 px-2 py-0.5">{{ p.name }}</li></ul>
            </div>
            <Field label="Who counts">
                <select v-model="form.assigned_to" class="input"><option :value="null">{{ t('Anyone') }}</option><option v-for="u in options.users ?? []" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_blind" type="checkbox" class="rounded border-ink-300" />{{ t('Blind count (hide expected quantity from the counter)') }}</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.requires_second_count" type="checkbox" class="rounded border-ink-300" />{{ t('Recount big differences before approval') }}</label>
            <Field label="Note"><input v-model="form.note" class="input" /></Field>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
        </div>
        <template #footer><Button @click="planning = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="submit">{{ t('Create count') }}</Button></template>
    </Modal>
</template>
