<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import * as Icons from 'lucide-vue-next';

const Award = Icons.Award;
const Trash2 = Icons.Trash2;

interface Badge {
    id: number;
    name: string;
    icon: string | null;
    color: string | null;
    pivot?: { awarded_at: string | null };
}

const props = defineProps<{
    contact: { id: number; badges: Badge[] };
    allBadges: Badge[];
}>();

const picking = ref(false);
const form = useForm({ badge_id: null as number | null });

function availableBadges() {
    return props.allBadges.filter((b) => !props.contact.badges.some((cb) => cb.id === b.id));
}

function attach() {
    if (!form.badge_id) return;
    form.post(route('contacts.employee.badges.attach', { contact: props.contact.id }), {
        preserveScroll: true,
        onSuccess: () => {
            picking.value = false;
            form.reset();
        },
    });
}

function remove(badge: Badge) {
    if (!confirm(`Remove the "${badge.name}" badge?`)) return;
    router.delete(
        route('contacts.employee.badges.detach', { contact: props.contact.id, badge: badge.id }),
        { preserveScroll: true }
    );
}

// Badge icons are stored as a Lucide icon name string (e.g. "Award",
// "Star") — this looks it up dynamically from the full icon set.
// Falls back to a generic Award icon if the stored name doesn't match
// any known Lucide export (e.g. renamed/removed icon after a package
// upgrade), rather than rendering nothing.
function resolveIcon(iconName: string | null) {
    if (!iconName) return Award;
    return (Icons as Record<string, any>)[iconName] ?? Award;
}
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex items-center justify-between">
            <h2 class="font-display text-base font-semibold text-ink-900">Badges</h2>
            <button
                v-if="!picking"
                type="button"
                @click="picking = true"
                class="rounded-lg bg-slate-100 px-3 py-1.5 font-sans text-sm font-medium text-ink-800 hover:bg-slate-200"
            >
                Award a badge
            </button>
        </div>

        <div v-if="picking" class="mt-4 flex gap-2">
            <select v-model="form.badge_id" class="flex-1 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                <option :value="null">Choose a badge...</option>
                <option v-for="b in availableBadges()" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <button type="button" @click="attach" :disabled="form.processing" class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50">
                Award
            </button>
            <button type="button" @click="picking = false" class="rounded-lg px-4 py-2 font-sans text-sm text-slate-500 hover:bg-slate-100">
                Cancel
            </button>
        </div>

        <div v-if="contact.badges.length" class="mt-4 grid grid-cols-3 gap-3">
            <div
                v-for="badge in contact.badges"
                :key="badge.id"
                class="flex flex-col items-center gap-2 rounded-lg border border-slate-100 bg-slate-50 p-4 text-center"
            >
                <component :is="resolveIcon(badge.icon)" :size="28" :style="{ color: badge.color ?? undefined }" class="text-brand-500" />
                <p class="font-sans text-sm font-medium text-ink-900">{{ badge.name }}</p>
                <button type="button" @click="remove(badge)" class="text-slate-400 hover:text-danger-500">
                    <Trash2 :size="14" />
                </button>
            </div>
        </div>
        <p v-else class="mt-4 font-sans text-sm text-slate-400">No badges awarded yet.</p>
    </div>
</template>