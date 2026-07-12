<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Clock } from 'lucide-vue-next';

interface ActivityEntry {
    id: number;
    app: string;
    action: string;
    description: string;
    user: string | null;
    created_at: string;
}

const props = defineProps<{
    contact: { id: number };
}>();

// Lazily fetched on mount rather than passed down from
// EmployeeController::show() — a long-tenured employee's combined
// activity (their own contact-record changes + everything they did as
// a user across POS/Products/Inventory/etc) could be a lot of rows,
// and there's no reason to make every visit to the Work tab pay for
// loading History's data too.
const activity = ref<ActivityEntry[]>([]);
const loading = ref(true);
const loadError = ref(false);

async function loadHistory() {
    loading.value = true;
    loadError.value = false;
    try {
        const response = await fetch(route('contacts.employee.history', { contact: props.contact.id }));
        if (!response.ok) throw new Error('Failed to load history');
        activity.value = await response.json();
    } catch {
        loadError.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(loadHistory);

const appLabel: Record<string, string> = {
    contacts: 'Contacts',
    pos: 'POS',
    inventory: 'Inventory',
    products: 'Products',
    barcode: 'Barcode',
    invoicing: 'Invoicing',
};
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="font-display text-base font-semibold text-ink-900">History</h2>
        <p class="mt-1 font-sans text-sm text-slate-500">
            Everything logged for this employee, across every app.
        </p>

        <div v-if="loading" class="mt-6 text-center font-sans text-sm text-slate-400">
            Loading...
        </div>

        <div v-else-if="loadError" class="mt-6 text-center font-sans text-sm text-danger-500">
            Could not load history. <button type="button" @click="loadHistory" class="underline">Try again</button>
        </div>

        <div v-else-if="activity.length === 0" class="mt-6 text-center font-sans text-sm text-slate-400">
            No activity recorded yet.
        </div>

        <div v-else class="mt-4 divide-y divide-slate-100">
            <div v-for="entry in activity" :key="entry.id" class="flex items-start gap-3 py-3">
                <Clock :size="16" class="mt-0.5 shrink-0 text-slate-300" />
                <div class="flex-1">
                    <p class="font-sans text-sm text-ink-900">{{ entry.description }}</p>
                    <p class="mt-0.5 font-sans text-xs text-slate-400">
                        {{ appLabel[entry.app] ?? entry.app }}
                        <span v-if="entry.user"> · {{ entry.user }}</span>
                        · {{ entry.created_at }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>