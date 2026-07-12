<script setup lang="ts">
defineProps<{
    contactId: number;
    active: string;
}>();

const tabs = [
    { key: 'work', label: 'Work' },
    { key: 'resume', label: 'Resume' },
    { key: 'personal', label: 'Personal' },
    { key: 'badges', label: 'Badges' },
    { key: 'history', label: 'History' },
];

// EmployeeTabs is rendered inside contacts/employee/Show.vue, which
// controls which tab's content is mounted via its own local `activeTab`
// ref (see Show.vue) rather than separate page loads per tab — Work,
// Resume, Personal, and Badges all come from the same Inertia payload
// (see EmployeeController::show), so there's no route change between
// them. History is the exception: it lazy-fetches its own data on
// mount, but still lives on the same page/URL as the others.
//
// This mirrors ProductsTabs conceptually (a row of tab links) but emits
// a local event instead of navigating, since unlike Products' category
// tabs, these aren't separate index queries — they're views over data
// that's already loaded.
defineEmits<{
    (e: 'change', key: string): void;
}>();
</script>

<template>
    <div class="flex gap-1 border-b border-slate-200">
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            @click="$emit('change', tab.key)"
            class="relative px-4 py-2.5 font-sans text-sm font-medium transition-colors"
            :class="active === tab.key
                ? 'text-ink-900'
                : 'text-slate-500 hover:text-ink-800'"
        >
            {{ tab.label }}
            <span
                v-if="active === tab.key"
                class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-brand-500"
            />
        </button>
    </div>
</template>