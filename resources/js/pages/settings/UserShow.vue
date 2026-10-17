<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Field from '@/components/form/Field.vue';
import RoleRows from '@/components/app/RoleRows.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ account: any; effective: Record<string, any>; sessions: any[]; logins: any[]; canManage: boolean; isSelf: boolean; roles: any[]; stores: any[]; employees: any[] }>();
const form = useForm({
    name: props.account.name, locale: props.account.locale ?? 'en',
    roles: (props.account.roleAssignments ?? props.account.role_assignments ?? []).map((a: any) => ({ role_id: a.role_id, store_id: a.store_id, expires_at: a.expires_at ? String(a.expires_at).slice(0, 10) : '', reason: a.reason ?? '' })),
});
const reason = ref('');
function save() {
    form.transform((d) => ({ ...d, roles: d.roles.map((r: any) => ({ ...r, expires_at: r.expires_at || null, reason: r.reason || null })) })).put(route('settings.users.update', props.account.id), { preserveScroll: true });
}
function act(action: string) {
    if (['suspend', 'deactivate'].includes(action) && !reason.value) return;
    if (action === 'reset_password' && !confirm(t('Make a new temporary password? They will be signed out everywhere.'))) return;
    router.post(route('settings.users.action', props.account.id), { action, reason: reason.value || null }, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="account.name" :subtitle="account.email">
        <template #actions><Badge :status="account.status" /><Button :href="route('settings.users.index')">{{ t('All users') }}</Button></template>
    </PageHeader>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section class="panel space-y-4 p-4">
            <div class="grid gap-3 md:grid-cols-2">
                <Field label="Name" :error="form.errors.name"><input v-model="form.name" class="input" :disabled="!canManage" /></Field>
                <Field label="Language"><select v-model="form.locale" class="input" :disabled="!canManage"><option value="en">English</option><option value="ur">اردو</option></select></Field>
            </div>
            <div>
                <h3 class="mb-2">{{ t('Roles') }}</h3>
                <RoleRows v-model="form.roles" :roles="roles" :stores="stores" :disabled="!canManage || isSelf" />
                <p v-if="isSelf" class="mt-1 text-[12.5px] text-ink-500">{{ t('You cannot change your own roles.') }}</p>
            </div>
            <div v-if="canManage" class="flex justify-end"><Button variant="primary" :loading="form.processing" @click="save">{{ t('Save') }}</Button></div>
        </section>
        <aside class="space-y-5">
            <section class="panel space-y-2 p-4 text-sm">
                <div>{{ t('Last sign-in') }}: {{ account.last_login_at ? dateTime(account.last_login_at) : '—' }}</div>
                <div>{{ t('Approval PIN') }}: {{ account.has_pin ? t('Set') : t('Not set') }}</div>
                <div>{{ t('Authenticator app') }}: {{ account.two_factor_confirmed_at ? t('On') : t('Off') }}</div>
                <div v-if="account.must_change_password" class="text-haldi-800">{{ t('Must choose a new password at next sign-in.') }}</div>
            </section>
            <section v-if="canManage && !isSelf" class="panel space-y-3 p-4">
                <Button class="w-full" @click="act('reset_password')">{{ t('New temporary password') }}</Button>
                <template v-if="account.status === 'active'">
                    <input v-model="reason" class="input" :placeholder="t('Reason (needed to suspend or deactivate)')" />
                    <div class="flex gap-2"><Button class="flex-1" :disabled="!reason" @click="act('suspend')">{{ t('Suspend') }}</Button><Button variant="danger" class="flex-1" :disabled="!reason" @click="act('deactivate')">{{ t('Deactivate') }}</Button></div>
                </template>
                <Button v-else variant="primary" class="w-full" @click="act('reactivate')">{{ t('Reactivate') }}</Button>
            </section>
            <section class="panel p-4">
                <h3 class="mb-2">{{ t('Recent sign-ins') }}</h3>
                <ul class="space-y-1 text-[13px] text-ink-600"><li v-for="(l, i) in logins" :key="i">{{ dateTime(l.created_at) }} · {{ l.event.replaceAll('_', ' ') }} · {{ l.ip_address }}</li></ul>
            </section>
        </aside>
    </div>
</template>
