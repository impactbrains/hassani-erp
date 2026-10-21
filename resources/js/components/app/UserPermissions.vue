<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { t } from '@/lib/i18n';

/**
 * Per-user permissions: for every permission, where it comes from (which role, or an exception made for this
 * person), whether they have it now, and buttons to give it, take it away or go back to the role default.
 */
type Row = { key: string; label: string; group: string; sensitive: boolean; from_roles: string[]; overrides: { id: number; effect: string; store_id: number | null; store: string | null; reason: string | null; by: string | null }[]; effective: boolean; can_give: boolean };
const props = defineProps<{ userId: number; rows: Row[]; stores: { id: number; name: string }[]; canEdit: boolean; isOwner: boolean; isSelf: boolean }>();
const q = ref('');
const onlyChanged = ref(false);
const shown = computed(() => props.rows.filter((r) => (!onlyChanged.value || r.overrides.length) && (!q.value || `${r.label} ${r.key}`.toLowerCase().includes(q.value.toLowerCase()))));
const groups = computed(() => {
    const out: Record<string, Row[]> = {};
    for (const r of shown.value) (out[r.group] ??= []).push(r);
    return out;
});
const changedCount = computed(() => props.rows.filter((r) => r.overrides.length).length);
const editing = ref<Row | null>(null);
const form = useForm({ permission: '', effect: 'grant', store_id: null as number | null, reason: '' });
function open(row: Row, effect: 'grant' | 'revoke') {
    editing.value = row;
    form.reset();
    form.clearErrors();
    form.permission = row.key;
    form.effect = effect;
}
function save() {
    form.transform((d) => ({ ...d, reason: d.reason || null })).post(route('settings.users.permissions', props.userId), { preserveScroll: true, onSuccess: () => (editing.value = null) });
}
function reset(key: string | null) {
    if (!confirm(key ? t('Go back to what the roles give for this permission?') : t('Remove every exception and go back to what the roles give?'))) return;
    router.post(route('settings.users.permissions.reset', props.userId), { permission: key }, { preserveScroll: true });
}
</script>

<template>
    <section class="panel">
        <div class="panel-head">
            <div><h2>{{ t('Permissions') }}</h2><p class="text-[12.5px] text-ink-500">{{ t('Roles give the base set. Here you can give one extra permission or take one away for this person only.') }}</p></div>
            <Button v-if="canEdit && changedCount" size="sm" @click="reset(null)">{{ t('Reset all to role defaults') }}</Button>
        </div>
        <p v-if="isOwner" class="px-4 py-3 text-sm text-ink-600">{{ t('This person is an owner and always has every permission.') }}</p>
        <template v-else>
            <div class="flex flex-wrap items-center gap-3 border-b border-ink-100 px-4 py-2">
                <input v-model="q" class="input w-64" :placeholder="t('Search permissions')" />
                <label class="flex items-center gap-2 text-sm"><input v-model="onlyChanged" type="checkbox" />{{ t('Only exceptions') }} ({{ changedCount }})</label>
                <p v-if="isSelf" class="text-[12.5px] text-ink-500">{{ t('You cannot change your own permissions.') }}</p>
            </div>
            <div v-for="(list, group) in groups" :key="group" class="border-b border-ink-100 last:border-0">
                <h3 class="bg-ink-50 px-4 py-1.5 text-[12.5px] uppercase tracking-wide text-ink-500">{{ t(String(group)) }}</h3>
                <table class="table-base">
                    <tbody>
                        <tr v-for="r in list" :key="r.key">
                            <td class="w-[38%]"><div>{{ t(r.label) }}<Badge v-if="r.sensitive" class="ms-1" tone="warn" :label="t('Sensitive')" /></div><div class="text-[11.5px] text-ink-400">{{ r.key }}</div></td>
                            <td class="text-[12.5px]">
                                <div v-if="r.from_roles.length">{{ t('From') }}: {{ r.from_roles.join(', ') }}</div>
                                <div v-else class="text-ink-400">{{ t('Not in any of their roles') }}</div>
                                <div v-for="o in r.overrides" :key="o.id" class="mt-0.5">
                                    <Badge :tone="o.effect === 'grant' ? 'good' : 'bad'" :label="o.effect === 'grant' ? t('Given') : t('Taken away')" />
                                    <span class="ms-1">{{ o.store ?? t('every store') }}</span><span v-if="o.reason" class="text-ink-500"> · {{ o.reason }}</span><span v-if="o.by" class="text-ink-400"> · {{ o.by }}</span>
                                </div>
                            </td>
                            <td class="w-20 text-center"><Badge :tone="r.effective ? 'good' : 'neutral'" :label="r.effective ? t('Has it') : t('No')" /></td>
                            <td class="w-72 whitespace-nowrap text-end">
                                <template v-if="canEdit">
                                    <Button v-if="r.can_give" size="sm" variant="ghost" @click="open(r, 'grant')">{{ t('Give') }}</Button>
                                    <Button v-if="r.effective || r.from_roles.length" size="sm" variant="ghost" @click="open(r, 'revoke')">{{ t('Take away') }}</Button>
                                    <Button v-if="r.overrides.length" size="sm" variant="ghost" @click="reset(r.key)">{{ t('Reset to role default') }}</Button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!shown.length" class="px-4 py-6 text-center text-sm text-ink-500">{{ t('Nothing matches.') }}</p>
        </template>
    </section>
    <Modal :open="!!editing" :title="`${form.effect === 'grant' ? t('Give') : t('Take away')}: ${editing ? t(editing.label) : ''}`" size="sm" @close="editing = null">
        <div class="space-y-3">
            <Field label="Where" :error="form.errors.store_id"><select v-model="form.store_id" class="input"><option :value="null">{{ t('Every store') }}</option><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
            <Field label="Reason (optional)" :error="form.errors.reason"><input v-model="form.reason" class="input" /></Field>
            <p v-if="form.effect === 'grant'" class="text-[12.5px] text-ink-500">{{ t('You can only give a permission you hold yourself. It does not open a store the person has no role in.') }}</p>
            <p v-for="(er, k) in form.errors" :key="k" class="text-sm text-ledger-red">{{ er }}</p>
        </div>
        <template #footer><Button @click="editing = null">{{ t('Cancel') }}</Button><Button :variant="form.effect === 'grant' ? 'primary' : 'danger'" :loading="form.processing" @click="save">{{ t('Save') }}</Button></template>
    </Modal>
</template>
