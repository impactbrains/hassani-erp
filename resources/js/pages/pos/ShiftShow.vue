<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import ShiftReportView from '@/components/pos/ShiftReportView.vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ shift: any; report: any; canReview: boolean }>();
const review = useForm({ note: '' });
function print() {
    window.print();
}
</script>

<template>
    <PageHeader :title="`${t('Shift')} ${shift.number}`" :subtitle="`${shift.register?.name} · ${shift.opener?.name}`">
        <template #actions>
            <Badge :status="shift.status" />
            <Button @click="print"><Printer class="h-4 w-4" />{{ t('Print') }}</Button>
            <Button :href="route('pos.shifts.index')">{{ t('All shifts') }}</Button>
        </template>
    </PageHeader>
    <section v-if="shift.status === 'closed' && Number(shift.variance) !== 0" class="panel mb-5 flex flex-wrap items-center gap-4 p-4">
        <div class="text-lg font-semibold" :class="Number(shift.variance) < 0 ? 'text-ledger-red' : 'text-haldi-700'">{{ t('Short / over') }}: {{ money(shift.variance) }}</div>
        <Badge :status="shift.variance_status" />
        <p v-if="shift.variance_note" class="text-sm text-ink-600">{{ shift.variance_note }}</p>
        <form v-if="canReview" class="ms-auto flex flex-1 items-end gap-2" @submit.prevent="review.post(route('pos.shifts.review', props.shift.id))">
            <label class="flex-1"><span class="field-label">{{ t('Review note (what happened)') }}</span><input v-model="review.note" class="input" required minlength="5" /></label>
            <Button type="submit" variant="primary" :loading="review.processing">{{ t('Mark reviewed') }}</Button>
        </form>
    </section>
    <section class="panel p-4"><ShiftReportView :report="report" :kind="shift.status === 'closed' ? 'Z' : 'X'" :variance="shift.variance" /></section>
</template>
