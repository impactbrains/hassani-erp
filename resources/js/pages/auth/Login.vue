<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

defineProps<{ status?: string | null }>();
const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <AuthLayout title="Sign in" subtitle="Use the e-mail and password the owner gave you.">
        <p v-if="status" class="mb-4 rounded bg-ledger-greenSoft px-3 py-2 text-sm text-ledger-green">{{ status }}</p>
        <form class="space-y-4" @submit.prevent="submit">
            <Field label="E-mail" :error="form.errors.email" for="email">
                <input id="email" v-model="form.email" type="email" class="input" autocomplete="username" required autofocus />
            </Field>
            <Field label="Password" :error="form.errors.password" for="password">
                <input id="password" v-model="form.password" type="password" class="input" autocomplete="current-password" required />
            </Field>
            <label class="flex items-center gap-2 text-sm text-ink-700"><input v-model="form.remember" type="checkbox" class="rounded border-ink-300 text-ink-800" />{{ t('Keep me signed in on this computer') }}</label>
            <Button type="submit" variant="ink" size="lg" class="w-full" :loading="form.processing">{{ t('Sign in') }}</Button>
            <Link :href="route('password.request')" class="block text-center text-sm text-ink-500 hover:text-ink-800">{{ t('Forgot your password?') }}</Link>
        </form>
    </AuthLayout>
</template>
