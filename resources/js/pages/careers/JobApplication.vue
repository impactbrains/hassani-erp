<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

const form = useForm({
    name: '',
    email: '',
    phone: '',
    city: '',
    notes: '',
});

function submit() {
    form.post(route('careers.apply.store'));
}
</script>

<template>
    <Head title="Job Application — Hassani Bachat Bazar" />

    <div class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-lg px-6 py-16">
            <Link :href="route('careers.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">Apply for a job</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">
                Tell us a bit about yourself and we'll be in touch.
            </p>

            <form @submit.prevent="submit" class="mt-6 rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Full name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.name" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Phone number</label>
                        <input
                            v-model="form.phone"
                            type="text"
                            required
                            placeholder="03XX-XXXXXXX"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.phone" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.phone }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">Email (optional)</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p v-if="form.errors.email" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">City (optional)</label>
                        <input
                            v-model="form.city"
                            type="text"
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block font-sans text-sm font-medium text-ink-900">
                            Tell us about yourself (optional)
                        </label>
                        <textarea
                            v-model="form.notes"
                            rows="4"
                            placeholder="Relevant experience, availability, what role you're interested in..."
                            class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="mt-6 w-full rounded-lg bg-brand-500 py-2.5 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                >
                    Submit application
                </button>
            </form>
        </div>
    </div>
</template>