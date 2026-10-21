<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ stores: any[] }>();
const adding = ref(false);
const editing = ref<any>(null);
const add = useForm({ name: '', code: '', city: 'Karachi', address: '', timezone: 'Asia/Karachi' });
const edit = useForm({ name: '', name_ur: '', kind: 'retail', phone: '', city: '', address: '', timezone: 'Asia/Karachi', is_active: true });
function openEdit(s: any) {
    editing.value = s;
    Object.assign(edit, { name: s.name, name_ur: s.name_ur ?? '', kind: s.kind ?? 'retail', phone: s.phone ?? '', city: s.city ?? '', address: s.address ?? '', timezone: s.timezone ?? 'Asia/Karachi', is_active: s.is_active });
}
/** Every IANA zone the browser knows, Karachi first. Each store keeps its own, so late-night sales land on the right day and hour. */
const zones: string[] = (() => {
    let all: string[] = [];
    try { all = (Intl as any).supportedValuesOf('timeZone'); } catch { /* older browser */ }
    const base = all.length ? all : ['Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Europe/London', 'America/New_York', 'UTC'];
    return ['Asia/Karachi', ...base.filter((z) => z !== 'Asia/Karachi')];
})();
const zoneLabel = (z: string) => z.replaceAll('_', ' ');
const kinds: Record<string, string> = { retail: 'Retail shop', wholesale: 'Wholesale', retail_wholesale: 'Retail and wholesale', warehouse: 'Warehouse only' };
</script>

<template>
    <PageHeader :title="t('Stores')" :subtitle="t('Each store has its own stock, POS counters, shifts and reports.')">
        <template #actions><Button variant="primary" @click="adding = true">{{ t('Add store') }}</Button></template>
    </PageHeader>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Store') }}</th><th>{{ t('Code') }}</th><th>{{ t('Type') }}</th><th>{{ t('City') }}</th><th>{{ t('Time zone') }}</th><th class="num">{{ t('Locations') }}</th><th class="num">{{ t('POS counters') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="s in stores" :key="s.id">
                    <td class="font-medium">{{ s.name }}<div v-if="s.name_ur" class="urdu text-[13px] text-ink-500">{{ s.name_ur }}</div></td><td>{{ s.code }}</td><td>{{ t(kinds[s.kind] ?? s.kind ?? '') }}</td><td>{{ s.city }}</td><td class="text-[13px] text-ink-600">{{ zoneLabel(s.timezone ?? 'Asia/Karachi') }}</td>
                    <td class="num"><a :href="route('settings.lists', { list: 'locations' })" class="link">{{ s.locations_count }}</a></td><td class="num"><a :href="route('settings.lists', { list: 'registers' })" class="link">{{ s.registers_count }}</a></td>
                    <td><Badge :status="s.is_active ? 'active' : 'inactive'" /></td><td><Button size="sm" @click="openEdit(s)">{{ t('Edit') }}</Button></td>
                </tr>
            </tbody>
        </table>
    </section>
    <Modal :open="adding" :title="t('Add store')" size="md" @close="adding = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Name" :error="add.errors.name" required class="col-span-2"><input v-model="add.name" class="input" /></Field>
            <Field label="Short code (on receipt numbers)" :error="add.errors.code" required><input v-model="add.code" maxlength="10" class="input uppercase" placeholder="LHR" /></Field>
            <Field label="City"><input v-model="add.city" class="input" /></Field>
            <Field label="Address" class="col-span-2"><input v-model="add.address" class="input" /></Field>
            <Field label="Time zone" :error="add.errors.timezone" hint="Receipts, reports and the dashboard show this store's times in this zone." class="col-span-2">
                <select v-model="add.timezone" class="input"><option v-for="z in zones" :key="z" :value="z">{{ zoneLabel(z) }}</option></select>
            </Field>
        </div>
        <p class="mt-3 text-[12.5px] text-ink-500">{{ t('Shop floor, godown and damaged locations and one POS counter are created automatically.') }}</p>
        <template #footer><Button @click="adding = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="add.processing" @click="add.post(route('settings.stores.store'), { onSuccess: () => { adding = false; add.reset(); } })">{{ t('Create') }}</Button></template>
    </Modal>
    <Modal :open="!!editing" :title="editing?.name ?? ''" size="md" @close="editing = null">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Name" :error="edit.errors.name" class="col-span-2"><input v-model="edit.name" class="input" /></Field>
            <Field label="Urdu name" class="col-span-2"><input v-model="edit.name_ur" class="input urdu" dir="rtl" /></Field>
            <Field label="Type"><select v-model="edit.kind" class="input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ t(l) }}</option></select></Field>
            <Field label="Phone"><input v-model="edit.phone" class="input" /></Field>
            <Field label="City"><input v-model="edit.city" class="input" /></Field>
            <Field label="Address"><input v-model="edit.address" class="input" /></Field>
            <Field label="Time zone" :error="edit.errors.timezone" hint="Changing it moves which hour and day this store's sales fall in on reports." class="col-span-2">
                <select v-model="edit.timezone" class="input"><option v-for="z in zones" :key="z" :value="z">{{ zoneLabel(z) }}</option></select>
            </Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="edit.is_active" type="checkbox" class="rounded border-ink-300" />{{ t('Active') }}</label>
        </div>
        <template #footer><Button @click="editing = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="edit.processing" @click="edit.put(route('settings.stores.update', editing.id), { preserveScroll: true, onSuccess: () => (editing = null) })">{{ t('Save') }}</Button></template>
    </Modal>
</template>
