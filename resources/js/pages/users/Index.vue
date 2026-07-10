<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ShieldCheck, Shield } from 'lucide-vue-next';

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    has_dashboard_access: boolean;
}

defineProps<{
    users: UserRow[];
}>();
</script>

<template>
    <Head title="Users" />

    <DashboardLayout>
        <div class="mx-auto max-w-4xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Users</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Manage accounts and per-app permissions.
            </p>

            <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Name</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Email</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Role</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                            <td class="px-4 py-3 font-medium text-ink-900">{{ user.name }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ user.email }}</td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="user.is_admin"
                                    class="inline-flex items-center gap-1 rounded-full bg-brand-500/15 px-2 py-0.5 text-xs font-medium text-ink-800"
                                >
                                    <ShieldCheck :size="13" /> Admin
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500"
                                >
                                    <Shield :size="13" /> User
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="route('users.edit', { user: user.id })"
                                    class="font-medium text-ink-800 hover:underline"
                                >
                                    Manage permissions
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>