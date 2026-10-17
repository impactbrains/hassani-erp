<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

/** First run only: name the business and create the first owner account. */
const props = defineProps<{ company: { name: string; city: string | null; phone: string | null } | null; store: { name: string; code: string } | null }>();
const form = useForm({
    company_name: props.company?.name ?? '', city: props.company?.city ?? 'Karachi', phone: props.company?.phone ?? '', store_name: props.store?.name ?? '',
    name: '', email: '', password: '', password_confirmation: '', locale: 'en',
});
</script>

<template>
    <AuthLayout title="Set up Hassani ERP" subtitle="This page appears only once. The account you create here is the owner, with full access.">
        <form class="space-y-4" @submit.prevent="form.post(route('setup'))">
            <Field label="Business name" :error="form.errors.company_name" required><input v-model="form.company_name" class="input" required /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="City" :error="form.errors.city"><input v-model="form.city" class="input" /></Field>
                <Field label="Phone" :error="form.errors.phone"><input v-model="form.phone" class="input" /></Field>
            </div>
            <Field label="First store name" :error="form.errors.store_name" required><input v-model="form.store_name" class="input" required /></Field>
            <hr class="border-ink-100" />
            <Field label="Your name" :error="form.errors.name" required><input v-model="form.name" class="input" autocomplete="name" required /></Field>
            <Field label="E-mail" :error="form.errors.email" required><input v-model="form.email" type="email" class="input" autocomplete="username" required /></Field>
            <Field label="Password" :error="form.errors.password" hint="At least 10 characters. A short sentence works well." required><input v-model="form.password" type="password" class="input" autocomplete="new-password" required /></Field>
            <Field label="Repeat password" required><input v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password" required /></Field>
            <Field label="Language">
                <select v-model="form.locale" class="input"><option value="en">English</option><option value="ur">اردو</option></select>
            </Field>
            <Button type="submit" variant="ink" size="lg" class="w-full" :loading="form.processing">{{ t('Create owner account') }}</Button>
        </form>
    </AuthLayout>
</template>
