<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft } from 'lucide-vue-next';

interface Employee {
    id: number;
    name: string;
}

interface TaskData {
    id: number;
    title: string;
    description: string | null;
    status: 'to_do' | 'in_progress' | 'done' | 'cancelled';
    priority: 'low' | 'medium' | 'high';
    due_date: string | null;
    due_time: string | null;
    assignees: Employee[];
}

const props = defineProps<{
    task: TaskData | null;
    employees: Employee[];
}>();

const isEditing = props.task !== null;

const form = useForm({
    title: props.task?.title ?? '',
    description: props.task?.description ?? '',
    status: props.task?.status ?? 'to_do',
    priority: props.task?.priority ?? 'medium',
    due_date: props.task?.due_date ?? '',
    due_time: props.task?.due_time?.slice(0, 5) ?? '',
    assignee_ids: props.task?.assignees.map((a) => a.id) ?? [],
});

function submit() {
    if (isEditing) {
        form.put(route('todo.update', { task: props.task!.id }));
    } else {
        form.post(route('todo.store'));
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edit Task' : 'New Task'" />

    <DashboardLayout>
        <div class="mx-auto max-w-2xl">
            <Link :href="route('todo.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to Tasks
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">
                {{ isEditing ? 'Edit Task' : 'New Task' }}
            </h1>

            <form @submit.prevent="submit" class="mt-6 space-y-5 rounded-lg border border-slate-200 bg-white p-5">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Title</label>
                    <input
                        v-model="form.title"
                        type="text"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                    <p v-if="form.errors.title" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Description</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="font-sans text-sm font-medium text-slate-500">Priority</label>
                        <select v-model="form.priority" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>

                    <div v-if="isEditing">
                        <label class="font-sans text-sm font-medium text-slate-500">Status</label>
                        <select v-model="form.status" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="to_do">To Do</option>
                            <option value="in_progress">In Progress</option>
                            <option value="done">Done</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="font-sans text-sm font-medium text-slate-500">Due Date</label>
                        <input
                            v-model="form.due_date"
                            type="date"
                            class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>
                    <div>
                        <label class="font-sans text-sm font-medium text-slate-500">Due Time</label>
                        <input
                            v-model="form.due_time"
                            type="time"
                            class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>
                </div>

                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Assign To</label>
                    <div class="mt-1 max-h-48 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                        <label
                            v-for="employee in employees"
                            :key="employee.id"
                            class="flex items-center gap-2 rounded px-2 py-1.5 font-sans text-sm hover:bg-slate-50"
                        >
                            <input
                                type="checkbox"
                                :value="employee.id"
                                v-model="form.assignee_ids"
                                class="rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                            />
                            {{ employee.name }}
                        </label>
                        <p v-if="employees.length === 0" class="px-2 py-1.5 font-sans text-sm text-slate-400">
                            No active employees found.
                        </p>
                    </div>
                    <p v-if="form.errors.assignee_ids" class="mt-1 font-sans text-xs text-danger-500">{{ form.errors.assignee_ids }}</p>
                </div>

                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                    >
                        {{ isEditing ? 'Save Changes' : 'Create Task' }}
                    </button>
                    <Link :href="route('todo.index')" class="rounded-lg px-4 py-2 font-sans text-sm text-slate-500 hover:bg-slate-100">
                        Cancel
                    </Link>
                </div>
            </form>
        </div>
    </DashboardLayout>
</template>