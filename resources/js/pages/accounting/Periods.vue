<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import { date, dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

defineProps<{ periods: any[]; canManage: boolean }>();
const reopening = ref<any>(null);
const reason = ref('');
function toggle(p: any, why: string | null = null) {
    router.post(route('accounting.periods.toggle', p.id), { reason: why }, { preserveScroll: true, onSuccess: () => { reopening.value = null; reason.value = ''; } });
}
</script>

<template>
    <PageHeader :title="t('Accounting periods')" :subtitle="t('Close a month once its figures are checked. Nothing can then be posted into it, so reports for that month never change.')" />
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Month') }}</th><th>{{ t('Year') }}</th><th>{{ t('From') }}</th><th>{{ t('To') }}</th><th>{{ t('Status') }}</th><th>{{ t('Closed') }}</th><th></th></tr></thead>
            <tbody>
                <tr v-for="p in periods" :key="p.id">
                    <td class="font-medium">{{ p.name }}</td><td>{{ p.fiscal_year?.name }}</td><td>{{ date(p.starts_on) }}</td><td>{{ date(p.ends_on) }}</td><td><Badge :status="p.status" /></td><td class="text-[12.5px] text-ink-500">{{ p.closed_at ? dateTime(p.closed_at) : '' }}</td>
                    <td><template v-if="canManage"><Button v-if="p.status === 'open'" size="sm" @click="toggle(p)">{{ t('Close month') }}</Button><Button v-else size="sm" variant="danger" @click="reopening = p">{{ t('Reopen') }}</Button></template></td>
                </tr>
            </tbody>
        </table>
    </section>
    <Modal :open="!!reopening" :title="`${t('Reopen')} ${reopening?.name ?? ''}`" size="sm" @close="reopening = null">
        <label class="block"><span class="field-label">{{ t('Why does it need to be reopened?') }}</span><input v-model="reason" class="input" /></label>
        <template #footer><Button @click="reopening = null">{{ t('Cancel') }}</Button><Button variant="danger" :disabled="!reason" @click="toggle(reopening, reason)">{{ t('Reopen') }}</Button></template>
    </Modal>
</template>
