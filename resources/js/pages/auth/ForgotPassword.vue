<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { t } from '@/lib/i18n';
</script>

<template>
    <AuthLayout title="Forgot your password?">
        <div class="space-y-3 text-[15px] text-ink-700">
            <p>{{ t('Ask the owner or a manager who looks after users. They can give you a new temporary password from Settings → Users.') }}</p>
            <p>{{ t('If the owner has lost their own password, it can be reset on the shop computer. The steps are in the README under “Forgotten owner password”.') }}</p>
            <Link :href="route('login')" class="link">{{ t('Back to sign in') }}</Link>
        </div>
    </AuthLayout>
</template>
