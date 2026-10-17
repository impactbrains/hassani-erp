<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Field from '@/components/form/Field.vue';
import RoleRows from '@/components/app/RoleRows.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ user: any; roles: any[]; stores: any[]; employees: any[] }>();
const cashier = props.roles.find((r) => r.key === 'cashier') ?? props.roles.find((r) => !r.is_owner);
const form = useForm({ name: '', email: '', locale: 'ur', roles: [{ role_id: cashier?.id ?? null, store_id: null, expires_at: '', reason: '' }] as any[] });
function submit() {
    form.transform((d) => ({ ...d, roles: d.roles.map((r: any) => ({ ...r, expires_at: r.expires_at || null, reason: r.reason || null })) })).post(route('settings.users.store'));
}
</script>

<template>
    <PageHeader :title="t('Add user')" :subtitle="t('After saving you will see a temporary password once. Give it to the person; they choose their own at first sign-in.')" />
    <section class="panel max-w-3xl space-y-4 p-4">
        <div class="grid gap-3 md:grid-cols-2">
            <Field label="Name" :error="form.errors.name" required><input v-model="form.name" class="input" /></Field>
            <Field label="E-mail (used to sign in)" :error="form.errors.email" required><input v-model="form.email" type="email" class="input" autocomplete="off" /></Field>
            <Field label="Language"><select v-model="form.locale" class="input"><option value="en">English</option><option value="ur">اردو</option></select></Field>
        </div>
        <div><h3 class="mb-2">{{ t('Roles') }}</h3><RoleRows v-model="form.roles" :roles="roles" :stores="stores" /><p v-if="form.errors.roles" class="mt-1 text-sm text-ledger-red">{{ form.errors.roles }}</p></div>
        <div class="flex justify-end gap-2"><Button :href="route('settings.users.index')">{{ t('Cancel') }}</Button><Button variant="primary" :loading="form.processing" @click="submit">{{ t('Create user') }}</Button></div>
    </section>
</template>
