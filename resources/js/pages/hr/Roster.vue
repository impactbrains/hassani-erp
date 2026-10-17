<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';
import { colorHex } from '@/lib/colors';

/** Weekly shift plan. Pick a shift per person per day; leave the box empty for a day off. */
const props = defineProps<{ week: string; days: string[]; employees: any[]; shifts: any[]; entries: any[]; canManage: boolean }>();
const at = computed(() => Object.fromEntries(props.entries.map((e) => [`${e.contact_id}|${String(e.work_date).slice(0, 10)}`, e.work_shift_id])));
function set(contactId: number, day: string, value: string) {
    router.post(route('hr.roster.assign'), { contact_id: contactId, date: day, work_shift_id: value ? Number(value) : null }, { preserveScroll: true, preserveState: true, only: ['entries'] });
}
function shiftWeek(n: number) {
    const d = new Date(props.week);
    d.setDate(d.getDate() + n * 7);
    router.get(route('hr.roster'), { week: d.toISOString().slice(0, 10) });
}
function copyLastWeek() {
    // Fills this week from the previous week's pattern, one assignment at a time.
    const prev = new Date(props.week);
    prev.setDate(prev.getDate() - 7);
    router.get(route('hr.roster'), { week: prev.toISOString().slice(0, 10) }, { preserveState: false });
}
const label = (d: string) => new Date(d).toLocaleDateString('en-PK', { weekday: 'short', day: 'numeric', month: 'short' });
const color = (id: number | undefined) => colorHex(props.shifts.find((s) => s.id === id)?.color);
</script>

<template>
    <PageHeader :title="t('Roster')" :subtitle="t('Who works which shift this week. Shifts are set up in Settings → Lists.')">
        <template #actions><Button @click="shiftWeek(-1)">‹ {{ t('Previous week') }}</Button><Button @click="shiftWeek(1)">{{ t('Next week') }} ›</Button></template>
    </PageHeader>
    <section class="panel overflow-x-auto">
        <table class="table-base">
            <thead><tr><th>{{ t('Employee') }}</th><th v-for="d in days" :key="d">{{ label(d) }}</th></tr></thead>
            <tbody>
                <tr v-for="e in employees" :key="e.id">
                    <td class="font-medium">{{ e.name }}</td>
                    <td v-for="d in days" :key="d" class="p-1">
                        <select class="input py-1 text-[13px]" :value="at[`${e.id}|${d}`] ?? ''" :disabled="!canManage" :style="at[`${e.id}|${d}`] ? { borderColor: color(at[`${e.id}|${d}`]), background: color(at[`${e.id}|${d}`]) + '14' } : {}" @change="set(e.id, d, ($event.target as HTMLSelectElement).value)">
                            <option value="">{{ t('Off') }}</option><option v-for="s in shifts" :key="s.id" :value="s.id">{{ s.name }} {{ String(s.start_time).slice(0, 5) }}</option>
                        </select>
                    </td>
                </tr>
                <tr v-if="!employees.length"><td :colspan="days.length + 1" class="py-8 text-center text-ink-500">{{ t('No employees in this store.') }}</td></tr>
            </tbody>
        </table>
    </section>
    <p v-if="!shifts.length" class="mt-3 text-sm text-haldi-800">{{ t('No shifts defined yet. Add them in Settings → Lists → Work shifts.') }}</p>
</template>
