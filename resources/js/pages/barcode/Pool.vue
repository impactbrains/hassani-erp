<script setup lang="ts">
import { ref, watch, nextTick, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import BarcodeTabs from '@/components/BarcodeTabs.vue';
import { Pencil, Trash2, Check, X, Printer, ListChecks } from 'lucide-vue-next';

interface PoolCode {
    id: number;
    code: string;
    label: string | null;
    status: 'unclaimed' | 'claimed';
    created_at: string;
}

const props = defineProps<{
    codes: {
        data: PoolCode[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { status?: string };
}>();

const status = ref(props.filters.status ?? '');

watch(status, () => {
    router.get(
        route('barcode.pool'),
        { status: status.value || undefined },
        { preserveState: true, replace: true }
    );
});

const localCodes = ref<PoolCode[]>([...props.codes.data]);
watch(() => props.codes.data, (fresh) => { localCodes.value = [...fresh]; });

function markUsed(code: PoolCode) {
    router.post(route('barcode.pool.mark-used', { generatedBarcode: code.id }), {}, { preserveScroll: true });
}

function markUnused(code: PoolCode) {
    router.post(route('barcode.pool.mark-unused', { generatedBarcode: code.id }), {}, { preserveScroll: true });
}

function destroyCode(code: PoolCode) {
    if (!confirm(`Delete barcode ${code.code}? This can't be undone.`)) return;

    router.delete(route('barcode.pool.destroy', { generatedBarcode: code.id }), {
        preserveScroll: true,
        onSuccess: () => {
            localCodes.value = localCodes.value.filter((c) => c.id !== code.id);
        },
    });
}

// --- Inline label editing ---
const editingId = ref<number | null>(null);
const editValue = ref('');
const isSaving = ref(false);

async function startEdit(code: PoolCode) {
    editingId.value = code.id;
    editValue.value = code.label ?? '';
    await nextTick();
    document.getElementById(`label-input-${code.id}`)?.focus();
}

function cancelEdit() {
    editingId.value = null;
    editValue.value = '';
}

async function saveEdit(code: PoolCode) {
    isSaving.value = true;
    try {
        const response = await fetch(route('barcode.pool.update', { generatedBarcode: code.id }), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            body: JSON.stringify({ label: editValue.value.trim() || null }),
        });

        if (response.ok) {
            const data = await response.json();
            const row = localCodes.value.find((c) => c.id === code.id);
            if (row) row.label = data.label;
            editingId.value = null;
        }
    } finally {
        isSaving.value = false;
    }
}

// --- Selection mode ---
const isSelecting = ref(false);
const selectedIds = ref<Set<number>>(new Set());

const allSelected = computed({
    get: () => localCodes.value.length > 0 && selectedIds.value.size === localCodes.value.length,
    set: (value: boolean) => {
        selectedIds.value = value ? new Set(localCodes.value.map((c) => c.id)) : new Set();
    },
});

function toggleSelecting() {
    isSelecting.value = !isSelecting.value;
    if (!isSelecting.value) selectedIds.value = new Set();
}

function toggleRow(id: number) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selectedIds.value = next;
}

const bulkColumns = ref(3);

function bulkPrint() {
    router.post(route('barcode.pool.bulk-print'), {
        ids: Array.from(selectedIds.value),
        columns: bulkColumns.value,
    });
}

function bulkDelete() {
    if (!confirm(`Delete ${selectedIds.value.size} selected barcode(s)? Claimed ones will be skipped.`)) return;

    router.delete(route('barcode.pool.bulk-destroy'), {
        data: { ids: Array.from(selectedIds.value) },
        preserveScroll: true,
        onSuccess: () => {
            localCodes.value = localCodes.value.filter((c) => !selectedIds.value.has(c.id));
            selectedIds.value = new Set();
        },
    });
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<template>
    <Head title="Barcode Pool" />

    <DashboardLayout>
        <div class="mx-auto max-w-4xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">Barcodes</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">
                        Every generated barcode, and whether it's been used yet.
                    </p>
                </div>

                <button
                    @click="toggleSelecting"
                    class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-sans text-sm font-medium text-ink-900 hover:bg-slate-50"
                    :class="{ 'border-brand-500 bg-brand-500/10': isSelecting }"
                >
                    <ListChecks :size="15" /> {{ isSelecting ? 'Cancel' : 'Select' }}
                </button>
            </div>

            <BarcodeTabs />

            <div class="mt-6 flex items-center justify-between">
                <select
                    v-model="status"
                    class="rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                >
                    <option value="">All</option>
                    <option value="unclaimed">Unclaimed</option>
                    <option value="claimed">Claimed / used</option>
                </select>

                <!-- Bulk action bar -->
                <div
                    v-if="isSelecting && selectedIds.size > 0"
                    class="flex items-center gap-3 rounded-lg bg-ink-900 px-3 py-2"
                >
                    <span class="font-sans text-sm text-white">{{ selectedIds.size }} selected</span>

                    <select
                        v-model.number="bulkColumns"
                        class="rounded-lg border-0 bg-white/10 py-1 font-sans text-xs text-white focus:ring-brand-500"
                        title="Columns for print sheet"
                    >
                        <option v-for="n in 6" :key="n" :value="n" class="text-ink-900">{{ n }} col</option>
                    </select>

                    <button
                        @click="bulkPrint"
                        class="flex items-center gap-1 rounded-lg bg-brand-500 px-2.5 py-1 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400"
                    >
                        <Printer :size="13" /> Print
                    </button>
                    <button
                        @click="bulkDelete"
                        class="flex items-center gap-1 rounded-lg bg-danger-500 px-2.5 py-1 font-sans text-xs font-semibold text-white hover:bg-danger-600"
                    >
                        <Trash2 :size="13" /> Delete
                    </button>
                </div>
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th v-if="isSelecting" class="w-10 px-4 py-3">
                                <input
                                    type="checkbox"
                                    v-model="allSelected"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                />
                            </th>
                            <th class="px-4 py-3 font-medium text-slate-500">Barcode</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Label</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Generated</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="code in localCodes"
                            :key="code.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                            :class="{ 'bg-brand-500/5': selectedIds.has(code.id) }"
                        >
                            <td v-if="isSelecting" class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.has(code.id)"
                                    @change="toggleRow(code.id)"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                />
                            </td>

                            <td class="px-4 py-3 font-mono text-ink-900">{{ code.code }}</td>

                            <td class="px-4 py-3">
                                <div v-if="editingId === code.id" class="flex items-center gap-1.5">
                                    <input
                                        :id="`label-input-${code.id}`"
                                        v-model="editValue"
                                        type="text"
                                        placeholder="No label"
                                        class="w-full rounded-lg border-slate-200 py-1 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        @keyup.enter="saveEdit(code)"
                                        @keyup.escape="cancelEdit"
                                    />
                                    <button @click="saveEdit(code)" :disabled="isSaving" class="shrink-0 text-emerald-600 hover:text-emerald-700" title="Save">
                                        <Check :size="16" />
                                    </button>
                                    <button @click="cancelEdit" class="shrink-0 text-slate-400 hover:text-danger-500" title="Cancel">
                                        <X :size="16" />
                                    </button>
                                </div>
                                <button
                                    v-else
                                    @click="startEdit(code)"
                                    class="group flex items-center gap-1.5 text-left text-slate-500 hover:text-ink-900"
                                >
                                    <span>{{ code.label ?? '—' }}</span>
                                    <Pencil :size="12" class="opacity-0 group-hover:opacity-100" />
                                </button>
                            </td>

                            <td class="px-4 py-3 text-slate-500">{{ formatDate(code.created_at) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="code.status === 'claimed'"
                                    class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                >
                                    Used
                                </span>
                                <span v-else class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                                    Unclaimed
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <button
                                        v-if="code.status === 'unclaimed'"
                                        @click="markUsed(code)"
                                        class="font-sans text-xs font-medium text-ink-800 hover:underline"
                                    >
                                        Mark as used
                                    </button>
                                    <button v-else @click="markUnused(code)" class="font-sans text-xs font-medium text-slate-400 hover:underline">
                                        Undo
                                    </button>

                                    <button
                                        v-if="code.status === 'unclaimed'"
                                        @click="destroyCode(code)"
                                        class="text-slate-400 hover:text-danger-500"
                                        title="Delete"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="localCodes.length === 0">
                            <td :colspan="isSelecting ? 6 : 5" class="px-4 py-8 text-center text-slate-400">
                                No barcodes generated yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="codes.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="link in codes.links"
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