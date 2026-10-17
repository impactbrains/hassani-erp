<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { X } from 'lucide-vue-next';

const props = withDefaults(defineProps<{ open: boolean; title?: string; size?: 'sm' | 'md' | 'lg' | 'xl'; dismissable?: boolean }>(), { size: 'md', dismissable: true });
const emit = defineEmits<{ close: [] }>();

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && props.open && props.dismissable) emit('close');
}
watch(() => props.open, (open) => (open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)), { immediate: true });
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="no-print fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/50 p-4 pt-[8vh]" @mousedown.self="dismissable && emit('close')">
            <div role="dialog" aria-modal="true" class="w-full rounded-lg bg-white shadow-pop" :class="{ sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }[size]">
                <div v-if="title" class="flex items-center justify-between border-b border-ink-100 px-5 py-3">
                    <h2>{{ title }}</h2>
                    <button v-if="dismissable" type="button" class="rounded p-1 text-ink-400 hover:bg-ink-50 hover:text-ink-700" :aria-label="t('Close')" @click="emit('close')"><X class="h-5 w-5" /></button>
                </div>
                <div class="px-5 py-4"><slot /></div>
                <div v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-ink-100 bg-ink-50/40 px-5 py-3"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
