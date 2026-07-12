<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { Plus, Pencil, Trash2, Search } from 'lucide-vue-next';

interface Assignee {
    id: number;
    name: string;
}

interface TaskRow {
    id: number;
    title: string;
    status: 'to_do' | 'in_progress' | 'done' | 'cancelled';
    priority: 'low' | 'medium' | 'high';
    due_date: string | null;
    due_time: string | null;
    assignees: Assignee[];
    created_by: Assignee | null;
    can_manage: boolean;
    can_update_status: boolean;
}

const props = defineProps<{
    tasks: {
        data: TaskRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { status?: string; priority?: string; assigned_to?: string; search?: string };
    employees: Assignee[];
    canManage: boolean;
    canCreateOwn: boolean;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const priority = ref(props.filters.priority ?? '');
const assignedTo = ref(props.filters.assigned_to ?? '');

let debounceTimer: ReturnType<typeof setTimeout>;

watch([search, status, priority, assignedTo], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('todo.index'),
            {
                search: search.value || undefined,
                status: status.value || undefined,
                priority: priority.value || undefined,
                assigned_to: assignedTo.value || undefined,
            },
            { preserveState: true, replace: true }
        );
    }, 300);
});

const statusBadgeClass: Record<string, string> = {
    to_do: 'bg-slate-100 text-slate-600',
    in_progress: 'bg-amber-100 text-amber-700',
    done: 'bg-brand-500/15 text-ink-800',
    cancelled: 'bg-danger-500/15 text-danger-500',
};

const priorityBadgeClass: Record<string, string> = {
    low: 'bg-slate-100 text-slate-500',
    medium: 'bg-amber-100 text-amber-700',
    high: 'bg-danger-500/15 text-danger-500',
};

function updateStatus(task: TaskRow, newStatus: string) {
    router.patch(route('todo.status', { task: task.id }), { status: newStatus }, { preserveState: true, preserveScroll: true });
}

function destroy(task: TaskRow) {
    if (!confirm(`Delete "${task.title}"?`)) return;
    router.delete(route('todo.destroy', { task: task.id }));
}

function isOverdue(task: TaskRow): boolean {
    if (!task.due_date || task.status === 'done' || task.status === 'cancelled') return false;
    return new Date(task.due_date) < new Date(new Date().toDateString());
}

function formatDue(task: TaskRow): string {
    if (!task.due_date) return '—';
    const date = new Date(task.due_date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    return task.due_time ? `${date}, ${task.due_time.slice(0, 5)}` : date;
}
</script>

<template>
    <Head title="Tasks" />

    <DashboardLayout>
        <div class="mx-auto max-w-5xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-2xl font-bold text-ink-900">Tasks</h1>
                    <p class="mt-1 font-sans text-sm text-slate-500">Staff to-dos and assignments.</p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        v-if="canCreateOwn"
                        :href="route('todo.create-own')"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-slate-50"
                    >
                        <Plus :size="16" /> New task for me
                    </Link>
                    <Link
                        v-if="canManage"
                        :href="route('todo.create')"
                        class="flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400"
                    >
                        <Plus :size="16" /> New task
                    </Link>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-[200px]">
                    <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search tasks..."
                        class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <select v-model="status" class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All statuses</option>
                    <option value="to_do">To Do</option>
                    <option value="in_progress">In Progress</option>
                    <option value="done">Done</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <select v-model="priority" class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All priorities</option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>

                <select v-model="assignedTo" class="rounded-lg border-slate-200 py-2 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Everyone</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </div>

            <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left font-sans text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Task</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Assigned To</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Priority</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Due</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="task in tasks.data" :key="task.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-ink-900">{{ task.title }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ task.assignees.map(a => a.name).join(', ') || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="priorityBadgeClass[task.priority]">
                                    {{ task.priority }}
                                </span>
                            </td>
                            <td class="px-4 py-3" :class="isOverdue(task) ? 'font-semibold text-danger-500' : 'text-slate-500'">
                                {{ formatDue(task) }}
                            </td>
                            <td class="px-4 py-3">
                                <select
                                    v-if="task.can_update_status"
                                    :value="task.status"
                                    @change="updateStatus(task, ($event.target as HTMLSelectElement).value)"
                                    class="rounded-full border-0 py-0.5 pl-2 pr-7 text-xs font-medium focus:ring-brand-500"
                                    :class="statusBadgeClass[task.status]"
                                >
                                    <option value="to_do">To Do</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="done">Done</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                                <span v-else class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass[task.status]">
                                    {{ task.status.replace('_', ' ') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="task.can_manage" class="flex items-center justify-end gap-3">
                                    <Link :href="route('todo.edit', { task: task.id })" class="text-slate-400 hover:text-ink-800" title="Edit">
                                        <Pencil :size="16" />
                                    </Link>
                                    <button @click="destroy(task)" class="text-slate-400 hover:text-danger-500" title="Delete">
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="tasks.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">No tasks found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="tasks.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="link in tasks.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    class="rounded-lg px-3 py-1.5 font-sans text-sm"
                    :class="link.active ? 'bg-ink-900 text-white' : 'text-slate-500 hover:bg-slate-100'"
                    preserve-state
                >
                    <span v-html="link.label" />
                </Link>
            </div>
        </div>
    </DashboardLayout>
</template>