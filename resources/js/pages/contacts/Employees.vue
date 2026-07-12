<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import HrSubTabs from '@/components/HrSubTabs.vue';
import { Search, Plus, Users, UserCheck } from 'lucide-vue-next';

interface ContactRow {
    id: number;
    type: 'customer' | 'supplier' | 'employee' | 'candidate';
    track: 'employee' | 'supplier' | null;
    stage: 'applied' | 'reviewing' | 'interview' | 'hired' | 'rejected' | null;
    name: string;
    email: string | null;
    phone: string | null;
    is_active: boolean;
}

const props = defineProps<{
    contacts: {
        data: ContactRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    view: string;
    filters: { search?: string };
    counts: { employees: number; candidates: number };
}>();

const search = ref(props.filters.search ?? '');
let debounceTimer: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('contacts.employees'),
            { search: search.value || undefined, view: props.view !== 'overview' ? props.view : undefined },
            { preserveState: true, replace: true }
        );
    }, 300);
});

const stageBadgeClass: Record<string, string> = {
    applied: 'bg-slate-100 text-slate-600',
    reviewing: 'bg-amber-100 text-amber-700',
    interview: 'bg-blue-100 text-blue-700',
    hired: 'bg-brand-500/15 text-ink-800',
    rejected: 'bg-danger-500/15 text-danger-500',
};
</script>

<template>
    <Head title="Employees" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">Employees</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">Staff records and job applicants.</p>
                </div>
                <Link
                    :href="route('contacts.create')"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400"
                >
                    <Plus :size="16" /> New contact
                </Link>
            </div>

            <HrSubTabs
                route-name="contacts.employees"
                :active-view="view"
                list-label="Employees"
                :counts="{ list: counts.employees, candidates: counts.candidates }"
            />

            <!-- Overview -->
            <div v-if="view === 'overview'" class="grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <div class="flex items-center gap-2 text-slate-400">
                        <Users :size="16" />
                        <span class="font-sans text-xs font-medium uppercase tracking-wide">Employees</span>
                    </div>
                    <p class="mt-2 font-display text-3xl font-bold text-ink-900">{{ counts.employees }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <div class="flex items-center gap-2 text-slate-400">
                        <UserCheck :size="16" />
                        <span class="font-sans text-xs font-medium uppercase tracking-wide">Open candidates</span>
                    </div>
                    <p class="mt-2 font-display text-3xl font-bold text-ink-900">{{ counts.candidates }}</p>
                </div>
            </div>

            <!-- Employees / Candidates list -->
            <template v-else>
                <div class="relative max-w-sm">
                    <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by name..."
                        class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table class="w-full text-left font-sans text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 font-medium text-slate-500">Name</th>
                                <th class="px-4 py-3 font-medium text-slate-500">Phone</th>
                                <th class="px-4 py-3 font-medium text-slate-500">Email</th>
                                <th v-if="view === 'candidates'" class="px-4 py-3 font-medium text-slate-500">Stage</th>
                                <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="contact in contacts.data"
                                :key="contact.id"
                                class="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                                @click="router.visit(route('contacts.show', { contact: contact.id }))"
                            >
                                <td class="px-4 py-3 font-medium text-ink-900">{{ contact.name }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ contact.phone ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ contact.email ?? '—' }}</td>
                                <td v-if="view === 'candidates'" class="px-4 py-3">
                                    <span
                                        v-if="contact.stage"
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                        :class="stageBadgeClass[contact.stage]"
                                    >
                                        {{ contact.stage }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="contact.is_active"
                                        class="inline-flex rounded-full bg-brand-500/15 px-2 py-0.5 text-xs font-medium text-ink-800"
                                    >
                                        Active
                                    </span>
                                    <span v-else class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                                        Inactive
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="contacts.data.length === 0">
                                <td :colspan="view === 'candidates' ? 5 : 4" class="px-4 py-8 text-center text-slate-400">
                                    {{ view === 'candidates' ? 'No candidates in the pipeline yet.' : 'No employees found.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="contacts.links.length > 3" class="mt-4 flex justify-center gap-1">
                    <Link
                        v-for="link in contacts.links"
                        :key="link.label"
                        :href="link.url ?? ''"
                        class="rounded-lg px-3 py-1.5 font-sans text-sm"
                        :class="link.active ? 'bg-ink-900 text-white' : 'text-slate-500 hover:bg-slate-100'"
                        preserve-state
                    >
                        <span v-html="link.label" />
                    </Link>
                </div>
            </template>
        </div>
    </DashboardLayout>
</template>