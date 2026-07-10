<script setup lang="ts">
import { ref } from 'vue';
import { Plus } from 'lucide-vue-next';

interface Option {
    id: number;
    label: string;
}

const props = defineProps<{
    modelValue: number | null;
    options: Option[];
    placeholder?: string;
    createLabel?: string; // e.g. "Add new unit"
    createFields: { key: string; label: string; placeholder?: string }[]; // fields shown in the inline create form
    createEndpoint: string; // route() result, e.g. route('units.store')
    labelField?: string; // which field of the server's JSON response to use as the display label (defaults to 'name')
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
    'created': [option: Option]; // parent can push the new option into its own options list
}>();

const CREATE_SENTINEL = '__create_new__';

const isCreating = ref(false);
const createForm = ref<Record<string, string>>({});
const isSubmitting = ref(false);
const error = ref<string | null>(null);

function onSelectChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value;

    if (value === CREATE_SENTINEL) {
        isCreating.value = true;
        createForm.value = Object.fromEntries(props.createFields.map((f) => [f.key, '']));
        return;
    }

    emit('update:modelValue', value ? Number(value) : null);
}

async function submitCreate() {
    isSubmitting.value = true;
    error.value = null;

    try {
        const response = await fetch(props.createEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify(createForm.value),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            error.value = data?.message ?? 'Could not create — check the fields and try again.';
            isSubmitting.value = false;
            return;
        }

        const created = await response.json();
        const option: Option = { id: created.id, label: created[props.labelField ?? 'name'] };

        emit('created', option);
        emit('update:modelValue', option.id);
        isCreating.value = false;
    } catch {
        error.value = 'Something went wrong — check your connection and try again.';
    } finally {
        isSubmitting.value = false;
    }
}

function cancelCreate() {
    isCreating.value = false;
    error.value = null;
}
</script>

<template>
    <div>
        <select
            :value="modelValue ?? ''"
            @change="onSelectChange"
            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
        >
            <option value="">{{ placeholder ?? 'None' }}</option>
            <option v-for="opt in options" :key="opt.id" :value="opt.id">{{ opt.label }}</option>
            <option :value="CREATE_SENTINEL" class="font-medium text-ink-800">
                + {{ createLabel ?? 'Create new' }}
            </option>
        </select>

        <div v-if="isCreating" class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div class="flex flex-col gap-2">
                <input
                    v-for="field in createFields"
                    :key="field.key"
                    v-model="createForm[field.key]"
                    type="text"
                    :placeholder="field.placeholder ?? field.label"
                    class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                />
            </div>

            <p v-if="error" class="mt-2 font-sans text-xs text-danger-500">{{ error }}</p>

            <div class="mt-2 flex gap-2">
                <button
                    type="button"
                    @click="submitCreate"
                    :disabled="isSubmitting"
                    class="flex items-center gap-1 rounded-lg bg-brand-500 px-3 py-1.5 font-sans text-xs font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50"
                >
                    <Plus :size="12" /> {{ isSubmitting ? 'Adding...' : 'Add' }}
                </button>
                <button
                    type="button"
                    @click="cancelCreate"
                    class="rounded-lg px-3 py-1.5 font-sans text-xs text-slate-500 hover:bg-slate-100"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>
