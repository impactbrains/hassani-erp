<script setup lang="ts">
import { Trash2 } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import { t } from '@/lib/i18n';

/** Role assignments: a role, optionally limited to one store and until a date (temporary cover). */
const props = defineProps<{ modelValue: any[]; roles: any[]; stores: any[]; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [any[]] }>();
function add() {
    emit('update:modelValue', [...props.modelValue, { role_id: props.roles.find((r) => !r.is_owner)?.id ?? null, store_id: null, expires_at: '', reason: '' }]);
}
function remove(i: number) {
    emit('update:modelValue', props.modelValue.filter((_, n) => n !== i));
}
</script>

<template>
    <div class="space-y-2">
        <div v-for="(r, i) in modelValue" :key="i" class="grid items-end gap-2 md:grid-cols-[1fr_1fr_10rem_auto]">
            <label><span class="field-label">{{ t('Role') }}</span><select v-model="r.role_id" class="input" :disabled="disabled"><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
            <label><span class="field-label">{{ t('Store') }}</span><select v-model="r.store_id" class="input" :disabled="disabled"><option :value="null">{{ t('All stores') }}</option><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
            <label><span class="field-label">{{ t('Until (optional)') }}</span><input v-model="r.expires_at" type="date" class="input" :disabled="disabled" /></label>
            <button v-if="!disabled" type="button" class="mb-2 text-ink-400 hover:text-ledger-red" :aria-label="t('Remove')" @click="remove(i)"><Trash2 class="h-4 w-4" /></button>
        </div>
        <p v-if="!disabled" class="text-[12.5px] text-ink-500">{{ roles.map((r) => `${r.name}: ${r.description ?? ''}`).join(' · ') }}</p>
        <Button v-if="!disabled" size="sm" @click="add">{{ t('Add role') }}</Button>
    </div>
</template>
