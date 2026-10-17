<script setup lang="ts">
import { computed, ref } from 'vue';
import { t } from '@/lib/i18n';

/**
 * Report chart: bars or a line for up to two series (this period and the
 * comparison period). The comparison series is drawn lighter and dashed.
 */
const props = withDefaults(defineProps<{ type?: string; labels: string[]; series: { name: string; data: (number | string | null)[] }[]; format?: (v: number) => string; height?: number }>(), { type: 'bar', format: (v: number) => v.toLocaleString('en-PK'), height: 220 });
const colors = ['#3E5C9A', '#B7820F'];
const W = 720;
const pad = { top: 14, right: 10, bottom: 28, left: 50 };
const values = computed(() => props.series.map((s) => s.data.map((v) => (v === null || v === undefined ? null : Number(v)))));
const flat = computed(() => values.value.flat().filter((v): v is number => v !== null && !Number.isNaN(v)));
const max = computed(() => nice(Math.max(1, ...flat.value)));
const min = computed(() => Math.min(0, ...flat.value));
const band = computed(() => (W - pad.left - pad.right) / Math.max(1, props.labels.length));
const hover = ref<number | null>(null);

function nice(v: number) {
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    return [1, 2, 2.5, 5, 10].map((m) => m * p).find((m) => m >= v) ?? v;
}
function y(v: number) {
    return pad.top + (props.height - pad.top - pad.bottom) * (1 - (v - min.value) / (max.value - min.value || 1));
}
function x(i: number) {
    return pad.left + band.value * i + band.value / 2;
}
function compact(v: number) {
    const a = Math.abs(v);
    if (a >= 1_000_000) return `${+(v / 1_000_000).toFixed(1)}M`;
    if (a >= 1000) return `${+(v / 1000).toFixed(1)}K`;
    return String(Math.round(v * 100) / 100);
}
const barW = computed(() => Math.max(2, Math.min(22, (band.value * 0.7) / Math.max(1, props.series.length))));
function linePath(s: (number | null)[]) {
    let d = '';
    s.forEach((v, i) => {
        if (v === null) return;
        d += `${d === '' || s[i - 1] === null ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
    });
    return d;
}
const labelEvery = computed(() => Math.max(1, Math.ceil(props.labels.length / 12)));
</script>

<template>
    <div class="relative">
        <div class="mb-2 flex flex-wrap gap-4 text-[12.5px] text-ink-600">
            <span v-for="(s, i) in series" :key="i" class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: colors[i % 2], opacity: i ? 0.7 : 1 }" />{{ t(s.name) }}</span>
        </div>
        <svg :viewBox="`0 0 ${W} ${height}`" class="w-full" role="img" :aria-label="series.map((s) => t(s.name)).join(', ')" @mouseleave="hover = null">
            <g class="text-ink-400">
                <template v-for="f in [0, 0.5, 1]" :key="f">
                    <line :x1="pad.left" :x2="W - pad.right" :y1="y(min + (max - min) * f)" :y2="y(min + (max - min) * f)" stroke="currentColor" stroke-opacity="0.25" />
                    <text :x="pad.left - 6" :y="y(min + (max - min) * f) + 4" text-anchor="end" font-size="11" fill="currentColor">{{ compact(min + (max - min) * f) }}</text>
                </template>
                <line v-if="min < 0" :x1="pad.left" :x2="W - pad.right" :y1="y(0)" :y2="y(0)" stroke="currentColor" stroke-opacity="0.6" />
            </g>
            <template v-if="type === 'line'">
                <path v-for="(s, si) in values" :key="si" :d="linePath(s)" fill="none" :stroke="colors[si % 2]" stroke-width="2" :stroke-dasharray="si ? '5 4' : undefined" :stroke-opacity="si ? 0.8 : 1" />
                <template v-if="hover !== null"><circle v-for="(s, si) in values" :key="si" v-show="s[hover] !== null" :cx="x(hover)" :cy="y(s[hover] ?? 0)" r="3.5" :fill="colors[si % 2]" /></template>
            </template>
            <template v-else>
                <template v-for="(s, si) in values" :key="si">
                    <rect v-for="(v, i) in s" :key="i" v-show="v !== null" :x="x(i) - (barW * values.length) / 2 + barW * si" :y="Math.min(y(v ?? 0), y(0))" :width="barW - 1" :height="Math.abs(y(0) - y(v ?? 0))" :fill="colors[si % 2]" :fill-opacity="si ? 0.6 : 1" rx="2" />
                </template>
            </template>
            <rect v-for="(l, i) in labels" :key="'h' + i" :x="pad.left + band * i" :y="pad.top" :width="band" :height="height - pad.top - pad.bottom" fill="transparent" @mouseenter="hover = i" />
            <text v-for="(l, i) in labels" v-show="i % labelEvery === 0" :key="'l' + i" :x="x(i)" :y="height - 8" text-anchor="middle" font-size="11" class="fill-ink-500">{{ l }}</text>
        </svg>
        <div v-if="hover !== null" class="pointer-events-none absolute top-6 rounded border border-ink-100 bg-white px-2.5 py-1.5 text-[12.5px] shadow" :style="{ left: `min(calc(${(x(hover) / W) * 100}% + 8px), calc(100% - 11rem))` }">
            <div class="font-medium">{{ labels[hover] }}</div>
            <div v-for="(s, si) in series" :key="si" class="tabular-nums" :style="{ color: colors[si % 2] }">{{ t(s.name) }}: {{ values[si][hover] === null ? '—' : format(values[si][hover] as number) }}</div>
        </div>
    </div>
</template>
