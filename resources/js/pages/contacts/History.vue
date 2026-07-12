<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, Search } from 'lucide-vue-next';

interface ActivityRow {
    id: number;
    action: string;
    description: string;
    created_at: string;
    formatted_created_at?: string;
    user: { id: number; name: string } | null;
    subject_type: string | null;
    subject_id: number | null;
}

const props = defineProps<{
    activity: {
        data: ActivityRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { action?: string; search?: string };
}>();

const search = ref(props.filters.search ?? '');
const action = ref(props.filters.action ?? '');

function applyFilters() {
    router.get(
        route('contacts.history'),
        { search: search.value || undefined, action: action.value || undefined },
        { preserveState: true, replace: true }
    );
}

let debounceTimer: ReturnType<typeof setTimeout>;
watch([search, action], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

const actionOptions = [
    { value: '', label: 'All actions' },
    { value: 'created', label: 'Contact created' },
    { value: 'updated', label: 'Contact updated' },
    { value: 'deactivated', label: 'Contact deactivated' },
    { value: 'deleted', label: 'Contact deleted' },
    { value: 'record_added', label: 'Record added' },
    { value: 'record_updated', label: 'Record updated' },
    { value: 'record_deleted', label: 'Record deleted' },
];

const actionBadgeClass: Record<string, string> = {
    created: 'bg-brand-500/15 text-ink-800',
    updated: 'bg-amber-100 text-amber-700',
    deactivated: 'bg-slate-100 text-slate-600',
    deleted: 'bg-danger-500/15 text-danger-500',
    record_added: 'bg-brand-500/15 text-ink-800',
    record_updated: 'bg-amber-100 text-amber-700',
    record_deleted: 'bg-danger-500/15 text-danger-500',
};

function goToSubject(row: ActivityRow) {
    if (row.subject_type?.endsWith('Contact') && row.subject_id) {
        router.visit(route('contacts.show', { contact: row.subject_id }));
    }
}
</script>

<template>
    <Head title="Contacts History" />

    <DashboardLayout>
        <div class="mx-auto max-w-4xl">
            <Link :href="route('contacts.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to Contacts
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">Contact History</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Every change and record added across all contacts.
            </p>

            <div class="mt-4 flex flex-wrap gap-3">
                <div class="relative max-w-xs flex-1">
                    <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search description..."
                        class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <select
                    v-model="action"
                    class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option v-for="opt in actionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Date</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Action</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Description</th>
                            <th class="px-4 py-3 font-medium text-slate-500">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in activity.data"
                            :key="row.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                            :class="row.subject_type?.endsWith('Contact') ? 'cursor-pointer' : ''"
                            @click="goToSubject(row)"
                        >
                            <td class="px-4 py-3 text-slate-500">{{ row.formatted_created_at ?? row.created_at }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="actionBadgeClass[row.action] ?? 'bg-slate-100 text-slate-600'"
                                >
                                    {{ row.action.replace('_', ' ') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-ink-900">{{ row.description }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ row.user?.name ?? '—' }}</td>
                        </tr>
                        <tr v-if="activity.data.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-slate-400">No activity recorded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="activity.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="link in activity.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    class="rounded-lg px-3 py-1.5 font-sans text-sm"
                    :class="link.active ? 'bg-ink-900 text-white' : 'text-slate-500 hover:bg-slate-100'"
                    preserve-state
                >
                    <span v-html="link.label" />
                </Link>
            </div>
        </div>
    </DashboardLayout>
</template>