<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import ContactsTabs from '@/components/ContactsTabs.vue';
import { Plus, Search } from 'lucide-vue-next';

interface ContactRow {
    id: number;
    type: 'customer' | 'supplier' | 'employee';
    name: string;
    email: string | null;
    phone: string | null;
    city: string | null;
    is_active: boolean;
}

const props = defineProps<{
    contacts: {
        data: ContactRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { type?: string; search?: string };
    typeCounts: Record<string, number>;
}>();

const search = ref(props.filters.search ?? '');
let debounceTimer: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('contacts.index'),
            { search: search.value || undefined, type: props.filters.type || undefined },
            { preserveState: true, replace: true }
        );
    }, 300);
});

const typeBadgeClass: Record<string, string> = {
    customer: 'bg-brand-500/15 text-ink-800',
    supplier: 'bg-blue-100 text-blue-700',
    employee: 'bg-amber-100 text-amber-700',
};
</script>

<template>
    <Head title="Contacts" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">Contacts</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">
                        Customers, suppliers, and employees.
                    </p>
                </div>

                <Link
                    :href="route('contacts.create')"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> New contact
                </Link>
            </div>

            <ContactsTabs :active-type="filters.type" />

            <div class="relative max-w-sm">
                <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search name, phone, or email..."
                    class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Name</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Type</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Phone</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Email</th>
                            <th class="px-4 py-3 font-medium text-slate-500">City</th>
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
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="typeBadgeClass[contact.type]"
                                >
                                    {{ contact.type }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ contact.phone ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ contact.email ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ contact.city ?? '—' }}</td>
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
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">No contacts found.</td>
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
        </div>
    </DashboardLayout>
</template>