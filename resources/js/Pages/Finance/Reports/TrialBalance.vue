<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    asOf:   { type: String, required: true },
    report: { type: Object, required: true },
});

const asOf = ref(props.asOf);
const apply = () => router.get(route('finance.reports.trial-balance'), { as_of: asOf.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const csvHref = computed(() => route('finance.reports.trial-balance.csv', { as_of: props.asOf }));
</script>

<template>
    <Head title="Trial Balance" />

    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Trial Balance</h1>
                <p class="text-on-surface-variant text-sm mt-1">As of {{ asOf }}</p>
                <nav class="mt-2 flex gap-3 text-xs font-bold">
                    <Link :href="route('finance.reports.trial-balance')" class="text-secondary">Trial Balance</Link>
                    <Link :href="route('finance.reports.financial-activities')" class="text-on-surface-variant hover:text-secondary">Financial Activities</Link>
                    <Link :href="route('finance.reports.financial-position')" class="text-on-surface-variant hover:text-secondary">Financial Position</Link>
                    <Link :href="route('finance.reports.cash-flow')" class="text-on-surface-variant hover:text-secondary">Cash Flows</Link>
                </nav>
            </div>
            <div class="flex items-end gap-3">
                <label class="text-xs font-bold text-on-surface-variant">As of
                    <input type="date" v-model="asOf" aria-label="As-of date"
                           class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm font-bold text-secondary">Apply</button>
                <a :href="csvHref" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm font-bold text-primary">CSV</a>
                <a :href="route('finance.reports.trial-balance.pdf', { as_of: props.asOf })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm font-bold text-primary">PDF</a>
            </div>
        </header>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[11px] uppercase tracking-wide border-b border-outline-variant/40">
                    <tr>
                        <th class="text-left p-3">Code</th>
                        <th class="text-left p-3">Account</th>
                        <th class="text-right p-3">Debit</th>
                        <th class="text-right p-3">Credit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="row in report.rows" :key="row.code">
                        <td class="p-3 font-mono text-on-surface-variant">{{ row.code }}</td>
                        <td class="p-3 text-primary">{{ row.name }}</td>
                        <td class="p-3 text-right text-primary">{{ row.debit ? money(row.debit) : '' }}</td>
                        <td class="p-3 text-right text-primary">{{ row.credit ? money(row.credit) : '' }}</td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td colspan="4" class="p-6 text-center text-on-surface-variant">No postings as of this date.</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-outline-variant/60 font-black">
                    <tr>
                        <td class="p-3" colspan="2">Total</td>
                        <td class="p-3 text-right" :class="report.balanced ? 'text-primary' : 'text-amber-700 dark:text-amber-400'">{{ money(report.total_debit) }}</td>
                        <td class="p-3 text-right" :class="report.balanced ? 'text-primary' : 'text-amber-700 dark:text-amber-400'">{{ money(report.total_credit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-if="!report.balanced" class="mt-3 text-amber-700 dark:text-amber-400 text-sm font-bold">
            ⚠ Trial balance is out of balance — investigate the ledger.
        </p>
    </div>
</template>
