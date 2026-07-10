<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import BarcodeTabs from '@/components/BarcodeTabs.vue';
import { Plus, Trash2 } from 'lucide-vue-next';

interface Row {
  [key: string]: any;
    label: string;
}

function blankRow(): Row {
    return { label: '' };
}

const form = useForm({
    rows: [blankRow()] as Row[],
    columns: 3,
});

function addRow() {
    form.rows.push(blankRow());
}

function removeRow(index: number) {
    if (form.rows.length === 1) return;
    form.rows.splice(index, 1);
}

function addMultiple(count: number) {
    for (let i = 0; i < count; i++) {
        form.rows.push(blankRow());
    }
}

function submit() {
    // This POST returns a full Inertia page (the print sheet), not JSON —
    // a normal Inertia form submission handles that navigation for us.
    form.post(route('barcode.store-batch'));
}
</script>

<template>
    <Head title="Generate Barcode Labels" />

    <DashboardLayout>
        <div class="mx-auto max-w-2xl">
            <h1 class="font-display text-2xl font-bold text-ink-900">Barcodes</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Generate blank barcode labels to print and stick on products.
            </p>

            <BarcodeTabs />

            <form @submit.prevent="submit" class="mt-6 flex flex-col gap-6">
                <section class="rounded-lg border border-slate-200 bg-white p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Barcodes to generate
                        </h2>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                @click="addMultiple(10)"
                                class="font-sans text-xs font-medium text-ink-800 hover:underline"
                            >
                                +10
                            </button>
                            <button
                                type="button"
                                @click="addMultiple(20)"
                                class="font-sans text-xs font-medium text-ink-800 hover:underline"
                            >
                                +20
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div
                            v-for="(row, index) in form.rows"
                            :key="index"
                            class="flex items-center gap-2"
                        >
                            <span class="w-6 shrink-0 font-sans text-xs text-slate-400">{{ index + 1 }}.</span>
                            <input
                                v-model="row.label"
                                type="text"
                                placeholder="Label (optional) — e.g. Lifebuoy Soap"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <button
                                type="button"
                                @click="removeRow(index)"
                                :disabled="form.rows.length === 1"
                                class="shrink-0 text-slate-400 hover:text-danger-500 disabled:opacity-30"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="addRow"
                        class="mt-3 flex items-center gap-1.5 font-sans text-sm font-medium text-ink-800 hover:underline"
                    >
                        <Plus :size="16" /> Add barcode
                    </button>

                    <p v-if="form.errors.rows" class="mt-2 font-sans text-xs text-danger-500">{{ form.errors.rows }}</p>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="mb-4 font-sans text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Print layout
                    </h2>

                    <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Columns per row (A4 sheet)</label>
                    <select
                        v-model.number="form.columns"
                        class="w-full max-w-[10rem] rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    >
                        <option v-for="n in 6" :key="n" :value="n">{{ n }}</option>
                    </select>
                    <p v-if="form.errors.columns" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.columns }}</p>
                </section>

                <div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-5 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                    >
                        Generate & print ({{ form.rows.length }} barcode{{ form.rows.length === 1 ? '' : 's' }})
                    </button>
                </div>
            </form>
        </div>
    </DashboardLayout>
</template>