<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';
import { date, dateTime, money, percent, qty } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Table for lists. Columns say how to show each value (money, quantity,
 * date, status ...); a cell slot `#cell-<key>` overrides any column.
 */
interface Column { key: string; label: string; type?: 'text' | 'money' | 'qty' | 'int' | 'decimal' | 'percent' | 'date' | 'datetime' | 'status' | 'bool'; class?: string }
const props = withDefaults(defineProps<{ columns: Column[]; rows: any[]; rowHref?: (row: any) => string | null; empty?: string; dense?: boolean }>(), { rowHref: undefined, empty: 'Nothing here yet.', dense: false });

const numeric = (c: Column) => ['money', 'qty', 'int', 'decimal', 'percent'].includes(c.type ?? '');
function value(row: any, key: string) {
    return key.split('.').reduce((v, k) => (v === null || v === undefined ? v : v[k]), row);
}
function show(c: Column, v: any) {
    if (v === null || v === undefined || v === '') return '—';
    switch (c.type) {
        case 'money': return money(v);
        case 'qty': case 'decimal': return qty(v);
        case 'int': return Number(v).toLocaleString('en-PK');
        case 'percent': return percent(v);
        case 'date': return date(v);
        case 'datetime': return dateTime(v);
        case 'bool': return v ? t('Yes') : t('No');
        default: return v;
    }
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="table-base" :class="dense ? '[&_td]:py-1.5' : ''">
            <thead>
                <tr><th v-for="c in columns" :key="c.key" :class="[numeric(c) ? 'text-end' : '', c.class]">{{ t(c.label) }}</th><th v-if="$slots.actions" /></tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in rows" :key="row.id ?? i">
                    <td v-for="(c, ci) in columns" :key="c.key" :class="[numeric(c) ? 'num' : '', c.class]">
                        <slot :name="`cell-${c.key}`" :row="row" :value="value(row, c.key)">
                            <Badge v-if="c.type === 'status' && value(row, c.key)" :status="value(row, c.key)" />
                            <Link v-else-if="ci === 0 && props.rowHref && props.rowHref(row)" :href="props.rowHref(row)!" class="link">{{ show(c, value(row, c.key)) }}</Link>
                            <template v-else>{{ show(c, value(row, c.key)) }}</template>
                        </slot>
                    </td>
                    <td v-if="$slots.actions" class="whitespace-nowrap text-end"><slot name="actions" :row="row" /></td>
                </tr>
                <tr v-if="!rows.length"><td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="py-10 text-center text-ink-500">{{ t(empty) }}</td></tr>
            </tbody>
        </table>
    </div>
</template>
