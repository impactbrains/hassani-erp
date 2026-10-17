<script setup lang="ts">
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import { date, money } from '@/lib/format';
import { localName, t } from '@/lib/i18n';

const props = defineProps<{ account: any; opening: string; lines: any[]; closing: string; filters: { from: string; to: string } }>();
const f = reactive({ ...props.filters });
function print() {
    window.print();
}
function apply() {
    router.get(route('accounting.accounts.show', props.account.id), f, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <PageHeader :title="`${account.code} · ${localName(account)}`" :subtitle="t('Ledger: every entry on this account.')">
        <template #actions><Button @click="print"><Printer class="h-4 w-4" />{{ t('Print') }}</Button><Button :href="route('accounting.accounts.index')">{{ t('All accounts') }}</Button></template>
    </PageHeader>
    <div class="panel no-print mb-4 flex flex-wrap items-end gap-3 p-3">
        <label><span class="field-label">{{ t('From') }}</span><input v-model="f.from" type="date" class="input" /></label>
        <label><span class="field-label">{{ t('To') }}</span><input v-model="f.to" type="date" class="input" /></label>
        <Button @click="apply">{{ t('Show') }}</Button>
    </div>
    <section class="panel">
        <table class="table-base">
            <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Entry') }}</th><th>{{ t('Details') }}</th><th>{{ t('Party') }}</th><th class="num">{{ t('Debit') }}</th><th class="num">{{ t('Credit') }}</th><th class="num">{{ t('Balance') }}</th></tr></thead>
            <tbody>
                <tr class="bg-ink-50/50"><td>{{ date(filters.from) }}</td><td></td><td class="font-medium">{{ t('Opening balance') }}</td><td></td><td></td><td></td><td class="num font-semibold">{{ money(opening) }}</td></tr>
                <tr v-for="(l, i) in lines" :key="i">
                    <td class="whitespace-nowrap">{{ date(l.entry_date) }}</td><td><a :href="route('accounting.journals.show', l.entry_id)" class="link">{{ l.number }}</a></td>
                    <td>{{ l.description }}<div v-if="l.memo && l.memo !== l.description" class="text-[12px] text-ink-500">{{ l.memo }}</div></td><td>{{ l.party ?? '' }}<div v-if="l.store" class="text-[12px] text-ink-500">{{ l.store }}</div></td>
                    <td class="num">{{ Number(l.debit) ? money(l.debit) : '' }}</td><td class="num">{{ Number(l.credit) ? money(l.credit) : '' }}</td><td class="num">{{ money(l.balance) }}</td>
                </tr>
                <tr class="bg-ink-50/50"><td>{{ date(filters.to) }}</td><td></td><td class="font-medium">{{ t('Closing balance') }}</td><td></td><td></td><td></td><td class="num text-base font-semibold">{{ money(closing) }}</td></tr>
            </tbody>
        </table>
        <p v-if="lines.length >= 2000" class="border-t border-ink-100 p-3 text-sm text-haldi-800">{{ t('Showing the first 2,000 entries. Choose a shorter period, or use Reports → General ledger to download everything.') }}</p>
    </section>
</template>
