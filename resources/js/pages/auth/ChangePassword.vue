<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

const form = useForm({ current_password: '', password: '', password_confirmation: '' });
</script>

<template>
    <AuthLayout title="Choose your own password" subtitle="You signed in with a temporary password. Choose a new one that only you know.">
        <form class="space-y-4" @submit.prevent="form.put(route('password.force.update'))">
            <Field label="Temporary password" :error="form.errors.current_password"><input v-model="form.current_password" type="password" class="input" autocomplete="current-password" required /></Field>
            <Field label="New password" :error="form.errors.password" hint="At least 10 characters. A short sentence works well."><input v-model="form.password" type="password" class="input" autocomplete="new-password" required /></Field>
            <Field label="Repeat new password"><input v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password" required /></Field>
            <Button type="submit" variant="ink" size="lg" class="w-full" :loading="form.processing">{{ t('Save password') }}</Button>
        </form>
    </AuthLayout>
</template>
