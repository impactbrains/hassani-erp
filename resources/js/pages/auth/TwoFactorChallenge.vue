<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

const form = useForm({ code: '' });
</script>

<template>
    <AuthLayout title="Enter your code" subtitle="Open your authenticator app and type the 6-digit code for Hassani ERP.">
        <form class="space-y-4" @submit.prevent="form.post(route('two-factor.challenge'))">
            <Field label="Code" :error="form.errors.code"><input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="input text-center text-2xl tracking-[0.4em]" autofocus /></Field>
            <Button type="submit" variant="ink" size="lg" class="w-full" :loading="form.processing">{{ t('Sign in') }}</Button>
        </form>
    </AuthLayout>
</template>
