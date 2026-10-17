<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { RotateCcw } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Field from '@/components/form/Field.vue';
import { t } from '@/lib/i18n';

/**
 * All business settings in one place. Each shows what it does, the value now,
 * and whether it comes from the default, the business, or this store.
 */
const props = defineProps<{ groups: Record<string, string>; settings: any[]; company: any; stores: any[]; storeId: number | null; canCompany: boolean }>();
const group = ref(Object.keys(props.groups)[0]);
const q = ref('');
const values = reactive<Record<string, any>>({});
props.settings.forEach((s) => (values[s.key] = s.type === 'json' ? JSON.stringify(s.value) : s.value));
const shown = computed(() => props.settings.filter((s) => (q.value ? `${s.label} ${s.description}`.toLowerCase().includes(q.value.toLowerCase()) : s.group === group.value) && (!props.storeId || s.scopes.includes('store'))));
const company = useForm({ ...props.company });
function changeStore(id: string) {
    router.get(route('settings.index'), id ? { store_id: id } : {}, { preserveScroll: true });
}
function save(s: any) {
    let value = values[s.key];
    if (s.type === 'json') {
        try { value = JSON.parse(value); } catch { alert(t('This needs a valid list, e.g. [5000, 1000, 500]')); return; }
    }
    if (s.high_impact && !confirm(t('This changes how existing figures are calculated or shown. Continue?'))) return;
    router.put(route('settings.update'), { key: s.key, value, store_id: props.storeId }, { preserveScroll: true });
}
function reset(s: any) {
    router.put(route('settings.update'), { key: s.key, value: null, store_id: props.storeId, clear: true }, { preserveScroll: true });
}
const sourceLabel: Record<string, string> = { default: 'Default', company: 'Business', store: 'This store', user: 'Personal' };
</script>

<template>
    <PageHeader :title="t('Business settings')" :subtitle="t('Receipts, POS rules, discounts, Khata, stock, HR and security.')">
        <template #actions>
            <select class="input w-56" :value="storeId ?? ''" @change="changeStore(($event.target as HTMLSelectElement).value)"><option value="">{{ t('Whole business') }}</option><option v-for="s in stores" :key="s.id" :value="s.id">{{ t('Override for') }} {{ s.name }}</option></select>
        </template>
    </PageHeader>
    <section v-if="canCompany && !storeId" class="panel mb-5 p-4">
        <h3 class="mb-3">{{ t('Business details (printed on receipts and invoices)') }}</h3>
        <div class="grid gap-3 md:grid-cols-4">
            <Field label="Business name" :error="company.errors.name"><input v-model="company.name" class="input" /></Field>
            <Field label="Legal name"><input v-model="company.legal_name" class="input" /></Field>
            <Field label="NTN"><input v-model="company.ntn" class="input" /></Field>
            <Field label="STRN"><input v-model="company.strn" class="input" /></Field>
            <Field label="Phone"><input v-model="company.phone" class="input" /></Field>
            <Field label="E-mail" :error="company.errors.email"><input v-model="company.email" type="email" class="input" /></Field>
            <Field label="Address"><input v-model="company.address" class="input" /></Field>
            <Field label="City"><input v-model="company.city" class="input" /></Field>
        </div>
        <div class="mt-3 flex justify-end"><Button variant="primary" :loading="company.processing" @click="company.put(route('settings.company'), { preserveScroll: true })">{{ t('Save details') }}</Button></div>
    </section>
    <div class="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
        <aside class="space-y-1">
            <input v-model="q" type="search" class="input mb-2" :placeholder="t('Find a setting')" />
            <button v-for="(l, k) in groups" :key="k" class="block w-full rounded px-3 py-2 text-start text-sm" :class="!q && group === k ? 'bg-ink-800 font-medium text-white' : 'hover:bg-ink-50'" @click="group = k; q = ''">{{ t(l) }}</button>
        </aside>
        <section class="panel divide-y divide-ink-100">
            <div v-for="s in shown" :key="s.key" class="grid gap-3 p-4 md:grid-cols-[minmax(0,1fr)_300px]">
                <div>
                    <div class="flex flex-wrap items-center gap-2"><span class="font-medium">{{ t(s.label) }}</span><Badge :tone="s.source === 'default' ? 'neutral' : 'info'" :label="t(sourceLabel[s.source] ?? s.source)" /><Badge v-if="s.high_impact" tone="warn" :label="t('affects reports')" /></div>
                    <p class="mt-0.5 text-[13px] text-ink-600">{{ t(s.description) }}</p>
                </div>
                <div class="flex items-start gap-2">
                    <div class="flex-1">
                        <select v-if="s.options" v-model="values[s.key]" class="input" :disabled="!s.can_edit"><option v-for="(l, k) in s.options" :key="k" :value="s.type === 'int' ? Number(k) : k">{{ l }}</option></select>
                        <label v-else-if="s.type === 'bool'" class="flex items-center gap-2 py-2 text-sm"><input v-model="values[s.key]" type="checkbox" class="rounded border-ink-300" :disabled="!s.can_edit" />{{ values[s.key] ? t('On') : t('Off') }}</label>
                        <input v-else-if="s.type === 'int' || s.type === 'decimal'" v-model="values[s.key]" type="number" step="any" class="input text-end" :disabled="!s.can_edit" />
                        <textarea v-else-if="s.type === 'json'" v-model="values[s.key]" rows="2" class="input font-mono text-[12.5px]" :disabled="!s.can_edit" />
                        <input v-else v-model="values[s.key]" class="input" :disabled="!s.can_edit" />
                    </div>
                    <template v-if="s.can_edit">
                        <Button size="sm" variant="primary" @click="save(s)">{{ t('Save') }}</Button>
                        <button v-if="s.source !== 'default' && (storeId ? s.source === 'store' : s.source === 'company')" class="mt-1.5 text-ink-400 hover:text-ink-700" :title="t('Back to default')" @click="reset(s)"><RotateCcw class="h-4 w-4" /></button>
                    </template>
                </div>
            </div>
            <p v-if="!shown.length" class="p-6 text-center text-ink-500">{{ storeId ? t('No setting in this group can differ per store.') : t('Nothing matches.') }}</p>
        </section>
    </div>
</template>
