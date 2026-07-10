<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import ProductsTabs from '@/components/ProductsTabs.vue';
import { Plus, Pencil, Trash2, X } from 'lucide-vue-next';

interface TaxRow {
    id: number;
    name: string;
    rate: string;
    type: 'percentage' | 'fixed';
    is_active: boolean;
    is_default: boolean;
}

defineProps<{
    taxes: TaxRow[];
}>();

const editingId = ref<number | null>(null);
const isCreating = ref(false);

const form = useForm({
    name: '',
    rate: '',
    type: 'percentage' as 'percentage' | 'fixed',
    is_active: true as boolean,
});

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    form.reset();
    form.type = 'percentage';
    form.is_active = true;
}

function startEdit(tax: TaxRow) {
    editingId.value = tax.id;
    isCreating.value = false;
    form.name = tax.name;
    form.rate = tax.rate;
    form.type = tax.type;
    form.is_active = tax.is_active;
}

function cancel() {
    isCreating.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submitCreate() {
    form.post(route('taxes.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreating.value = false;
            form.reset();
        },
    });
}

function submitEdit(tax: TaxRow) {
    form.put(route('products.manage.taxes.update', { tax: tax.id }), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            form.reset();
        },
    });
}

function destroy(tax: TaxRow) {
    if (!confirm(`Delete "${tax.name}"?`)) return;
    router.delete(route('products.manage.taxes.destroy', { tax: tax.id }), {
        preserveScroll: true,
    });
}

function setDefault(tax: TaxRow) {
    if (tax.is_default) return;
    router.post(route('products.manage.taxes.set-default', { tax: tax.id }), {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Taxes" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Products</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Manage your catalog, variants and barcodes.
            </p>

            <ProductsTabs />

            <div class="flex items-center justify-between">
                <h2 class="font-sans text-sm font-semibold text-ink-900">Taxes</h2>
                <button
                    v-if="!isCreating"
                    @click="startCreate"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> New tax
                </button>
            </div>

            <div v-if="isCreating" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                <div class="grid grid-cols-4 gap-3">
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Standard"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Rate</label>
                        <input
                            v-model="form.rate"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.rate" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.rate }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Type</label>
                        <select
                            v-model="form.type"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                v-model="form.is_active"
                                class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                            />
                            <span class="font-sans text-sm text-ink-900">Active</span>
                        </label>
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
                            <th class="px-4 py-3 font-medium text-slate-500">Default</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Name</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Rate</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Type</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tax in taxes" :key="tax.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <template v-if="editingId === tax.id">
                                <td class="px-4 py-2" colspan="6">
                                    <div class="flex items-center gap-3">
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="w-40 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <input
                                            v-model="form.rate"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="w-24 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <select
                                            v-model="form.type"
                                            class="w-32 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        >
                                            <option value="percentage">Percentage</option>
                                            <option value="fixed">Fixed</option>
                                        </select>
                                        <label class="flex items-center gap-1.5">
                                            <input
                                                type="checkbox"
                                                v-model="form.is_active"
                                                class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                            />
                                            <span class="font-sans text-xs text-ink-900">Active</span>
                                        </label>
                                        <button
                                            @click="submitEdit(tax)"
                                            :disabled="form.processing"
                                            class="rounded-lg bg-brand-500 px-3 py-1.5 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400"
                                        >
                                            Save
                                        </button>
                                        <button @click="cancel" class="text-slate-400 hover:text-ink-800">
                                            <X :size="16" />
                                        </button>
                                    </div>
                                    <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-3">
                                    <input
                                        type="radio"
                                        name="default_tax"
                                        :checked="tax.is_default"
                                        @change="setDefault(tax)"
                                        class="h-4 w-4 border-slate-300 text-brand-500 focus:ring-brand-500"
                                    />
                                </td>
                                <td class="px-4 py-3 font-medium text-ink-900">{{ tax.name }}</td>
                                <td class="px-4 py-3 text-slate-500">
                                    {{ tax.rate }}{{ tax.type === 'percentage' ? '%' : '' }}
                                </td>
                                <td class="px-4 py-3 text-slate-500 capitalize">{{ tax.type }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="tax.is_active"
                                        class="inline-flex rounded-full bg-brand-500/15 px-2 py-0.5 text-xs font-medium text-ink-800"
                                    >
                                        Active
                                    </span>
                                    <span v-else class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                                        Inactive
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <button @click="startEdit(tax)" class="text-slate-400 hover:text-ink-800" title="Edit">
                                            <Pencil :size="16" />
                                        </button>
                                        <button
                                            @click="destroy(tax)"
                                            :disabled="tax.is_default"
                                            class="text-slate-400 hover:text-danger-500 disabled:opacity-30"
                                            :title="tax.is_default ? 'Cannot delete the default tax' : 'Delete'"
                                        >
                                            <Trash2 :size="16" />
                                        </button>
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="taxes.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">No taxes yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>