<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { CheckCircle2, AlertTriangle, X, Copy } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';

/** Success/error messages after an action, and one-time secrets (temporary passwords, API keys). */
const page = usePage<any>();
const toasts = ref<{ id: number; kind: 'success' | 'error'; text: string }[]>([]);
const secret = ref<{ label: string; value: string; note?: string } | null>(null);
const copied = ref(false);
let seq = 0;

function push(kind: 'success' | 'error', text: string) {
    const id = ++seq;
    toasts.value.push({ id, kind, text });
    setTimeout(() => (toasts.value = toasts.value.filter((x) => x.id !== id)), kind === 'error' ? 9000 : 4500);
}

watch(() => page.props.flash, (flash: any) => {
    if (flash?.success) push('success', flash.success);
    if (flash?.error) push('error', flash.error);
    if (flash?.secret) { secret.value = flash.secret; copied.value = false; }
}, { immediate: true, deep: true });

async function copy() {
    await navigator.clipboard.writeText(secret.value?.value ?? '');
    copied.value = true;
}
defineExpose({ push });
</script>

<template>
    <div class="no-print pointer-events-none fixed bottom-4 end-4 z-[60] flex w-[min(26rem,calc(100vw-2rem))] flex-col gap-2" aria-live="polite">
        <div v-for="toast in toasts" :key="toast.id" class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-white p-3 shadow-pop" :class="toast.kind === 'error' ? 'border-ledger-red/30' : 'border-ledger-green/30'">
            <CheckCircle2 v-if="toast.kind === 'success'" class="mt-0.5 h-5 w-5 shrink-0 text-ledger-green" />
            <AlertTriangle v-else class="mt-0.5 h-5 w-5 shrink-0 text-ledger-red" />
            <p class="flex-1 text-sm">{{ toast.text }}</p>
            <button class="text-ink-400 hover:text-ink-700" @click="toasts = toasts.filter((x) => x.id !== toast.id)"><X class="h-4 w-4" /></button>
        </div>
    </div>
    <Modal :open="!!secret" :title="secret?.label" :dismissable="false" @close="secret = null">
        <p class="mb-3 text-sm text-ink-600">{{ secret?.note }}</p>
        <div class="flex items-center gap-2 rounded border border-haldi-300 bg-haldi-50 p-3">
            <code class="flex-1 select-all break-all font-semibold tracking-wide text-ink-900">{{ secret?.value }}</code>
            <Button size="sm" @click="copy"><Copy class="h-4 w-4" />{{ copied ? 'Copied' : 'Copy' }}</Button>
        </div>
        <template #footer><Button variant="primary" @click="secret = null">I have saved it</Button></template>
    </Modal>
</template>
