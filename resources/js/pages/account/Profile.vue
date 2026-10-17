<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ profile: any; sessions: any[]; logins: any[]; two_factor_setup: { secret: string; uri: string } | null }>();

const details = useForm({ name: props.profile.name, locale: props.profile.locale ?? 'en' });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const pin = useForm({ current_password: '', pin: '', pin_confirmation: '' });
const twoStart = useForm({ current_password: '' });
const twoConfirm = useForm({ code: '' });
const twoOff = useForm({ current_password: '' });

/** The setup key grouped in fours is easier to type into an authenticator app. */
const grouped = (key: string) => key.match(/.{1,4}/g)?.join(' ') ?? key;

function agent(ua: string | null) {
    if (!ua) return t('Unknown device');
    const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    const os = /Windows/.test(ua) ? 'Windows' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iPhone/iPad' : /Mac OS/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : '';
    return `${browser}${os ? ' on ' + os : ''}`;
}
</script>

<template>
    <PageHeader :title="t('My account')" :subtitle="profile.email" />
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel">
            <div class="panel-head"><h2>{{ t('Name and language') }}</h2></div>
            <form class="space-y-4 p-4" @submit.prevent="details.put(route('account.update'))">
                <Field label="Name" :error="details.errors.name"><input v-model="details.name" class="input" /></Field>
                <Field label="Language"><select v-model="details.locale" class="input"><option value="en">English</option><option value="ur">اردو</option></select></Field>
                <Button type="submit" variant="primary" :loading="details.processing">{{ t('Save') }}</Button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>{{ t('Password') }}</h2></div>
            <form class="space-y-4 p-4" @submit.prevent="password.put(route('account.password'), { onSuccess: () => password.reset() })">
                <Field label="Current password" :error="password.errors.current_password"><input v-model="password.current_password" type="password" class="input" autocomplete="current-password" /></Field>
                <Field label="New password" :error="password.errors.password" hint="At least 10 characters. You stay signed in here; other devices are signed out."><input v-model="password.password" type="password" class="input" autocomplete="new-password" /></Field>
                <Field label="Repeat new password"><input v-model="password.password_confirmation" type="password" class="input" autocomplete="new-password" /></Field>
                <Button type="submit" variant="primary" :loading="password.processing">{{ t('Change password') }}</Button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>{{ t('Approval PIN') }}</h2><Badge :tone="profile.has_pin ? 'good' : 'neutral'" :label="profile.has_pin ? t('Set') : t('Not set')" /></div>
            <form class="space-y-4 p-4" @submit.prevent="pin.put(route('account.pin'), { onSuccess: () => pin.reset() })">
                <p class="text-sm text-ink-600">{{ t('Managers use this PIN on the POS screen to approve discounts, price changes, refunds, payouts and Khata over the limit. Use 4–8 digits nobody can guess.') }}</p>
                <Field label="Your password" :error="pin.errors.current_password"><input v-model="pin.current_password" type="password" class="input" autocomplete="current-password" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="New PIN" :error="pin.errors.pin"><input v-model="pin.pin" type="password" inputmode="numeric" maxlength="8" class="input" autocomplete="off" /></Field>
                    <Field label="Repeat PIN"><input v-model="pin.pin_confirmation" type="password" inputmode="numeric" maxlength="8" class="input" autocomplete="off" /></Field>
                </div>
                <Button type="submit" variant="primary" :loading="pin.processing">{{ t('Save PIN') }}</Button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>{{ t('Authenticator app (2-step sign-in)') }}</h2><Badge :tone="profile.two_factor ? 'good' : 'neutral'" :label="profile.two_factor ? t('On') : t('Off')" /></div>
            <div class="space-y-4 p-4">
                <template v-if="profile.two_factor">
                    <p class="text-sm text-ink-600">{{ t('You are asked for a 6-digit code from your phone each time you sign in.') }}</p>
                    <form class="flex items-end gap-2" @submit.prevent="twoOff.delete(route('account.two-factor.disable'))">
                        <Field label="Your password" :error="twoOff.errors.current_password" class="flex-1"><input v-model="twoOff.current_password" type="password" class="input" /></Field>
                        <Button type="submit" variant="danger">{{ t('Turn off') }}</Button>
                    </form>
                </template>
                <template v-else-if="two_factor_setup">
                    <ol class="list-decimal space-y-1 ps-5 text-sm text-ink-700">
                        <li>{{ t('In Google Authenticator or Microsoft Authenticator, choose “Add account” → “Enter a setup key”.') }}</li>
                        <li>{{ t('Account name: Hassani ERP. Key:') }}</li>
                    </ol>
                    <code class="block select-all break-all rounded border border-haldi-300 bg-haldi-50 px-3 py-2 text-base font-semibold tracking-wider">{{ grouped(two_factor_setup.secret) }}</code>
                    <p class="text-[12.5px] text-ink-500">{{ t('Type of key: time based. Then enter the 6-digit code the app shows.') }}</p>
                    <form class="flex items-end gap-2" @submit.prevent="twoConfirm.put(route('account.two-factor.confirm'))">
                        <Field label="Code" :error="twoConfirm.errors.code" class="flex-1"><input v-model="twoConfirm.code" inputmode="numeric" maxlength="6" class="input" /></Field>
                        <Button type="submit" variant="primary">{{ t('Turn on') }}</Button>
                    </form>
                </template>
                <form v-else class="space-y-3" @submit.prevent="twoStart.post(route('account.two-factor.start'))">
                    <p class="text-sm text-ink-600">{{ t('Recommended for owners and managers. The phone app works without internet.') }}</p>
                    <Field label="Your password" :error="twoStart.errors.current_password"><input v-model="twoStart.current_password" type="password" class="input" /></Field>
                    <Button type="submit">{{ t('Set up') }}</Button>
                </form>
            </div>
        </section>

        <section class="panel lg:col-span-2">
            <div class="panel-head"><h2>{{ t('Where you are signed in') }}</h2></div>
            <ul class="divide-y divide-ink-100">
                <li v-for="s in sessions" :key="s.id" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                    <div class="flex-1"><div class="font-medium">{{ agent(s.agent) }} <Badge v-if="s.current" tone="good" :label="t('This device')" /></div><div class="text-ink-500">{{ s.ip }} · {{ t('last active') }} {{ dateTime(s.last_active) }}</div></div>
                    <Button v-if="!s.current" size="sm" @click="router.delete(route('account.sessions.revoke', s.id), { preserveScroll: true })">{{ t('Sign out') }}</Button>
                </li>
            </ul>
            <div class="border-t border-ink-100 px-4 py-3">
                <h3 class="mb-2">{{ t('Recent sign-ins') }}</h3>
                <ul class="space-y-1 text-[13px] text-ink-600"><li v-for="(l, i) in logins" :key="i">{{ dateTime(l.created_at) }} · {{ l.event.replaceAll('_', ' ') }} · {{ l.ip_address }}</li></ul>
            </div>
        </section>
    </div>
</template>
