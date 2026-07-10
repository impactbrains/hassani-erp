<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';

interface LabelData {
    name: string | null;
    price: string | null;
    barcode: string;
    barcodeSvg: string; // base64-encoded SVG
}

const props = defineProps<{
    labels: LabelData[];
    columns: number;
}>();

function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function svgDataUrl(base64: string): string {
    return `data:image/svg+xml;base64,${base64}`;
}

// Format as EAN-13 typically appears: single digit, then groups of 6, e.g.
// "6 123456 789012" — purely cosmetic spacing, doesn't change the code itself.
function formatBarcodeDigits(code: string): string {
    if (code.length !== 13) return code;
    return `${code.slice(0, 1)} ${code.slice(1, 7)} ${code.slice(7, 13)}`;
}

const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${props.columns}, 1fr)`,
}));

onMounted(() => {
    setTimeout(() => window.print(), 300);
});
</script>

<template>
    <Head :title="labels.length > 1 ? `${labels.length} Labels` : `Label — ${labels[0]?.name ?? labels[0]?.barcode}`" />

    <div class="sheet" :style="gridStyle">
        <div v-for="(item, index) in labels" :key="index" class="label">
            <p v-if="item.name" class="name">{{ item.name }}</p>
            <p v-if="item.price !== null" class="price">Rs {{ money(item.price) }}</p>
            <img :src="svgDataUrl(item.barcodeSvg)" alt="" class="barcode-image" />
            <p class="barcode-digits">{{ formatBarcodeDigits(item.barcode) }}</p>
        </div>
    </div>
</template>

<style>
/* A4 sheet of labels arranged in a grid, columns configurable at generation
   time. Intentionally global and print-focused — this page has no
   DashboardLayout, so there's no design-system CSS to inherit from. */
@media print {
    @page {
        size: A4;
        margin: 10mm;
    }
    body {
        margin: 0;
    }
}

body {
    background: #e5e7eb; /* subtle grey so the sheet edge is visible on screen before printing */
}

.sheet {
    display: grid;
    gap: 4mm 4mm;
    width: 190mm; /* A4 width (210mm) minus 10mm margins each side */
    margin: 0 auto;
    background: white;
    padding: 4mm;
}

.label {
    box-sizing: border-box;
    border: 1px dashed #d1d5db; /* cut guide — remove if using pre-cut/die-cut sticker stock */
    border-radius: 2mm;
    padding: 2mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: 'Helvetica', 'Arial', sans-serif;
    text-align: center;
    overflow: hidden;
    aspect-ratio: 4 / 3;
}

.name {
    font-size: 8px;
    font-weight: bold;
    margin: 0 0 1mm 0;
    line-height: 1.1;
    max-height: 18px;
    overflow: hidden;
}

.price {
    font-size: 9px;
    font-weight: bold;
    margin: 0 0 1mm 0;
}

.barcode-image {
    width: 90%;
    max-height: 45%;
    object-fit: contain;
}

.barcode-digits {
    font-size: 7px;
    letter-spacing: 0.5px;
    margin: 1mm 0 0 0;
    font-family: 'Courier New', monospace;
}
</style>