<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import EmployeeTabs from '@/components/EmployeeTabs.vue';
import WorkTab from '../tabs/WorkTab.vue';
import ResumeTab from '../tabs/ResumeTab.vue';
import PersonalTab from '../tabs/PersonalTab.vue';
import BadgesTab from '../tabs/BadgesTab.vue';
import HistoryTab from '../tabs/HistoryTab.vue';
import { ArrowLeft } from 'lucide-vue-next';

/**
 * Types here intentionally mirror EmployeeController::show()'s Inertia
 * payload field-for-field. If you add a field to that controller later,
 * add it here too — Inertia won't type-error on a mismatch, it'll just
 * silently be undefined in the template.
 */
interface Contact {
    id: number;
    name: string;
    type: string;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    employee_detail: Record<string, any> | null;
    resume_items: any[];
    skills: any[];
    badges: any[];
}

interface WorkScheduleDay {
    day: number;
    is_working_day: boolean;
    start_time: string | null;
    end_time: string | null;
    work_location: string | null;
}

interface LookupOption {
    id: number;
    name: string;
}

defineProps<{
    contact: Contact;
    workSchedule: WorkScheduleDay[];
    departments: LookupOption[];
    jobPositions: LookupOption[];
    possibleManagers: LookupOption[];
    allSkills: LookupOption[];
    allBadges: { id: number; name: string; icon: string | null; color: string | null }[];
}>();

// Tab switching is purely client-side state, not a route change — Work,
// Resume, Personal, and Badges all came from the same Inertia payload
// (see EmployeeController::show), so there's nothing to refetch when
// switching between them. History is the only tab that lazy-loads its
// own data, handled inside HistoryTab.vue itself on mount.
const activeTab = ref('work');
</script>

<template>
    <Head :title="`${contact.name} — Employee Profile`" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <div class="flex items-center gap-3">
                <Link
                    :href="route('contacts.show', { contact: contact.id })"
                    class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-ink-800"
                >
                    <ArrowLeft :size="16" />
                    Back to contact
                </Link>
            </div>

            <div class="mt-2 flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">{{ contact.name }}</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">Employee profile</p>
                </div>

                <span
                    v-if="contact.is_active"
                    class="inline-flex rounded-full bg-brand-500/15 px-2.5 py-1 font-sans text-xs font-medium text-ink-800"
                >
                    Active
                </span>
                <span v-else class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 font-sans text-xs font-medium text-slate-500">
                    Inactive
                </span>
            </div>

            <div class="mt-6">
                <EmployeeTabs :contact-id="contact.id" :active="activeTab" @change="activeTab = $event" />
            </div>

            <div class="mt-6">
                <WorkTab
                    v-if="activeTab === 'work'"
                    :contact="contact"
                    :work-schedule="workSchedule"
                    :departments="departments"
                    :job-positions="jobPositions"
                    :possible-managers="possibleManagers"
                />

                <ResumeTab
                    v-else-if="activeTab === 'resume'"
                    :contact="contact"
                    :all-skills="allSkills"
                />

                <PersonalTab
                    v-else-if="activeTab === 'personal'"
                    :contact="contact"
                />

                <BadgesTab
                    v-else-if="activeTab === 'badges'"
                    :contact="contact"
                    :all-badges="allBadges"
                />

                <HistoryTab
                    v-else-if="activeTab === 'history'"
                    :contact="contact"
                />
            </div>
        </div>
    </DashboardLayout>
</template>