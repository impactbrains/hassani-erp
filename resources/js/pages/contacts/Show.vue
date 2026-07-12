<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft, Pencil, Mail, Phone, MapPin, Hash, Trash2, Plus, X, History, UserCog } from 'lucide-vue-next';

interface ContactData {
    id: number;
    type: 'customer' | 'supplier' | 'employee' | 'candidate';
    previous_type: string | null;
    track: 'employee' | 'supplier' | null;
    stage: 'applied' | 'reviewing' | 'interview' | 'hired' | 'rejected' | null;
    application_notes: string | null;
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

interface ContactRecordData {
    id: number;
    description: string;
    amount: string | null;
    created_at: string;
    formatted_created_at?: string;
    user: { id: number; name: string } | null;
}

const props = defineProps<{
    contact: ContactData;
    totalSpent: number;
    orderCount: number;
    recentDocuments: RecentDocument[];
    records: ContactRecordData[];
}>();

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const typeBadgeClass: Record<string, string> = {
    customer: 'bg-brand-500/15 text-ink-800',
    supplier: 'bg-blue-100 text-blue-700',
    employee: 'bg-amber-100 text-amber-700',
    candidate: 'bg-purple-100 text-purple-700',
};

const stageBadgeClass: Record<string, string> = {
    applied: 'bg-slate-100 text-slate-600',
    reviewing: 'bg-amber-100 text-amber-700',
    interview: 'bg-blue-100 text-blue-700',
    hired: 'bg-brand-500/15 text-ink-800',
    rejected: 'bg-danger-500/15 text-danger-500',
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

// ---- Candidate pipeline ----
const showApplyPanel = ref(false);
const applyTrack = ref<'employee' | 'supplier'>('employee');
const applyNotes = ref('');

function submitApplyAsCandidate() {
    router.post(
        route('contacts.apply-as-candidate', { contact: props.contact.id }),
        { track: applyTrack.value, notes: applyNotes.value || undefined },
        { preserveScroll: true, onSuccess: () => (showApplyPanel.value = false) }
    );
}

function advanceStage(stage: 'applied' | 'reviewing' | 'interview') {
    router.post(
        route('contacts.advance-stage', { contact: props.contact.id }),
        { stage },
        { preserveScroll: true }
    );
}

function hireCandidate() {
    if (!confirm(`Hire "${props.contact.name}" as ${props.contact.track}?`)) return;
    router.post(route('contacts.hire', { contact: props.contact.id }), {}, { preserveScroll: true });
}

function rejectCandidate() {
    if (!confirm(`Reject "${props.contact.name}"'s application?`)) return;
    router.post(route('contacts.reject', { contact: props.contact.id }), {}, { preserveScroll: true });
}

// ---- Contact records (free-text ledger entries) ----
const isAddingRecord = ref(false);
const editingRecordId = ref<number | null>(null);

const recordForm = useForm({
    description: '',
    amount: '',
});

function startAddRecord() {
    isAddingRecord.value = true;
    editingRecordId.value = null;
    recordForm.reset();
}

function startEditRecord(record: ContactRecordData) {
    editingRecordId.value = record.id;
    isAddingRecord.value = false;
    recordForm.description = record.description;
    recordForm.amount = record.amount ?? '';
}

function cancelRecordForm() {
    isAddingRecord.value = false;
    editingRecordId.value = null;
    recordForm.reset();
    recordForm.clearErrors();
}

function submitNewRecord() {
    recordForm.post(route('contacts.records.store', { contact: props.contact.id }), {
        preserveScroll: true,
        onSuccess: () => {
            isAddingRecord.value = false;
            recordForm.reset();
        },
    });
}

function submitEditRecord(record: ContactRecordData) {
    recordForm.put(route('contacts.records.update', { contact: props.contact.id, record: record.id }), {
        preserveScroll: true,
        onSuccess: () => {
            editingRecordId.value = null;
            recordForm.reset();
        },
    });
}

function destroyRecord(record: ContactRecordData) {
    if (!confirm('Delete this record?')) return;
    router.delete(route('contacts.records.destroy', { contact: props.contact.id, record: record.id }), {
        preserveScroll: true,
    });
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
                        v-if="contact.type === 'candidate' && contact.stage"
                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                        :class="stageBadgeClass[contact.stage]"
                    >
                        {{ contact.stage }} · {{ contact.track }}
                    </span>
                    <span
                        v-if="!contact.is_active"
                        class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500"
                    >
                        Inactive
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <template v-if="contact.type === 'candidate'">
                        <button
                            v-if="contact.stage === 'applied'"
                            @click="advanceStage('reviewing')"
                            class="rounded-lg border border-slate-200 px-3 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                        >
                            Move to Reviewing
                        </button>
                        <button
                            v-if="contact.stage === 'reviewing'"
                            @click="advanceStage('interview')"
                            class="rounded-lg border border-slate-200 px-3 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                        >
                            Move to Interview
                        </button>
                        <button
                            @click="hireCandidate"
                            class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400"
                        >
                            Hire
                        </button>
                        <button
                            @click="rejectCandidate"
                            class="rounded-lg border border-danger-500 px-4 py-2 font-sans text-sm font-semibold text-danger-500 hover:bg-danger-500/5"
                        >
                            Reject
                        </button>
                    </template>
                    <button
                        v-else-if="['customer', 'supplier'].includes(contact.type)"
                        @click="showApplyPanel = !showApplyPanel"
                        class="rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                    >
                        Apply as candidate
                    </button>
                    <Link
                        v-if="contact.type === 'employee'"
                        :href="route('contacts.employee.show', { contact: contact.id })"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                    >
                        <UserCog :size="16" /> Employee Profile
                    </Link>
                    <Link
                        :href="route('contacts.history')"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                    >
                        <History :size="16" /> History
                    </Link>
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

            <!-- Apply-as-candidate panel -->
            <div v-if="showApplyPanel" class="mt-3 rounded-lg border border-slate-200 bg-white p-4">
                <div class="flex items-end gap-3">
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Track</label>
                        <select
                            v-model="applyTrack"
                            class="rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option value="employee">Employee (job applicant)</option>
                            <option value="supplier">Supplier (partnership)</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Notes (optional)</label>
                        <input
                            v-model="applyNotes"
                            type="text"
                            placeholder="Why are they applying?"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>
                    <button
                        @click="submitApplyAsCandidate"
                        class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400"
                    >
                        Confirm
                    </button>
                    <button
                        @click="showApplyPanel = false"
                        class="rounded-lg px-3 py-2 font-sans text-sm text-slate-500 hover:bg-slate-100"
                    >
                        Cancel
                    </button>
                </div>
                <p class="mt-2 font-sans text-xs text-slate-400">
                    Their current type ({{ contact.type }}) will be restored automatically if this application is rejected.
                </p>
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
                <p v-if="contact.type === 'candidate' && contact.application_notes" class="mt-3 border-t border-slate-100 pt-3 font-sans text-sm text-slate-600">
                    <span class="font-medium text-ink-900">Application notes:</span> {{ contact.application_notes }}
                </p>
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

            <!-- Records — free-text ledger entries ("Taken 5 rupees from the counter") -->
            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 class="font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">Records</h2>
                    <button
                        v-if="!isAddingRecord"
                        @click="startAddRecord"
                        class="flex items-center gap-1 font-sans text-xs font-semibold text-ink-800 hover:underline"
                    >
                        <Plus :size="12" /> Add record
                    </button>
                </div>

                <div v-if="isAddingRecord" class="border-b border-slate-100 p-4">
                    <div class="flex gap-2">
                        <input
                            v-model="recordForm.description"
                            type="text"
                            placeholder="e.g. Taken 5 rupees from the counter"
                            class="flex-1 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <input
                            v-model="recordForm.amount"
                            type="number"
                            step="0.01"
                            placeholder="Amount (optional)"
                            class="w-40 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <button
                            @click="submitNewRecord"
                            :disabled="recordForm.processing"
                            class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50"
                        >
                            Save
                        </button>
                        <button @click="cancelRecordForm" class="rounded-lg px-3 py-2 font-sans text-sm text-slate-500 hover:bg-slate-100">
                            Cancel
                        </button>
                    </div>
                    <p v-if="recordForm.errors.description" class="mt-1 font-sans text-xs text-danger-500">
                        {{ recordForm.errors.description }}
                    </p>
                </div>

                <div v-if="records.length === 0 && !isAddingRecord" class="px-4 py-8 text-center font-sans text-sm text-slate-400">
                    No records yet.
                </div>

                <div
                    v-for="record in records"
                    :key="record.id"
                    class="border-b border-slate-100 px-4 py-3 last:border-0"
                >
                    <template v-if="editingRecordId === record.id">
                        <div class="flex gap-2">
                            <input
                                v-model="recordForm.description"
                                type="text"
                                class="flex-1 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <input
                                v-model="recordForm.amount"
                                type="number"
                                step="0.01"
                                placeholder="Amount (optional)"
                                class="w-40 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <button
                                @click="submitEditRecord(record)"
                                :disabled="recordForm.processing"
                                class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400"
                            >
                                Save
                            </button>
                            <button @click="cancelRecordForm" class="text-slate-400 hover:text-ink-800">
                                <X :size="16" />
                            </button>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-sans text-sm text-ink-900">{{ record.description }}</p>
                                <p class="mt-0.5 font-sans text-xs text-slate-400">
                                    {{ record.formatted_created_at ?? record.created_at }}
                                    <span v-if="record.user"> · {{ record.user.name }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span v-if="record.amount !== null" class="font-sans text-sm font-semibold text-ink-900">
                                    Rs {{ money(record.amount) }}
                                </span>
                                <button @click="startEditRecord(record)" class="text-slate-400 hover:text-ink-800">
                                    <Pencil :size="14" />
                                </button>
                                <button @click="destroyRecord(record)" class="text-slate-400 hover:text-danger-500">
                                    <Trash2 :size="14" />
                                </button>
                            </div>
                        </div>
                    </template>
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