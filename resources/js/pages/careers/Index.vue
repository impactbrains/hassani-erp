<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Briefcase, Truck } from 'lucide-vue-next';
</script>

<template>
    <Head title="Careers — Hassani Bachat Bazar" />

    <div class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-2xl px-6 py-16">
            <div class="text-center">
                <h1 class="font-display text-3xl font-bold text-ink-900">Careers at Hassani Bachat Bazar</h1>
                <p class="mt-2 font-sans text-base text-slate-500">
                    Join our team, or partner with us as a supplier.
                </p>
            </div>

            <div class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Link
                    :href="route('careers.apply')"
                    class="flex flex-col items-center gap-3 rounded-lg border border-slate-200 bg-white p-8 text-center transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-brand-500/15 text-ink-900">
                        <Briefcase :size="24" :stroke-width="1.75" />
                    </div>
                    <h2 class="font-display text-lg font-semibold text-ink-900">Apply for a job</h2>
                    <p class="font-sans text-sm text-slate-500">Looking to join our team? Tell us about yourself.</p>
                </Link>

                <Link
                    :href="route('careers.supplier-apply')"
                    class="flex flex-col items-center gap-3 rounded-lg border border-slate-200 bg-white p-8 text-center transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-ink-700/10 text-ink-900">
                        <Truck :size="24" :stroke-width="1.75" />
                    </div>
                    <h2 class="font-display text-lg font-semibold text-ink-900">Become a supplier</h2>
                    <p class="font-sans text-sm text-slate-500">Interested in a supply partnership with us?</p>
                </Link>
            </div>
        </div>
    </div>
</template>