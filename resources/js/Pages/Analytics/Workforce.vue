<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import ChartCard from '@/Components/charts/ChartCard.vue';
import BarChart from '@/Components/charts/ChartJs/BarChart.vue';
import LineChart from '@/Components/charts/ChartJs/LineChart.vue';
import DoughnutChart from '@/Components/charts/ChartJs/DoughnutChart.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    metrics:     { type: Object, default: () => ({ kpis: {}, series: {}, meta: {} }) },
    filters:     { type: Object, default: () => ({ department_id: null, from: '', to: '' }) },
    departments: { type: Array,  default: () => [] },
});

const departmentId = ref(props.filters.department_id ?? '');
const from = ref(props.filters.from);
const to   = ref(props.filters.to);

const apply = () => router.get(route('analytics.workforce'), {
    department_id: departmentId.value || undefined,
    from: from.value || undefined,
    to: to.value || undefined,
}, { preserveState: false, preserveScroll: true });

const nf = new Intl.NumberFormat('en-GH');
const money = (v) => 'GHS ' + new Intl.NumberFormat('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v) || 0);

const k = computed(() => props.metrics.kpis ?? {});
const s = computed(() => props.metrics.series ?? {});

const kpiCards = computed(() => [
    { label: 'Headcount',      value: nf.format(k.value.headcount ?? 0),                 icon: 'groups',        color: 'blue',  hint: (k.value.headcount_delta >= 0 ? '+' : '') + (k.value.headcount_delta ?? 0) + ' net in period' },
    { label: 'New Hires',      value: nf.format(k.value.new_hires ?? 0),                 icon: 'person_add',    color: 'green' },
    { label: 'Leavers',        value: nf.format(k.value.leavers ?? 0),                   icon: 'logout',        color: 'amber' },
    { label: 'Turnover Rate',  value: (k.value.turnover_rate ?? 0) + '%',                icon: 'sync_problem',  color: 'red'   },
    { label: 'Avg Tenure',     value: (k.value.avg_tenure ?? 0) + ' yrs',                icon: 'hourglass_top', color: 'violet' },
]);

// palette matching the app's chart usage
const PALETTE = ['#1a237e', '#3949ab', '#00897b', '#f9a825', '#c62828', '#6a1b9a', '#0277bd', '#558b0f'];

const labelsOf  = (arr) => (arr ?? []).map((r) => r.label ?? r.month);
const valuesOf  = (arr) => (arr ?? []).map((r) => r.value);

const trendData = computed(() => ({
    labels: (s.value.headcount_trend ?? []).map((r) => r.month),
    datasets: [
        { type: 'bar',  label: 'Joiners', data: (s.value.headcount_trend ?? []).map((r) => r.joiners), backgroundColor: '#00897b' },
        { type: 'bar',  label: 'Leavers', data: (s.value.headcount_trend ?? []).map((r) => r.leavers), backgroundColor: '#c62828' },
        { type: 'line', label: 'Net',     data: (s.value.headcount_trend ?? []).map((r) => r.net),     borderColor: '#1a237e', tension: 0.3 },
    ],
}));

const barData = (arr, label) => ({
    labels: labelsOf(arr),
    datasets: [{ label, data: valuesOf(arr), backgroundColor: PALETTE }],
});

const genderData = computed(() => ({
    labels: labelsOf(s.value.gender),
    datasets: [{ data: valuesOf(s.value.gender), backgroundColor: PALETTE }],
}));

const baseOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true } } };
const horizontalOptions = { ...baseOptions, indexAxis: 'y' };

const hasData = (arr) => Array.isArray(arr) && arr.some((r) => (r.value ?? r.joiners ?? r.leavers ?? 0) > 0);
</script>

<template>
    <Head title="Workforce Analytics" />

    <div class="space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-primary">Workforce Analytics</h1>
                <p class="text-sm text-on-surface-variant">Headcount, composition and turnover across the organisation.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    Department
                    <select v-model="departmentId" aria-label="Filter by department" class="mt-1 rounded-xl border-outline-variant/60 bg-surface-container-lowest text-sm">
                        <option value="">All departments</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    From
                    <input v-model="from" type="date" aria-label="From date" class="mt-1 rounded-xl border-outline-variant/60 bg-surface-container-lowest text-sm" />
                </label>
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    To
                    <input v-model="to" type="date" aria-label="To date" class="mt-1 rounded-xl border-outline-variant/60 bg-surface-container-lowest text-sm" />
                </label>
                <button @click="apply" class="rounded-xl bg-primary px-4 py-2 text-xs font-black text-white shadow-glow-sm">Apply</button>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
            <StatCard v-for="c in kpiCards" :key="c.label" :value="c.value" :label="c.label" :icon="c.icon" :color="c.color" :hint="c.hint" />
        </section>

        <p v-if="metrics.meta?.turnover_caveat" class="rounded-xl bg-amber-500/10 px-4 py-2 text-xs text-amber-800 dark:text-amber-300">
            Some employees marked terminated have no offboarding record in this period, so turnover may be understated.
        </p>

        <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <ChartCard title="Headcount trend" subtitle="Joiners vs leavers" icon="show_chart">
                <div class="h-72"><LineChart v-if="hasData(s.headcount_trend)" :data="trendData" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data for this period.</p></div>
            </ChartCard>
            <ChartCard title="Headcount by department" icon="apartment">
                <div class="h-72"><BarChart v-if="hasData(s.by_department)" :data="barData(s.by_department, 'Employees')" :options="horizontalOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Gender diversity" icon="diversity_3">
                <div class="h-72"><DoughnutChart v-if="hasData(s.gender)" :data="genderData" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Tenure bands" icon="hourglass_top">
                <div class="h-72"><BarChart v-if="hasData(s.tenure_bands)" :data="barData(s.tenure_bands, 'Employees')" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Age bands" icon="cake">
                <div class="h-72"><BarChart v-if="hasData(s.age_bands)" :data="barData(s.age_bands, 'Employees')" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Span of control" subtitle="Managers by number of direct reports" icon="account_tree">
                <div class="h-72"><BarChart v-if="hasData(s.span_of_control)" :data="barData(s.span_of_control, 'Managers')" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Cost to company by department" icon="payments" class="xl:col-span-2">
                <div class="h-72"><BarChart v-if="hasData(s.cost_by_department)" :data="barData(s.cost_by_department, 'Payroll cost')" :options="horizontalOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
        </section>
    </div>
</template>
