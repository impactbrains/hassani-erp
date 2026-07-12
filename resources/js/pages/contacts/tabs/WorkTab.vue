<script setup lang="ts">
import { reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';

interface LookupOption {
    id: number;
    name: string;
}

interface WorkScheduleDay {
    day: number;
    is_working_day: boolean;
    start_time: string | null;
    end_time: string | null;
    work_location: string | null;
}

const props = defineProps<{
    contact: { id: number; employee_detail: Record<string, any> | null };
    workSchedule: WorkScheduleDay[];
    departments: LookupOption[];
    jobPositions: LookupOption[];
    possibleManagers: LookupOption[];
}>();

const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const detail = props.contact.employee_detail ?? {};

// --- Work details form (department/position/title/manager/location/note) ---
const workForm = useForm({
    department_id: detail.department_id ?? null,
    job_position_id: detail.job_position_id ?? null,
    job_title: detail.job_title ?? '',
    manager_contact_id: detail.manager_contact_id ?? null,
    work_address: detail.work_address ?? '',
    work_location: detail.work_location ?? '',
    note: detail.note ?? '',
});

function submitWork() {
    workForm.put(route('contacts.employee.work.update', { contact: props.contact.id }), {
        preserveScroll: true,
    });
}

// --- Schedule form (7 rows, submitted together) ---
// reactive() rather than useForm for the per-row table since useForm's
// dot-path error binding gets awkward with array indices; validation
// errors here are surfaced via a plain scheduleError ref instead.
const scheduleRows = reactive(
    DAY_NAMES.map((_, day) => {
        const existing = props.workSchedule.find((d) => d.day === day);
        return {
            day,
            is_working_day: existing?.is_working_day ?? false,
            start_time: existing?.start_time?.slice(0, 5) ?? '',
            end_time: existing?.end_time?.slice(0, 5) ?? '',
            work_location: existing?.work_location ?? '',
        };
    })
);

const scheduleForm = useForm({ days: scheduleRows });

// transform() is set once here, not inside submitSchedule — useForm's
// internal `days` field is a snapshot taken at useForm({ days:
// scheduleRows }) time, but scheduleRows itself is reactive and gets
// mutated directly by the v-model bindings in the table below, so the
// transform reads the LIVE reactive array at submit time rather than
// the stale snapshot useForm captured on mount.
scheduleForm.transform(() => ({ days: scheduleRows }));

function submitSchedule() {
    scheduleForm.put(route('contacts.employee.schedule.update', { contact: props.contact.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <!-- Work + Location + Note -->
        <form @submit.prevent="submitWork" class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Work</h2>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Department</label>
                    <select
                        v-model="workForm.department_id"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    >
                        <option :value="null">—</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </div>

                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Job Position</label>
                    <select
                        v-model="workForm.job_position_id"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    >
                        <option :value="null">—</option>
                        <option v-for="p in jobPositions" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>

                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Job Title</label>
                    <input
                        v-model="workForm.job_title"
                        type="text"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Manager</label>
                    <select
                        v-model="workForm.manager_contact_id"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    >
                        <option :value="null">—</option>
                        <option v-for="m in possibleManagers" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>
            </div>

            <h2 class="mt-6 font-display text-base font-semibold text-ink-900">Location</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Work Address</label>
                    <input
                        v-model="workForm.work_address"
                        type="text"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Work Location</label>
                    <input
                        v-model="workForm.work_location"
                        type="text"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>
            </div>

            <h2 class="mt-6 font-display text-base font-semibold text-ink-900">Note</h2>
            <textarea
                v-model="workForm.note"
                rows="3"
                class="mt-2 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
            />

            <button
                type="submit"
                :disabled="workForm.processing"
                class="mt-4 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
            >
                Save
            </button>
        </form>

        <!-- Timing + Usual Work Location -->
        <form @submit.prevent="submitSchedule" class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Timing &amp; Usual Work Location</h2>

            <table class="mt-4 w-full text-left font-sans text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500">
                        <th class="py-2 pr-3 font-medium">Day</th>
                        <th class="py-2 pr-3 font-medium">Working</th>
                        <th class="py-2 pr-3 font-medium">Start</th>
                        <th class="py-2 pr-3 font-medium">End</th>
                        <th class="py-2 font-medium">Location</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in scheduleRows" :key="row.day" class="border-b border-slate-100 last:border-0">
                        <td class="py-2 pr-3 text-ink-900">{{ DAY_NAMES[row.day] }}</td>
                        <td class="py-2 pr-3">
                            <input type="checkbox" v-model="row.is_working_day" class="rounded border-slate-300 text-brand-500 focus:ring-brand-500" />
                        </td>
                        <td class="py-2 pr-3">
                            <input
                                type="time"
                                v-model="row.start_time"
                                :disabled="!row.is_working_day"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm disabled:bg-slate-50 focus:border-brand-500 focus:ring-brand-500"
                            />
                        </td>
                        <td class="py-2 pr-3">
                            <input
                                type="time"
                                v-model="row.end_time"
                                :disabled="!row.is_working_day"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm disabled:bg-slate-50 focus:border-brand-500 focus:ring-brand-500"
                            />
                        </td>
                        <td class="py-2">
                            <input
                                type="text"
                                v-model="row.work_location"
                                :disabled="!row.is_working_day"
                                placeholder="e.g. Main store"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm disabled:bg-slate-50 focus:border-brand-500 focus:ring-brand-500"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="Object.keys(scheduleForm.errors).length" class="mt-3 font-sans text-sm text-danger-500">
                Check that each day's end time is after its start time.
            </p>

            <button
                type="submit"
                :disabled="scheduleForm.processing"
                class="mt-4 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
            >
                Save Schedule
            </button>
        </form>
    </div>
</template>