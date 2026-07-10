<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import InventoryTabs from '@/components/InventoryTabs.vue';
import { Plus, Pencil, Trash2, X } from 'lucide-vue-next';

interface LocationRow {
    id: number;
    name: string;
    type: 'store' | 'warehouse';
    address: string | null;
    is_default: boolean;
}

defineProps<{
    locations: LocationRow[];
}>();

const editingId = ref<number | null>(null);
const isCreating = ref(false);

const form = useForm({
    name: '',
    type: 'store' as 'store' | 'warehouse',
    address: '',
});

function startCreate() {
    isCreating.value = true;
    editingId.value = null;
    form.reset();
    form.type = 'store';
}

function startEdit(location: LocationRow) {
    editingId.value = location.id;
    isCreating.value = false;
    form.name = location.name;
    form.type = location.type;
    form.address = location.address ?? '';
}

function cancel() {
    isCreating.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submitCreate() {
    form.post(route('inventory.locations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreating.value = false;
            form.reset();
        },
    });
}

function submitEdit(location: LocationRow) {
    form.put(route('inventory.locations.update', { location: location.id }), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            form.reset();
        },
    });
}

function destroy(location: LocationRow) {
    if (!confirm(`Delete "${location.name}"?`)) return;
    router.delete(route('inventory.locations.destroy', { location: location.id }), {
        preserveScroll: true,
    });
}

function setDefault(location: LocationRow) {
    if (location.is_default) return;
    router.post(route('inventory.locations.set-default', { location: location.id }), {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Locations" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Inventory</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Stock levels across all locations.
            </p>

            <InventoryTabs />

            <div class="flex items-center justify-between">
                <h2 class="font-sans text-sm font-semibold text-ink-900">Locations</h2>
                <button
                    v-if="!isCreating"
                    @click="startCreate"
                    class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                >
                    <Plus :size="16" /> New location
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
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Type</label>
                        <select
                            v-model="form.type"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option value="store">Store</option>
                            <option value="warehouse">Warehouse</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Address</label>
                        <input
                            v-model="form.address"
                            type="text"
                            placeholder="Optional"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
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
                            <th class="px-4 py-3 font-medium text-slate-500">Type</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Address</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="location in locations" :key="location.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <template v-if="editingId === location.id">
                                <td class="px-4 py-2" colspan="5">
                                    <div class="flex items-center gap-3">
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="w-40 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <select
                                            v-model="form.type"
                                            class="w-32 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        >
                                            <option value="store">Store</option>
                                            <option value="warehouse">Warehouse</option>
                                        </select>
                                        <input
                                            v-model="form.address"
                                            type="text"
                                            placeholder="Address"
                                            class="w-56 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                                        />
                                        <button
                                            @click="submitEdit(location)"
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
                                        name="default_location"
                                        :checked="location.is_default"
                                        @change="setDefault(location)"
                                        class="h-4 w-4 border-slate-300 text-brand-500 focus:ring-brand-500"
                                    />
                                </td>
                                <td class="px-4 py-3 font-medium text-ink-900">{{ location.name }}</td>
                                <td class="px-4 py-3 text-slate-500 capitalize">{{ location.type }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ location.address ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <button @click="startEdit(location)" class="text-slate-400 hover:text-ink-800" title="Edit">
                                            <Pencil :size="16" />
                                        </button>
                                        <button
                                            @click="destroy(location)"
                                            :disabled="location.is_default"
                                            class="text-slate-400 hover:text-danger-500 disabled:opacity-30"
                                            :title="location.is_default ? 'Cannot delete the default location' : 'Delete'"
                                        >
                                            <Trash2 :size="16" />
                                        </button>
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="locations.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">No locations yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DashboardLayout>
</template>