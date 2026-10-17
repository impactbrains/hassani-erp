<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/form/Field.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Keys for a future website or mobile app to read the catalogue and place orders (see README → API). */
const props = defineProps<{ clients: any[]; abilities: Record<string, string>; stores: any[] }>();
const adding = ref(false);
const form = useForm({ name: '', store_id: props.stores[0]?.id ?? null, abilities: ['catalog:read', 'stock:read'] as string[] });
function save() {
    form.post(route('settings.api.store'), { preserveScroll: true, onSuccess: () => { adding.value = false; form.reset('name'); } });
}
function revoke(c: any) {
    if (confirm(t('Revoke this key? Anything using it stops working immediately.'))) router.delete(route('settings.api.destroy', c.id), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="t('API keys')" :subtitle="t('For an online shop or app. Each key is limited to one store and the abilities you tick.')">
        <template #actions><Button variant="primary" @click="adding = true">{{ t('New API key') }}</Button></template>
    </PageHeader>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Name') }}</th><th>{{ t('Store') }}</th><th>{{ t('Can') }}</th><th>{{ t('Created') }}</th><th>{{ t('Last used') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="c in clients" :key="c.id">
                    <td class="font-medium">{{ c.name }}</td><td>{{ c.store?.name }}</td><td class="text-[12.5px]">{{ (c.abilities ?? []).map((a: string) => t(abilities[a] ?? a)).join(', ') }}</td>
                    <td>{{ dateTime(c.created_at) }}</td><td>{{ c.last_used_at ? dateTime(c.last_used_at) : t('never') }}</td><td><Badge :status="c.revoked_at ? 'revoked' : 'active'" /></td>
                    <td><Button v-if="!c.revoked_at" size="sm" variant="danger" @click="revoke(c)">{{ t('Revoke') }}</Button></td>
                </tr>
                <tr v-if="!clients.length"><td colspan="7" class="py-8 text-center text-ink-500">{{ t('No API keys.') }}</td></tr>
            </tbody>
        </table>
    </section>
    <Modal :open="adding" :title="t('New API key')" size="md" @close="adding = false">
        <div class="space-y-3">
            <Field label="Name" :error="form.errors.name" required><input v-model="form.name" class="input" :placeholder="t('Online shop')" /></Field>
            <Field label="Store"><select v-model="form.store_id" class="input"><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
            <Field label="Allowed to" :error="form.errors.abilities"><label v-for="(l, k) in abilities" :key="k" class="flex items-center gap-2 py-0.5 text-sm"><input v-model="form.abilities" type="checkbox" :value="k" class="rounded border-ink-300" />{{ t(l) }}</label></Field>
        </div>
        <template #footer><Button @click="adding = false">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="save">{{ t('Create key') }}</Button></template>
    </Modal>
</template>
