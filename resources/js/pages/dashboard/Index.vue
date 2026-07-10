<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import AppTile from '@/components/dashboard/AppTile.vue';
import { apps } from '@/config/apps';
import type { AppGroup } from '@/types/dashboard';

const page = usePage();
const isAdmin = computed(() => (page.props.auth as any)?.user?.is_admin ?? false);
const permissions = computed(() => (page.props.permissions as Record<string, 'none' | 'user' | 'admin'>) ?? {});

const groupLabels: Record<AppGroup, string> = {
    operations: 'Operations',
    'back-office': 'Back office',
    'customer-facing': 'Customer facing',
    productivity: 'Productivity',
};

// adminOnly tiles (e.g. "Users") are gated purely by is_admin — they have no
// row in the per-app permissions map since they're not one of the 10 core apps.
// Everything else is gated by the resolved access level for that app.
const visibleApps = computed(() =>
    apps.filter((app) => {
        if (app.adminOnly) return isAdmin.value;
        return (permissions.value[app.key] ?? 'none') !== 'none';
    })
);

const groupedApps = computed(() => {
    const order: AppGroup[] = ['operations', 'back-office', 'customer-facing', 'productivity'];
    return order
        .map((group) => ({
            group,
            label: groupLabels[group],
            items: visibleApps.value.filter((app) => app.group === group),
        }))
        .filter((section) => section.items.length > 0);
});
</script>

<template>
    <Head title="Dashboard" />

    <DashboardLayout>
        <div class="mx-auto max-w-6xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Dashboard</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Everything you need to run the store, in one place.
            </p>

            <div class="mt-8 flex flex-col gap-8">
                <section v-for="section in groupedApps" :key="section.group">
                    <h2 class="mb-3 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {{ section.label }}
                    </h2>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        <AppTile v-for="app in section.items" :key="app.key" :app="app" />
                    </div>
                </section>
            </div>
        </div>
    </DashboardLayout>
</template>