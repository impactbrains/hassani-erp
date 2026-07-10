<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import ProductsTabs from '@/components/ProductsTabs.vue';
import { Plus, Pencil, Trash2, X } from 'lucide-vue-next';

interface CategoryRow {
    id: number;
    name: string;
    parent_id: number | null;
    parent: { id: number; name: string } | null;
}

// eslint-disable-next-line @typescript-eslint/no-unused-vars
const props = defineProps<{
    categories: CategoryRow[];
}>();

const editingId = ref<number | null>(null);
const isCreating = ref(false);

const form = useForm({
    name: '',
    parent_id: null as number | null,
});

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    form.reset();
}

function startEdit(category: CategoryRow) {
    editingId.value = category.id;
    isCreating.value = false;
    form.name = category.name;
    form.parent_id = category.parent_id;
}

function cancel() {
    isCreating.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submitCreate() {
    form.post(route('categories.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreating.value = false;
            form.reset();
        },
    });
}

function submitEdit(category: CategoryRow) {
    form.put(route('products.manage.categories.update', { category: category.id }), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            form.reset();
        },
    });
}

function destroy(category: CategoryRow) {
    if (!confirm(`Delete "${category.name}"?`)) return;
    router.delete(route('products.manage.categories.destroy', { category: category.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Categories" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Products</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Manage your catalog, variants and barcodes.
            </p>

            <ProductsTabs />

            <div class="flex items-center justify-between">
                <h2 class="font-sans text-sm font-semibold text-ink-900">Categories</h2>
                <button
                    v-if="!isCreating"
                    @click="startCreate"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> New category
                </button>
            </div>

            <div v-if="isCreating" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Parent category</label>
                        <select
                            v-model="form.parent_id"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option :value="null">None</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
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
                            <th class="px-4 py-3 font-medium text-slate-500">Parent</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="category in categories" :key="category.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <template v-if="editingId === category.id">
                                <td class="px-4 py-2" colspan="3">
                                    <div class="flex items-center gap-3">
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="w-48 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <select
                                            v-model="form.parent_id"
                                            class="w-48 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        >
                                            <option :value="null">None</option>
                                            <option
                                                v-for="cat in categories.filter((c) => c.id !== category.id)"
                                                :key="cat.id"
                                                :value="cat.id"
                                            >
                                                {{ cat.name }}
                                            </option>
                                        </select>
                                        <button
                                            @click="submitEdit(category)"
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
                                <td class="px-4 py-3 font-medium text-ink-900">{{ category.name }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ category.parent?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <button @click="startEdit(category)" class="text-slate-400 hover:text-ink-800" title="Edit">
                                            <Pencil :size="16" />
                                        </button>
                                        <button @click="destroy(category)" class="text-slate-400 hover:text-danger-500" title="Delete">
                                            <Trash2 :size="16" />
                                        </button>
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="categories.length === 0">
                            <td colspan="3" class="px-4 py-8 text-center text-slate-400">No categories yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>