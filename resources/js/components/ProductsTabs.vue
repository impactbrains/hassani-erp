<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const tabs = [
    { label: 'Products', href: () => route('products.index'), active: () => page.component === 'products/Index' || page.component === 'products/Form' },
    { label: 'Categories', href: () => route('products.manage.categories.index'), active: () => page.component === 'products/manage/Categories' },
    { label: 'Units', href: () => route('products.manage.units.index'), active: () => page.component === 'products/manage/Units' },
    { label: 'Taxes', href: () => route('products.manage.taxes.index'), active: () => page.component === 'products/manage/Taxes' },
];
</script>

<template>
    <div class="mb-6 flex gap-1 border-b border-slate-200">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href()"
            class="border-b-2 px-4 py-2.5 font-sans text-sm font-medium transition-colors"
            :class="
                tab.active()
                    ? 'border-brand-500 text-ink-900'
                    : 'border-transparent text-slate-500 hover:text-ink-800'
            "
        >
            {{ tab.label }}
        </Link>
    </div>
</template>