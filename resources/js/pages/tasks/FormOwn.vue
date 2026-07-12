<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import { ArrowLeft } from 'lucide-vue-next';

// Deliberately no `employees` prop and no assignee picker at all — this
// form always creates a task assigned only to the current user. The
// server (TaskController::storeSelf) enforces this independently of
// what this form sends, so there's no real security reliance on the
// UI simply "not showing" the option — but it's still hidden here
// since assigning to yourself is the whole point of this simpler form.
const form = useForm({
    title: '',
    description: '',
    priority: 'medium' as 'low' | 'medium' | 'high',
    due_date: '',
    due_time: '',
});

function submit() {
    form.post(route('todo.store-own'));
}
</script>

<template>
    <Head title="New Task" />

    <DashboardLayout>
        <div class="mx-auto max-w-2xl">
            <Link :href="route('todo.index')" class="flex items-center gap-1.5 font-sans text-sm text-slate-500 hover:underline">
                <ArrowLeft :size="14" /> Back to Tasks
            </Link>

            <h1 class="mt-2 font-display text-2xl font-bold text-ink-900">New Task</h1>
            <p class="mt-1 font-sans text-sm text-slate-500">This will be assigned to you.</p>

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

                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Priority</label>
                    <select v-model="form.priority" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
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

                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
                    >
                        Create Task
                    </button>
                    <Link :href="route('todo.index')" class="rounded-lg px-4 py-2 font-sans text-sm text-slate-500 hover:bg-slate-100">
                        Cancel
                    </Link>
                </div>
            </form>
        </div>
    </DashboardLayout>
</template>