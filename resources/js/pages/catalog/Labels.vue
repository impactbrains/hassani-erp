<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Shelf labels with name (English and Urdu), price and a Code 128 barcode,
 * printed on A4 sticker sheets or a label printer.
 */
const props = defineProps<{ variants: any[]; copies: number }>();
const copies = ref(props.copies);
const svgs = ref<Record<number, string>>({});

// Code 128-B: enough for SKUs and EAN digits; drawn as SVG so it prints sharp.
const PATTERNS = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
function code128(text: string): string {
    const codes = [104];
    for (const ch of text) codes.push(Math.max(0, Math.min(94, ch.charCodeAt(0) - 32)));
    codes.push(codes.reduce((sum, c, i) => sum + c * (i === 0 ? 1 : i), 0) % 103);
    codes.push(106);
    let x = 10;
    let bars = '';
    for (const c of codes) {
        PATTERNS[c].split('').forEach((w, i) => {
            const width = Number(w);
            if (i % 2 === 0) bars += `<rect x="${x}" y="0" width="${width}" height="40" />`;
            x += width;
        });
    }
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${x + 10} 40" preserveAspectRatio="none" class="h-10 w-full">${bars}</svg>`;
}
function print() {
    window.print();
}
onMounted(() => props.variants.forEach((v) => (svgs.value[v.id] = code128(String(v.barcode)))));
</script>

<template>
    <Head :title="t('Shelf labels')" />
    <div class="no-print flex items-center gap-3 border-b border-ink-100 bg-white px-4 py-3">
        <Link :href="route('catalog.products.index')" class="link">{{ t('Back to products') }}</Link>
        <label class="ms-auto flex items-center gap-2 text-sm">{{ t('Copies of each') }} <input v-model.number="copies" type="number" min="1" max="100" class="input w-20 py-1" /></label>
        <button class="rounded bg-ink-800 px-4 py-2 text-sm font-medium text-white" @click="print">{{ t('Print') }}</button>
    </div>
    <div class="grid grid-cols-3 gap-2 p-4 print:grid-cols-3 print:gap-1 print:p-0">
        <template v-for="v in variants" :key="v.id">
            <div v-for="n in copies" :key="n" class="break-inside-avoid rounded border border-dashed border-ink-200 p-2 text-black print:border-black">
                <div class="truncate text-[13px] font-semibold leading-tight">{{ v.name }}</div>
                <div v-if="v.name_ur" class="urdu truncate text-right text-[14px]" dir="rtl">{{ v.name_ur }}</div>
                <div class="flex items-end justify-between">
                    <span class="text-xl font-bold tabular-nums">{{ money(v.price) }}</span>
                    <span v-if="v.mrp" class="text-[10px]">MRP {{ money(v.mrp) }}</span>
                </div>
                <div v-html="svgs[v.id]" />
                <div class="text-center text-[10px] tracking-widest">{{ v.barcode }}</div>
            </div>
        </template>
    </div>
</template>
