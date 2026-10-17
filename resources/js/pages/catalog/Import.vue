<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Field from '@/components/form/Field.vue';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

/** Adds many products at once from a CSV file (e.g. typed in Excel and saved as CSV UTF-8). */
defineProps<{ columns: string[]; result: { created: number; skipped: number; errors: string[]; dry_run: boolean } | null }>();
const form = useForm<{ file: File | null; dry_run: boolean }>({ file: null, dry_run: true });
</script>

<template>
    <PageHeader :title="t('Import products')" :subtitle="t('Add many products at once from a CSV file. Existing SKUs are skipped, never overwritten.')">
        <template #actions><Button :href="route('catalog.products.index')">{{ t('Back to products') }}</Button></template>
    </PageHeader>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel space-y-4 p-4">
            <h2>{{ t('1. Prepare the file') }}</h2>
            <p class="text-sm text-ink-600">{{ t('Download the template, fill one product per row in Excel, then “Save as” → “CSV UTF-8” so Urdu names stay correct. Only name and unit are required.') }}</p>
            <a :href="route('catalog.import.template')" class="inline-flex h-10 items-center gap-2 rounded border border-ink-200 bg-white px-4 text-sm font-medium hover:bg-ink-50"><Download class="h-4 w-4" />{{ t('Download template') }}</a>
            <div class="text-[13px] text-ink-600">
                <p class="mb-1 font-medium">{{ t('Columns') }}</p>
                <code class="block rounded bg-ink-50 p-2 text-[12.5px]">{{ columns.join(', ') }}</code>
                <p class="mt-2">{{ t('unit and pack_unit use the short codes from Settings → Lists → Units (pcs, box, ctn, kg ...). pack_size is how many base units are in the pack.') }}</p>
            </div>
        </section>
        <section class="panel space-y-4 p-4">
            <h2>{{ t('2. Upload') }}</h2>
            <form class="space-y-4" @submit.prevent="form.post(route('catalog.import.store'), { forceFormData: true })">
                <Field label="CSV file" :error="form.errors.file"><input type="file" accept=".csv,text/csv" class="block text-sm" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></Field>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.dry_run" type="checkbox" class="rounded border-ink-300" />{{ t('Check only (nothing is saved)') }}</label>
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.file">{{ form.dry_run ? t('Check file') : t('Import products') }}</Button>
            </form>
            <div v-if="result" class="rounded-lg border p-3 text-sm" :class="result.errors.length ? 'border-haldi-300 bg-haldi-50' : 'border-ledger-green/30 bg-ledger-greenSoft'">
                <p class="font-medium">{{ result.dry_run ? t('Check result') : t('Import result') }}: {{ result.created }} {{ result.dry_run ? t('can be added') : t('added') }}, {{ result.skipped }} {{ t('skipped (SKU exists)') }}, {{ result.errors.length }} {{ t('with problems') }}.</p>
                <ul v-if="result.errors.length" class="mt-2 max-h-60 list-disc space-y-0.5 overflow-auto ps-5 text-ledger-red"><li v-for="(e, i) in result.errors" :key="i">{{ e }}</li></ul>
                <p v-if="result.dry_run && result.created" class="mt-2">{{ t('Untick “Check only” and upload again to save them.') }}</p>
            </div>
        </section>
    </div>
</template>
