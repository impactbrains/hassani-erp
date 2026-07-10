<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import ProductsTabs from '@/components/ProductsTabs.vue';
import { Plus, Pencil, Trash2, X } from 'lucide-vue-next';

interface UnitRow {
    id: number;
    name: string;
    short_code: string;
}

defineProps<{
    units: UnitRow[];
}>();

const editingId = ref<number | null>(null);
const isCreating = ref(false);

const form = useForm({
    name: '',
    short_code: '',
});

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    form.reset();
}

function startEdit(unit: UnitRow) {
    editingId.value = unit.id;
    isCreating.value = false;
    form.name = unit.name;
    form.short_code = unit.short_code;
}

function cancel() {
    isCreating.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submitCreate() {
    form.post(route('units.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreating.value = false;
            form.reset();
        },
    });
}

function submitEdit(unit: UnitRow) {
    form.put(route('products.manage.units.update', { unit: unit.id }), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            form.reset();
        },
    });
}

function destroy(unit: UnitRow) {
    if (!confirm(`Delete "${unit.name}"?`)) return;
    router.delete(route('products.manage.units.destroy', { unit: unit.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Units" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Products</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Manage your catalog, variants and barcodes.
            </p>

            <ProductsTabs />

            <div class="flex items-center justify-between">
                <h2 class="font-sans text-sm font-semibold text-ink-900">Units</h2>
                <button
                    v-if="!isCreating"
                    @click="startCreate"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> New unit
                </button>
            </div>

            <div v-if="isCreating" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Kilogram"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Short code</label>
                        <input
                            v-model="form.short_code"
                            type="text"
                            placeholder="e.g. kg"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.short_code" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.short_code }}</p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <button
                        @click="submitCreate"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-4 py-1.5 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50"
                    >
                        Save
                    </button>
                    <button @click="cancel" class="rounded-lg px-4 py-1.5 font-sans text-sm text-slate-500 hover:bg-slate-100">
                        Cancel
                    </button>
                </div>
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Name</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Short code</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="unit in units" :key="unit.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <template v-if="editingId === unit.id">
                                <td class="px-4 py-2" colspan="3">
                                    <div class="flex items-center gap-3">
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="w-48 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <input
                                            v-model="form.short_code"
                                            type="text"
                                            class="w-32 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <button
                                            @click="submitEdit(unit)"
                                            :disabled="form.processing"
                                            class="rounded-lg bg-brand-500 px-3 py-1.5 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400"
                                        >
                                            Save
                                        </button>
                                        <button @click="cancel" class="text-slate-400 hover:text-ink-800">
                                            <X :size="16" />
                                        </button>
                                    </div>
                                    <p v-if="form.errors.short_code" class="mt-1 font-sans text-xs text-danger-500">
                                        {{ form.errors.short_code }}
                                    </p>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-3 font-medium text-ink-900">{{ unit.name }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ unit.short_code }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <button @click="startEdit(unit)" class="text-slate-400 hover:text-ink-800" title="Edit">
                                            <Pencil :size="16" />
                                        </button>
                                        <button @click="destroy(unit)" class="text-slate-400 hover:text-danger-500" title="Delete">
                                            <Trash2 :size="16" />
                                        </button>
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="units.length === 0">
                            <td colspan="3" class="px-4 py-8 text-center text-slate-400">No units yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>