<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Single-series column chart (e.g. sales by hour). Thin columns capped at
 * 24px, 4px rounded tops, hairline grid, value on hover; pages offer a
 * table view beside it.
 */
const props = withDefaults(defineProps<{ labels: string[]; values: number[]; format?: (v: number) => string; height?: number; color?: string; ariaLabel?: string }>(), {
    height: 200,
    color: '#3E5C9A',
    format: (v: number) => String(v),
    ariaLabel: undefined,
});

const W = 640;
const pad = { top: 12, right: 8, bottom: 26, left: 44 };
const max = computed(() => niceMax(Math.max(1, ...props.values)));
const ticks = computed(() => [0, 0.5, 1].map((f) => max.value * f));
const band = computed(() => (W - pad.left - pad.right) / Math.max(1, props.values.length));
const barW = computed(() => Math.min(24, band.value * 0.62));
const hover = ref<number | null>(null);

function niceMax(v: number): number {
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    return [1, 2, 2.5, 5, 10].map((m) => m * p).find((m) => m >= v) ?? v;
}
function y(v: number) {
    return pad.top + (props.height - pad.top - pad.bottom) * (1 - v / max.value);
}
function compact(v: number) {
    if (v >= 1_000_000) return `${+(v / 1_000_000).toFixed(1)}M`;
    if (v >= 1000) return `${+(v / 1000).toFixed(1)}K`;
    return String(Math.round(v));
}
function barPath(i: number) {
    const x = pad.left + band.value * i + (band.value - barW.value) / 2;
    const top = y(props.values[i]);
    const base = y(0);
    const h = Math.max(0, base - top);
    const r = Math.min(4, h, barW.value / 2);
    return `M${x},${base} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW.value - r} Q${x + barW.value},${top} ${x + barW.value},${top + r} V${base} Z`;
}
</script>

<template>
    <div class="relative" dir="ltr">
        <svg :viewBox="`0 0 ${W} ${height}`" class="w-full" role="img" :aria-label="ariaLabel" @mouseleave="hover = null">
            <g v-for="tick in ticks" :key="tick">
                <line :x1="pad.left" :x2="W - pad.right" :y1="y(tick)" :y2="y(tick)" stroke="#E3E8EF" stroke-width="1" />
                <text :x="pad.left - 8" :y="y(tick) + 4" text-anchor="end" fill="#8496B5" font-size="11">{{ compact(tick) }}</text>
            </g>
            <g v-for="(v, i) in values" :key="i">
                <rect :x="pad.left + band * i" :y="pad.top" :width="band" :height="height - pad.top - pad.bottom" fill="transparent" @mouseenter="hover = i" />
                <path v-if="v > 0" :d="barPath(i)" :fill="color" :opacity="hover === null || hover === i ? 1 : 0.45" class="pointer-events-none transition-opacity" />
                <text v-if="labels.length <= 14 || i % 2 === 0" :x="pad.left + band * i + band / 2" :y="height - 8" text-anchor="middle" fill="#8496B5" font-size="11">{{ labels[i] }}</text>
            </g>
        </svg>
        <div
            v-if="hover !== null"
            class="pointer-events-none absolute top-0 -translate-x-1/2 rounded border border-ink-100 bg-white px-2.5 py-1.5 text-[13px] shadow-pop"
            :style="{ left: `${((pad.left + band * hover + band / 2) / W) * 100}%` }"
        >
            <div class="text-ink-500">{{ labels[hover] }}</div>
            <div class="font-semibold tabular-nums text-ink-900">{{ format(values[hover]) }}</div>
        </div>
    </div>
</template>
