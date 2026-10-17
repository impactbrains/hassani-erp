<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { colorHex } from '@/lib/colors';
import { date } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Every simple list in one place: categories, brands, units, payment methods, reasons, locations, POS counters, departments, leave types, shifts, tags … */
const props = defineProps<{ lists: { key: string; label: string; section: string }[]; list: { key: string; label: string; append_only: boolean; fields: Record<string, any> }; rows: any[]; sources: Record<string, any[]> }>();
const sections: Record<string, string> = { catalog: 'Products', sales: 'Sales and POS', inventory: 'Inventory', hr: 'HR', crm: 'Customers' };
const grouped = computed(() => Object.entries(sections).map(([k, l]) => ({ key: k, label: l, lists: props.lists.filter((x) => x.section === k) })).filter((g) => g.lists.length));
const editing = ref<any>(null);
const form = useForm<Record<string, any>>({});
const visibleCols = computed(() => Object.entries(props.list.fields).filter(([k]) => !['name_ur', 'label_ur', 'sort_order', 'note'].includes(k)).slice(0, 6));
function open(row: any | null) {
    const data: Record<string, any> = {};
    Object.entries(props.list.fields).forEach(([k, f]: [string, any]) => {
        data[k] = row ? (f.type === 'date' && row[k] ? String(row[k]).slice(0, 10) : f.type === 'time' && row[k] ? String(row[k]).slice(0, 5) : row[k]) : f.type === 'bool' ? (f.default ?? true) : f.type === 'select' ? Object.keys(f.options)[0] : null;
    });
    form.defaults(data);
    form.reset();
    form.clearErrors();
    editing.value = row ?? {};
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? form.put(route('settings.lists.update', { list: props.list.key, id: editing.value.id }), opts) : form.post(route('settings.lists.store', { list: props.list.key }), opts);
}
function remove(row: any) {
    if (confirm(t('Delete? If it is in use it will be deactivated instead.'))) router.delete(route('settings.lists.destroy', { list: props.list.key, id: row.id }), { preserveScroll: true });
}
function show(k: string, f: any, v: any) {
    if (v === null || v === undefined || v === '') return '—';
    if (f.type === 'bool') return v ? t('Yes') : t('No');
    if (f.type === 'select') return t(f.options[v] ?? v);
    if (f.type === 'ref') return props.sources[f.source]?.find((x: any) => x.id === v)?.name ?? v;
    if (f.type === 'date') return date(v);
    if (f.type === 'time') return String(v).slice(0, 5);
    return v;
}
</script>

<template>
    <PageHeader :title="t('Lists')" :subtitle="t('The choices used across the app. Items in use are deactivated rather than deleted, so history stays correct.')" />
    <div class="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <div v-for="g in grouped" :key="g.key">
                <div class="mb-1 px-3 text-[12px] font-semibold uppercase tracking-wide text-ink-400">{{ t(g.label) }}</div>
                <a v-for="l in g.lists" :key="l.key" :href="route('settings.lists', { list: l.key })" class="block rounded px-3 py-1.5 text-sm" :class="l.key === list.key ? 'bg-ink-800 font-medium text-white' : 'hover:bg-ink-50'">{{ t(l.label) }}</a>
            </div>
        </aside>
        <section class="panel">
            <div class="panel-head"><h2>{{ t(list.label) }}</h2><Button size="sm" variant="primary" @click="open(null)"><Plus class="h-4 w-4" />{{ t('Add') }}</Button></div>
            <p v-if="list.append_only" class="border-b border-ink-100 px-4 py-2 text-[12.5px] text-ink-600">{{ t('History is kept: to change a rate, add a new row with the date it starts.') }}</p>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th v-for="[k, f] in visibleCols" :key="k">{{ t(f.label) }}</th><th class="w-24"></th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" :class="r.is_active === false ? 'opacity-50' : ''">
                            <td v-for="[k, f] in visibleCols" :key="k"><span v-if="k === 'color'" class="inline-block h-3 w-3 rounded-sm align-middle" :style="{ background: colorHex(r[k]) }" /> {{ show(k, f, r[k]) }}<div v-if="(k === 'name' || k === 'label') && (r.name_ur || r.label_ur)" class="urdu text-[13px] text-ink-500">{{ r.name_ur ?? r.label_ur }}</div></td>
                            <td class="whitespace-nowrap text-end"><button v-if="!list.append_only" class="link me-3 text-[13px]" @click="open(r)">{{ t('Edit') }}</button><button class="text-ink-400 hover:text-ledger-red" :aria-label="t('Delete')" @click="remove(r)"><Trash2 class="inline h-4 w-4" /></button></td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="visibleCols.length + 1" class="py-8 text-center text-ink-500">{{ t('Nothing yet.') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <Modal :open="!!editing" :title="`${editing?.id ? t('Edit') : t('Add')} · ${t(list.label)}`" size="md" @close="editing = null">
        <div class="grid grid-cols-2 gap-3">
            <template v-for="(f, k) in list.fields" :key="k">
                <label v-if="f.type === 'bool'" class="col-span-2 flex items-center gap-2 text-sm"><input v-model="form[k]" type="checkbox" class="rounded border-ink-300" />{{ t(f.label) }}</label>
                <Field v-else :label="f.label" :error="form.errors[k]" :class="f.type === 'text' && !String(k).endsWith('code') ? 'col-span-2' : ''">
                    <select v-if="f.type === 'select'" v-model="form[k]" class="input"><option v-for="(l, o) in f.options" :key="o" :value="o">{{ t(l) }}</option></select>
                    <select v-else-if="f.type === 'ref'" v-model="form[k]" class="input"><option :value="null">—</option><option v-for="o in sources[f.source] ?? []" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <input v-else-if="f.type === 'number' || f.type === 'decimal'" v-model="form[k]" type="number" step="any" class="input" />
                    <input v-else-if="f.type === 'date'" v-model="form[k]" type="date" class="input" />
                    <input v-else-if="f.type === 'time'" v-model="form[k]" type="time" class="input" />
                    <input v-else v-model="form[k]" class="input" :dir="f.dir" :class="f.dir === 'rtl' ? 'urdu' : ''" />
                </Field>
            </template>
        </div>
        <template #footer><Button @click="editing = null">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
