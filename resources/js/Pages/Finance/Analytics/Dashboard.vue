<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChartCard from '@/Components/charts/ChartCard.vue';
import LineChart from '@/Components/charts/ChartJs/LineChart.vue';
import BarChart from '@/Components/charts/ChartJs/BarChart.vue';
import DoughnutChart from '@/Components/charts/ChartJs/DoughnutChart.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:   { type: Number },
    from:   { type: String },
    to:     { type: String },
    kpis:   { type: Object,  default: () => ({}) },
    trends: { type: Object,  default: () => ({}) },
});

// ── filter bar ───────────────────────────────────────────────────────────────
const year = ref(props.year);
const from = ref(props.from);
const to   = ref(props.to);

const apply = () => router.get(route('finance.analytics'),
    { year: year.value, from: from.value, to: to.value }, { preserveState: false });

const exportParams = computed(() => ({ year: year.value, from: from.value, to: to.value }));

// ── formatting ───────────────────────────────────────────────────────────────
const fmt = new Intl.NumberFormat('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const money = (v) => 'GHS ' + fmt.format(Number(v) || 0);
const signClass = (v) => (Number(v) >= 0 ? 'text-emerald-600' : 'text-rose-600');

const kpiCards = computed(() => [
    { key: 'cash_position',       label: 'Cash Position',        signed: false },
    { key: 'income_ytd',          label: 'Income YTD',           signed: false },
    { key: 'expenditure_ytd',     label: 'Expenditure YTD',      signed: false },
    { key: 'surplus_ytd',         label: 'Surplus YTD',          signed: true },
    { key: 'ap_outstanding',      label: 'AP Outstanding',       signed: false },
    { key: 'ar_outstanding',      label: 'AR Outstanding',       signed: false },
    { key: 'budget_variance',     label: 'Budget Variance',      signed: true },
    { key: 'latest_payroll_cost', label: 'Latest Payroll Cost',  signed: false },
].map((c) => {
    const value = Number(props.kpis?.[c.key] ?? 0);
    return { ...c, value, display: money(value), cls: c.signed ? signClass(value) : 'text-primary' };
}));

// ── chart palette ────────────────────────────────────────────────────────────
const C = {
    blue:   '#3949ab',
    emerald:'#10b981',
    rose:   '#f43f5e',
    amber:  '#f59e0b',
    cyan:   '#06b6d4',
    violet: '#8b5cf6',
    slate:  '#64748b',
};

const months = computed(() => props.trends?.months ?? []);

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: true, labels: { boxWidth: 12, font: { size: 11 } } } },
    scales: { y: { beginAtZero: true, ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 } } } },
};

// 1. Income vs Expenditure
const incomeExpData = computed(() => ({
    labels: months.value,
    datasets: [
        { label: 'Income',      data: props.trends?.income ?? [],      backgroundColor: C.emerald },
        { label: 'Expenditure', data: props.trends?.expenditure ?? [], backgroundColor: C.rose },
    ],
}));

// 2. Surplus
const surplusData = computed(() => ({
    labels: months.value,
    datasets: [
        { label: 'Surplus', data: props.trends?.surplus ?? [], borderColor: C.blue,
          backgroundColor: 'rgba(57,73,171,0.12)', fill: true, tension: 0.3, pointRadius: 2 },
    ],
}));

// 3. Cash balance
const cashData = computed(() => ({
    labels: months.value,
    datasets: [
        { label: 'Cash', data: props.trends?.cash ?? [], borderColor: C.cyan,
          backgroundColor: 'rgba(6,182,212,0.12)', fill: true, tension: 0.3, pointRadius: 2 },
    ],
}));

// 4. AR & AP aging — grouped bar across buckets
const agingData = computed(() => {
    const ar = props.trends?.aging?.ar ?? {};
    const ap = props.trends?.aging?.ap ?? {};
    const pick = (o) => [Number(o.current ?? 0), Number(o.d30 ?? 0), Number(o.d60 ?? 0), Number(o.d90 ?? 0)];
    return {
        labels: ['Current', '1-30d', '31-60d', '61+d'],
        datasets: [
            { label: 'AR', data: pick(ar), backgroundColor: C.blue },
            { label: 'AP', data: pick(ap), backgroundColor: C.amber },
        ],
    };
});

// 4b. AR aging doughnut
const arAgingDoughnut = computed(() => {
    const ar = props.trends?.aging?.ar ?? {};
    return {
        labels: ['Current', '1-30d', '31-60d', '61+d'],
        datasets: [
            { data: [Number(ar.current ?? 0), Number(ar.d30 ?? 0), Number(ar.d60 ?? 0), Number(ar.d90 ?? 0)],
              backgroundColor: [C.emerald, C.amber, C.violet, C.rose] },
        ],
    };
});
const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } },
};

// 5. Budget vs Actuals
const budgetData = computed(() => {
    const rows = props.trends?.budget ?? [];
    return {
        labels: rows.map((r) => r.type),
        datasets: [
            { label: 'YTD Budget', data: rows.map((r) => Number(r.ytd_budget ?? 0)), backgroundColor: C.slate },
            { label: 'YTD Actual', data: rows.map((r) => Number(r.ytd_actual ?? 0)), backgroundColor: C.blue },
        ],
    };
});

// 6. Top expenses — horizontal
const topExpensesData = computed(() => {
    const rows = props.trends?.top_expenses ?? [];
    return {
        labels: rows.map((r) => r.name || r.code),
        datasets: [
            { label: 'Amount', data: rows.map((r) => Number(r.amount ?? 0)), backgroundColor: C.violet },
        ],
    };
});
const horizontalOptions = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, ticks: { font: { size: 10 } } }, y: { ticks: { font: { size: 10 } } } },
};

// ── PNG export ───────────────────────────────────────────────────────────────
const incomeExpRef = ref(null);
const surplusRef   = ref(null);
const cashRef      = ref(null);
const agingRef     = ref(null);
const budgetRef    = ref(null);
const topExpRef    = ref(null);

const downloadPng = (chartRef, name) => {
    const url = chartRef?.value?.toBase64Image?.();
    if (!url) return;
    const a = document.createElement('a');
    a.href = url;
    a.download = `${name}.png`;
    document.body.appendChild(a);
    a.click();
    a.remove();
};
</script>

<template>
    <Head title="Finance Analytics" />

    <div class="p-6 max-w-7xl mx-auto space-y-6">
        <!-- Header / filter bar -->
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Finance Analytics</h1>
                <p class="text-on-surface-variant text-sm mt-1">FY {{ year }} · {{ from }} → {{ to }}</p>
            </div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>Year
                    <input type="number" v-model.number="year" aria-label="Fiscal year"
                           class="mt-1 block w-24 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <label>From
                    <input type="date" v-model="from" aria-label="From date"
                           class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <label>To
                    <input type="date" v-model="to" aria-label="To date"
                           class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.analytics.csv', exportParams)" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
                <a :href="route('finance.analytics.pdf', exportParams)" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">PDF</a>
            </div>
        </header>

        <!-- KPI strip -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="c in kpiCards" :key="c.key"
                 class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">{{ c.label }}</p>
                <p class="mt-2 text-2xl font-black" :class="c.cls">{{ c.display }}</p>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid gap-6 lg:grid-cols-2">
            <ChartCard title="Income vs Expenditure" subtitle="Monthly" icon="bar_chart" accent="#3949ab">
                <template #footer>
                    <button type="button" @click="downloadPng(incomeExpRef, 'income-vs-expenditure')"
                            class="text-[11px] font-bold text-secondary hover:underline">PNG</button>
                </template>
                <div style="height:280px">
                    <BarChart ref="incomeExpRef" :data="incomeExpData" :options="baseOptions" />
                </div>
            </ChartCard>

            <ChartCard title="Surplus" subtitle="Monthly" icon="trending_up" accent="#3949ab">
                <template #footer>
                    <button type="button" @click="downloadPng(surplusRef, 'surplus')"
                            class="text-[11px] font-bold text-secondary hover:underline">PNG</button>
                </template>
                <div style="height:280px">
                    <LineChart ref="surplusRef" :data="surplusData" :options="baseOptions" />
                </div>
            </ChartCard>

            <ChartCard title="Cash Balance" subtitle="End of month" icon="account_balance" accent="#06b6d4">
                <template #footer>
                    <button type="button" @click="downloadPng(cashRef, 'cash-balance')"
                            class="text-[11px] font-bold text-secondary hover:underline">PNG</button>
                </template>
                <div style="height:280px">
                    <LineChart ref="cashRef" :data="cashData" :options="baseOptions" />
                </div>
            </ChartCard>

            <ChartCard title="AR &amp; AP Aging" subtitle="Outstanding by bucket" icon="hourglass_top" accent="#f59e0b">
                <template #footer>
                    <button type="button" @click="downloadPng(agingRef, 'ar-ap-aging')"
                            class="text-[11px] font-bold text-secondary hover:underline">PNG</button>
                </template>
                <div style="height:280px">
                    <BarChart ref="agingRef" :data="agingData" :options="baseOptions" />
                </div>
            </ChartCard>

            <ChartCard title="AR Aging Split" subtitle="Receivables" icon="donut_small" accent="#10b981">
                <div style="height:280px">
                    <DoughnutChart :data="arAgingDoughnut" :options="doughnutOptions" />
                </div>
            </ChartCard>

            <ChartCard title="Budget vs Actuals" subtitle="YTD by type" icon="monitoring" accent="#3949ab">
                <template #footer>
                    <button type="button" @click="downloadPng(budgetRef, 'budget-vs-actuals')"
                            class="text-[11px] font-bold text-secondary hover:underline">PNG</button>
                </template>
                <div style="height:280px">
                    <BarChart ref="budgetRef" :data="budgetData" :options="baseOptions" />
                </div>
            </ChartCard>

            <ChartCard title="Top Expenses" subtitle="By account" icon="leaderboard" accent="#8b5cf6" class="lg:col-span-2">
                <template #footer>
                    <button type="button" @click="downloadPng(topExpRef, 'top-expenses')"
                            class="text-[11px] font-bold text-secondary hover:underline">PNG</button>
                </template>
                <div style="height:320px">
                    <BarChart ref="topExpRef" :data="topExpensesData" :options="horizontalOptions" />
                </div>
            </ChartCard>
        </div>
    </div>
</template>
