<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';

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

const props = defineProps<{
    contact: ContactData | null;
}>();

const isEditing = !!props.contact;

const form = useForm({
    type: props.contact?.type ?? 'customer',
    name: props.contact?.name ?? '',
    email: props.contact?.email ?? '',
    phone: props.contact?.phone ?? '',
    address: props.contact?.address ?? '',
    city: props.contact?.city ?? '',
    tax_number: props.contact?.tax_number ?? '',
    is_active: props.contact?.is_active ?? true,
});

function submit() {
    if (isEditing && props.contact) {
        form.put(route('contacts.update', { contact: props.contact.id }));
    } else {
        form.post(route('contacts.store'));
    }
}
</script>

<template>
    <Head :title="isEditing ? `Edit ${contact?.name}` : 'New contact'" />

    <DashboardLayout>
        <div class="mx-auto max-w-2xl">
            <Link
                :href="isEditing ? route('contacts.show', { contact: contact!.id }) : route('contacts.index')"
                class="font-sans text-sm text-slate-500 hover:underline"
            >
                &larr; Back
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">
                {{ isEditing ? `Edit ${contact?.name}` : 'New contact' }}
            </h1>

            <form @submit.prevent="submit" class="mt-6 rounded-lg border border-slate-200 bg-white p-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Type</label>
                        <select
                            v-model="form.type"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option value="customer">Customer</option>
                            <option value="supplier">Supplier</option>
                            <option value="employee">Employee</option>
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

                    <div class="col-span-2">
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Phone</label>
                        <input
                            v-model="form.phone"
                            type="text"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.email" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.email }}</p>
                    </div>

                    <div class="col-span-2">
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Address</label>
                        <textarea
                            v-model="form.address"
                            rows="2"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">City</label>
                        <input
                            v-model="form.city"
                            type="text"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Tax number</label>
                        <input
                            v-model="form.tax_number"
                            type="text"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-5 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                    >
                        {{ isEditing ? 'Save changes' : 'Create contact' }}
                    </button>
                </div>
            </form>
        </div>
    </DashboardLayout>
</template>