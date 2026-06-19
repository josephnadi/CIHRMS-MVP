<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ from: String, to: String, report: { type: Object, required: true } });
const from = ref(props.from); const to = ref(props.to);
const method = ref('direct');
const apply = () => router.get(route('finance.reports.cash-flow'), { from: from.value, to: to.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>
<template>
    <Head title="Statement of Cash Flows" />
    <div class="p-6 max-w-3xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Statement of Cash Flows</h1>
                <p class="text-on-surface-variant text-sm mt-1">{{ from }} → {{ to }}</p>
                <nav class="mt-2 flex gap-3 text-xs font-bold">
                    <Link :href="route('finance.reports.trial-balance')" class="text-on-surface-variant hover:text-secondary">Trial Balance</Link>
                    <Link :href="route('finance.reports.financial-activities')" class="text-on-surface-variant hover:text-secondary">Financial Activities</Link>
                    <Link :href="route('finance.reports.financial-position')" class="text-on-surface-variant hover:text-secondary">Financial Position</Link>
                    <Link :href="route('finance.reports.cash-flow')" class="text-secondary">Cash Flows</Link>
                </nav>
            </div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>From <input type="date" v-model="from" aria-label="From date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <label>To <input type="date" v-model="to" aria-label="To date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.reports.cash-flow.csv', { from, to })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
            </div>
        </header>

        <div class="mb-4 inline-flex rounded-lg border border-outline-variant/60 overflow-hidden text-sm font-bold">
            <button @click="method = 'direct'" :class="method === 'direct' ? 'bg-secondary/20 text-secondary' : 'text-on-surface-variant'" class="px-4 py-2">Direct</button>
            <button @click="method = 'indirect'" :class="method === 'indirect' ? 'bg-secondary/20 text-secondary' : 'text-on-surface-variant'" class="px-4 py-2">Indirect</button>
        </div>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
            <template v-if="method === 'direct'">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-primary">Operating activities</span><span class="text-primary">{{ money(report.direct.operating) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Investing activities</span><span class="text-primary">{{ money(report.direct.investing) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Financing activities</span><span class="text-primary">{{ money(report.direct.financing) }}</span></div>
                </div>
            </template>
            <template v-else>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-primary">Surplus / (Deficit)</span><span class="text-primary">{{ money(report.indirect.surplus) }}</span></div>
                    <div class="flex justify-between text-on-surface-variant"><span>Δ Liabilities</span><span>{{ money(report.indirect.liability_change) }}</span></div>
                    <div class="flex justify-between text-on-surface-variant"><span>Δ Receivables</span><span>{{ money(-report.indirect.ar_change) }}</span></div>
                    <div class="flex justify-between border-t border-outline-variant/40 pt-2"><span class="text-primary">Operating activities</span><span class="text-primary">{{ money(report.indirect.operating) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Investing activities</span><span class="text-primary">{{ money(report.indirect.investing) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Financing activities</span><span class="text-primary">{{ money(report.indirect.financing) }}</span></div>
                </div>
            </template>
            <div class="flex justify-between border-t border-outline-variant/60 mt-3 pt-3 font-black text-primary">
                <span>Net change in cash</span><span>{{ money(report.net_change) }}</span>
            </div>
            <p class="mt-2 text-[11px] text-emerald-300">
                ✓ Direct {{ money(report.direct.net) }} = Indirect {{ money(report.indirect.net) }} = Net change {{ money(report.net_change) }}
            </p>
        </div>
    </div>
</template>
