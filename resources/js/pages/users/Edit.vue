<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';

interface PermissionRow {
    app: string;
    access_level: 'none' | 'user' | 'admin';
}

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        is_admin: boolean;
        has_dashboard_access: boolean;
    };
    permissions: PermissionRow[];
}>();

// Human-readable labels + grouping, purely for display.
const appLabels: Record<string, string> = {
    pos: 'POS',
    inventory: 'Inventory',
    products: 'Products',
    invoicing: 'Invoicing',
    sales: 'Sales',
    accounting: 'Accounting',
    website: 'Website',
    contacts: 'Contacts',
    barcode: 'Barcode',
    todo: 'To-Do',
    chat: 'Chat',
};

const form = useForm({
    is_admin: props.user.is_admin,
    has_dashboard_access: props.user.has_dashboard_access,
    permissions: props.permissions.map((p) => ({ ...p })),
});

function submit() {
    form.put(route('users.update', { user: props.user.id }));
}
</script>

<template>
    <Head :title="`Permissions — ${user.name}`" />

    <DashboardLayout>
        <div class="mx-auto max-w-3xl">
            <Link :href="route('users.index')" class="font-sans text-sm text-slate-500 hover:underline">
                &larr; Back to Users
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">{{ user.name }}</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">{{ user.email }}</p>

            <form @submit.prevent="submit" class="mt-8 flex flex-col gap-8">
                <!-- Global settings -->
                <section class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-4 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Account
                    </h2>

                    <label class="flex items-center gap-3">
                        <input
                            type="checkbox"
                            v-model="form.is_admin"
                            class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                        />
                        <span class="font-sans text-sm text-ink-900">
                            Administrator
                            <span class="block text-xs font-normal text-slate-500">
                                Full access to every app, plus user management. Overrides all per-app settings below.
                            </span>
                        </span>
                    </label>

                    <label class="mt-4 flex items-center gap-3">
                        <input
                            type="checkbox"
                            v-model="form.has_dashboard_access"
                            class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                        />
                        <span class="font-sans text-sm text-ink-900">
                            Dashboard access
                            <span class="block text-xs font-normal text-slate-500">
                                Can log in and see the app dashboard at all.
                            </span>
                        </span>
                    </label>
                </section>

                <!-- Per-app permissions -->
                <section
                    class="rounded-lg border border-slate-200 bg-white p-5"
                    :class="{ 'opacity-50': form.is_admin }"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                            App permissions
                        </h2>
                        <span v-if="form.is_admin" class="font-sans text-xs text-slate-400">
                            Disabled — user is a global Administrator
                        </span>
                    </div>

                    <fieldset :disabled="form.is_admin" class="flex flex-col divide-y divide-slate-100">
                        <div
                            v-for="(permission, index) in form.permissions"
                            :key="permission.app"
                            class="flex items-center justify-between py-3"
                        >
                            <span class="font-sans text-sm font-medium text-ink-900">
                                {{ appLabels[permission.app] ?? permission.app }}
                            </span>

                            <div class="flex gap-4">
                                <label
                                    v-for="level in (['none', 'user', 'admin'] as const)"
                                    :key="level"
                                    class="flex items-center gap-1.5"
                                >
                                    <input
                                        type="radio"
                                        :name="`permission-${permission.app}`"
                                        :value="level"
                                        v-model="form.permissions[index].access_level"
                                        class="h-4 w-4 border-slate-300 text-brand-500 focus:ring-brand-500"
                                    />
                                    <span class="font-sans text-sm capitalize text-ink-900">{{ level }}</span>
                                </label>
                            </div>
                        </div>
                    </fieldset>
                </section>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-5 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                    >
                        Save permissions
                    </button>
                    <span v-if="form.recentlySuccessful" class="font-sans text-sm text-slate-500">
                        Saved.
                    </span>
                </div>
            </form>
        </div>
    </DashboardLayout>
</template>