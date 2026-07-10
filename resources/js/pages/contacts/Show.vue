<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, Pencil, Mail, Phone, MapPin, Hash, Trash2 } from 'lucide-vue-next';

interface ContactData {
    id: number;
    type: 'customer' | 'supplier' | 'employee';
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    tax_number: string | null;
    is_active: boolean;
}

interface RecentDocument {
    id: number;
    type: 'pos_order' | 'pos_refund';
    number: string;
    status: string;
    total: string;
    created_at: string;
    formatted_created_at: string;
}

const props = defineProps<{
    contact: ContactData;
    totalSpent: number;
    orderCount: number;
    recentDocuments: RecentDocument[];
}>();

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const typeBadgeClass: Record<string, string> = {
    customer: 'bg-brand-500/15 text-ink-800',
    supplier: 'bg-blue-100 text-blue-700',
    employee: 'bg-amber-100 text-amber-700',
};

const statusBadgeClass: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-600',
    confirmed: 'bg-amber-100 text-amber-700',
    paid: 'bg-brand-500/15 text-ink-800',
    cancelled: 'bg-danger-500/15 text-danger-500',
    refunded: 'bg-blue-100 text-blue-700',
};

const typeLabel: Record<string, string> = {
    pos_order: 'Order',
    pos_refund: 'Refund',
};

function destroy() {
    if (!confirm(`Delete "${props.contact.name}"? If they have order history, they'll be deactivated instead.`)) {
        return;
    }
    router.delete(route('contacts.destroy', { contact: props.contact.id }));
}
</script>

<template>
    <Head :title="contact.name" />

    <DashboardLayout>
        <div class="mx-auto max-w-3xl">
            <Link :href="route('contacts.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to Contacts
            </Link>

            <div class="mt-2 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="font-display text-2xl font-bold text-ink-900">{{ contact.name }}</h1>
                    <span
                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                        :class="typeBadgeClass[contact.type]"
                    >
                        {{ contact.type }}
                    </span>
                    <span
                        v-if="!contact.is_active"
                        class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500"
                    >
                        Inactive
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('contacts.edit', { contact: contact.id })"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                    >
                        <Pencil :size="16" /> Edit
                    </Link>
                    <button
                        @click="destroy"
                        class="flex items-center gap-1.5 rounded-lg border border-danger-500 px-4 py-2 font-sans text-sm font-semibold text-danger-500 hover:bg-danger-500/5"
                    >
                        <Trash2 :size="16" /> Delete
                    </button>
                </div>
            </div>

            <!-- Contact details -->
            <div class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                <div class="grid grid-cols-2 gap-3 font-sans text-sm">
                    <p v-if="contact.phone" class="flex items-center gap-1.5 text-slate-600">
                        <Phone :size="14" class="text-slate-400" /> {{ contact.phone }}
                    </p>
                    <p v-if="contact.email" class="flex items-center gap-1.5 text-slate-600">
                        <Mail :size="14" class="text-slate-400" /> {{ contact.email }}
                    </p>
                    <p v-if="contact.address || contact.city" class="flex items-center gap-1.5 text-slate-600">
                        <MapPin :size="14" class="text-slate-400" />
                        {{ [contact.address, contact.city].filter(Boolean).join(', ') }}
                    </p>
                    <p v-if="contact.tax_number" class="flex items-center gap-1.5 text-slate-600">
                        <Hash :size="14" class="text-slate-400" /> {{ contact.tax_number }}
                    </p>
                </div>
            </div>

            <!-- Stats (customers only really matter here, but shown for any type with order history) -->
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="font-sans text-xs font-medium uppercase tracking-wide text-slate-400">Total spent</p>
                    <p class="mt-1 font-display text-2xl font-bold text-ink-900">Rs {{ money(totalSpent) }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="font-sans text-xs font-medium uppercase tracking-wide text-slate-400">Orders placed</p>
                    <p class="mt-1 font-display text-2xl font-bold text-ink-900">{{ orderCount }}</p>
                </div>
            </div>

            <!-- Recent order/refund history -->
            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 class="font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">Recent activity</h2>
                </div>
                <table class="w-full text-left font-sans text-sm">
                    <tbody>
                        <tr
                            v-for="doc in recentDocuments"
                            :key="doc.id"
                            class="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                            @click="router.visit(route('pos.history.show', { document: doc.id }))"
                        >
                            <td class="px-4 py-3 font-medium text-ink-900">{{ doc.number }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ typeLabel[doc.type] }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="statusBadgeClass[doc.status]"
                                >
                                    {{ doc.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ doc.formatted_created_at }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-ink-900">Rs {{ money(doc.total) }}</td>
                        </tr>
                        <tr v-if="recentDocuments.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">No orders yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>