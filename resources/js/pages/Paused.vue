<script setup lang="ts">
import { PauseCircle } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

/** Fallback when a page component is missing, so a bad link never shows a blank screen. */
defineProps<{ page?: string }>();
</script>

<template>
    <div class="mx-auto mt-16 max-w-lg text-center">
        <PauseCircle class="mx-auto h-12 w-12 text-haldi-500" />
        <h1 class="mt-4 text-xl font-semibold">{{ t('This screen is not available') }}</h1>
        <p class="mt-2 text-ink-600">{{ t('The page you opened does not exist in this version. Use the menu to continue.') }}</p>
        <div class="mt-6 flex justify-center gap-2"><Button :href="route('dashboard')">{{ t('Dashboard') }}</Button><Button variant="primary" :href="route('pos.screen')">{{ t('Open POS') }}</Button></div>
    </div>
</template>
