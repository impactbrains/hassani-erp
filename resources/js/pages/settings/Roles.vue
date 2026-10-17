<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { t } from '@/lib/i18n';

/** Roles bundle permissions. You can only give permissions you have yourself. */
const props = defineProps<{ roles: any[]; groups: Record<string, { label: string; permissions: Record<string, { label: string; sensitive?: boolean }> }>; mine: string[]; canManage: boolean }>();
const selectedId = ref<number | 'new' | null>(props.roles.find((r) => !r.is_owner)?.id ?? props.roles[0]?.id ?? null);
const selected = computed(() => (selectedId.value === 'new' ? null : props.roles.find((r) => r.id === selectedId.value)));
const form = useForm({ name: '', description: '', permissions: [] as string[] });
function load() {
    const r = selected.value;
    form.name = r?.name ?? '';
    form.description = r?.description ?? '';
    form.permissions = r ? [...r.permissions] : [];
    form.clearErrors();
}
load();
function pick(id: number | 'new') {
    selectedId.value = id;
    load();
}
const editable = computed(() => props.canManage && !selected.value?.is_owner);
function toggleGroup(g: string, on: boolean) {
    const keys = Object.keys(props.groups[g].permissions).filter((k) => props.mine.includes(k));
    form.permissions = on ? [...new Set([...form.permissions, ...keys])] : form.permissions.filter((p) => !keys.includes(p));
}
function save() {
    selected.value ? form.put(route('settings.roles.update', selected.value.id), { preserveScroll: true }) : form.post(route('settings.roles.store'), { preserveScroll: true });
}
function remove() {
    if (selected.value && confirm(t('Delete this role?'))) router.delete(route('settings.roles.destroy', selected.value.id), { onSuccess: () => pick(props.roles[0]?.id) });
}
</script>

<template>
    <PageHeader :title="t('Roles and permissions')" :subtitle="t('Give people a role in Users. A role can be limited to one store there.')">
        <template #actions><Button v-if="canManage" variant="primary" @click="pick('new')">{{ t('New role') }}</Button></template>
    </PageHeader>
    <div class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
        <aside class="panel divide-y divide-ink-100">
            <button v-for="r in roles" :key="r.id" class="block w-full px-4 py-3 text-start" :class="selectedId === r.id ? 'bg-haldi-50' : 'hover:bg-ink-50/60'" @click="pick(r.id)">
                <div class="flex items-center gap-2 font-medium">{{ r.name }}<Lock v-if="r.is_owner" class="h-3.5 w-3.5 text-ink-400" /></div>
                <div class="text-[12px] text-ink-500">{{ r.assignments_count }} {{ t('people') }} · {{ r.permissions.length }} {{ t('permissions') }}</div>
            </button>
        </aside>
        <section class="panel">
            <div class="space-y-3 border-b border-ink-100 p-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <label><span class="field-label">{{ t('Name') }}</span><input v-model="form.name" class="input" :disabled="!editable" /></label>
                    <label><span class="field-label">{{ t('Description') }}</span><input v-model="form.description" class="input" :disabled="!editable" /></label>
                </div>
                <p v-if="selected?.is_owner" class="text-sm text-ink-600">{{ t('The owner role always has every permission and cannot be changed.') }}</p>
                <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ e }}</p>
            </div>
            <div class="grid gap-x-6 gap-y-5 p-4 md:grid-cols-2">
                <div v-for="(g, gk) in groups" :key="gk">
                    <div class="mb-1 flex items-center justify-between"><h3>{{ t(g.label) }}</h3><span v-if="editable" class="text-[12px]"><button class="link" @click="toggleGroup(gk as string, true)">{{ t('all') }}</button> · <button class="link" @click="toggleGroup(gk as string, false)">{{ t('none') }}</button></span></div>
                    <label v-for="(p, pk) in g.permissions" :key="pk" class="flex items-start gap-2 py-0.5 text-[13.5px]" :class="!mine.includes(pk as string) && editable ? 'opacity-50' : ''">
                        <input v-model="form.permissions" type="checkbox" :value="pk" class="mt-1 rounded border-ink-300" :disabled="!editable || !mine.includes(pk as string)" />
                        <span>{{ t(p.label) }}<Badge v-if="p.sensitive" class="ms-1" tone="warn" :label="t('sensitive')" /></span>
                    </label>
                </div>
            </div>
            <div v-if="editable" class="flex justify-between border-t border-ink-100 p-3">
                <Button v-if="selected && !selected.is_system" variant="danger" @click="remove">{{ t('Delete role') }}</Button><span v-else></span>
                <Button variant="primary" :loading="form.processing" :disabled="!form.name" @click="save">{{ t('Save role') }}</Button>
            </div>
        </section>
    </div>
</template>
