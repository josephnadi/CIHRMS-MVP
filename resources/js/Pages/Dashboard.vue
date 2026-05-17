<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { useToast } from '@/composables/useToast';
import Sparkline from '@/Components/charts/Sparkline.vue';
import LiveBars  from '@/Components/charts/LiveBars.vue';

// Department dashboards are heavy (200 lines each, lots of decorative SVG/CSS)
// and only one is visible at a time — load them on demand so a typical
// dashboard visit ships ~600 fewer template lines in the initial bundle.
const DeptIt        = defineAsyncComponent(() => import('@/Pages/Dashboard/DeptIt.vue'));
const DeptHr        = defineAsyncComponent(() => import('@/Pages/Dashboard/DeptHr.vue'));
const DeptMarketing = defineAsyncComponent(() => import('@/Pages/Dashboard/DeptMarketing.vue'));
const DeptFinance   = defineAsyncComponent(() => import('@/Pages/Dashboard/DeptFinance.vue'));

const { success } = useToast();

// Switch the active dashboard module via the URL query param so back/forward works.
function gotoModule(mod) {
    router.get(route('dashboard'), { module: mod }, { preserveScroll: false, preserveState: true });
}

// Attendance scope toggle (today / week) for the in-dashboard widget
const attendanceScope = ref('today');

const props = defineProps({
    stats:           Object,
    recentEvents:    Array,
    employees:       Array,
    tickets:         Array,
    headcountByDept: { type: Array,  default: () => [] },
    leaveByMonth:    { type: Object, default: () => ({}) },
    ticketTrend:     { type: Object, default: () => ({}) },
    sparkSeries:     { type: Object, default: () => ({}) },
    activityFeed:    { type: Array,  default: () => [] },
    activeModule:    String,
});

const search = ref('');
// Auto-select the latest employee (props.employees is ordered latest() server-side)
// so the right-hand detail panel is populated as soon as Employees opens.
const selectedEmployee = ref(
    props.activeModule === 'employees' && props.employees?.length
        ? props.employees[0]
        : null
);

const selectEmployee = (employee) => {
    selectedEmployee.value = employee;
};

// â”€â”€ Live Analytics Layer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const liveTime  = ref('');
const lastSync  = ref(Date.now());      // timestamp of last server reload (for the "Live" pill)
const isSyncing = ref(false);           // true while an Inertia partial reload is in flight
const nowTick   = ref(Date.now());      // ticks every second to drive the "Live Â· 12s" countdown
const syncAgoLabel = computed(() => {
    const s = Math.max(0, Math.floor((nowTick.value - lastSync.value) / 1000));
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    return Math.floor(s / 3600) + 'h';
});
const sparkData = computed(() => {
    const toValues = (s) => Array.isArray(s) ? s.map(p => Number(p.value ?? 0)) : [];
    const compliance = [96, 97, 97.5, 98, 97.8, 98.2, 98, 98.4, 98.2, 98.6, 98.4, 98.2]; // no event type yet; kept literal
    return {
        employees:  toValues(props.sparkSeries.employees),
        tickets:    toValues(props.sparkSeries.tickets),
        leave:      toValues(props.sparkSeries.leave),
        compliance,
        payroll:    toValues(props.sparkSeries.payroll),
    };
});
// perfBarData is derived from real backend data via chartLeaveByMonth (defined below).
// Falls back to a stable baseline so the chart never collapses to zero on empty months.
const perfBarFallback = [45, 62, 50, 80, 88, 68, 72, 60, 75, 90, 70, 83];
const feedIdx = ref(0);
const activityPool = computed(() => {
    return props.activityFeed.length > 0
        ? props.activityFeed
        : [{ text: 'No recent activity yet.', icon: 'history', color: '#64748b', time: '' }];
});
const liveActivity = computed(() =>
    Array.from({ length: 5 }, (_, i) => activityPool.value[(feedIdx.value + i) % activityPool.value.length])
);

const kpiCards = computed(() => {
    const e = sparkData.value.employees;
    const t = sparkData.value.tickets;
    const l = sparkData.value.leave;
    const c = sparkData.value.compliance;
    const s = props.stats ?? {};
    return [
        // Card icon colours follow the institutional palette:
        //   magenta = people, cyan = tech/service, gold = flagship (5%), blue = financial
        { label: 'Active Staff',    display: (s.employees ?? 0).toLocaleString(),                                       trend: s.openJobs ? `${s.openJobs} open roles` : 'Workforce',     icon: 'badge',          color: '#d912e3', rgb: '217,18,227', spark: e, up: true  },
        { label: 'Open Tickets',    display: s.openTickets ?? 0,                                                        trend: 'Service desk',                                            icon: 'support_agent',  color: '#12d9e3', rgb: '18,217,227', spark: t, up: false },
        { label: 'Pending Leave',   display: s.pendingLeave ?? 0,                                                       trend: 'Awaiting approval',                                       icon: 'calendar_today', color: '#7cb6e8', rgb: '124,182,232', spark: l, up: false },
        { label: 'Pending Payroll', display: s.pendingPayments ?? 0,                                                    trend: s.openComplaints ? `${s.openComplaints} complaints` : '—', icon: 'payments',       color: '#ffd700', rgb: '255,215,0',  spark: c, up: true  },
    ];
});

// Seed sparkline arrays from real data when available
const chartHeadcount = computed(() => props.headcountByDept ?? []);
const chartLeaveByMonth = computed(() => {
    const monthlyData = props.leaveByMonth ?? {};
    return Array.from({ length: 12 }, (_, i) => monthlyData[String(i + 1)] ?? monthlyData[i + 1] ?? 0);
});
const chartTicketTrend = computed(() => Object.values(props.ticketTrend ?? {}));

// Workforce performance bars: scale real monthly leave data to a 20â€“98% range so visual
// proportions stay readable. Falls back to a stable baseline series when no data exists.
const perfBarData = computed(() => {
    const real = chartLeaveByMonth.value;
    const max = Math.max(...real);
    if (!max) return perfBarFallback;
    return real.map(v => 20 + Math.round((v / max) * 78));
});

const sparkLine = (pts, w = 96, h = 30) => {
    const min = Math.min(...pts); const max = Math.max(...pts); const rng = Math.max(max - min, 0.1);
    const n = pts.length;
    return pts.map((y, i) => `${((i / (n - 1)) * w).toFixed(1)},${(h - ((y - min) / rng) * h * 0.88).toFixed(1)}`).join(' ');
};
const sparkArea = (pts, w = 96, h = 30) => {
    const min = Math.min(...pts); const max = Math.max(...pts); const rng = Math.max(max - min, 0.1);
    const n = pts.length;
    const line = pts.map((y, i) => `${((i / (n - 1)) * w).toFixed(1)},${(h - ((y - min) / rng) * h * 0.88).toFixed(1)}`).join(' L ');
    return `M 0,${(h - ((pts[0] - min) / rng) * h * 0.88).toFixed(1)} L ${line} L ${w},${h} L 0,${h} Z`;
};

// â”€â”€ Department-specific live data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const deptSparkData = ref({
    it: {
        servers:  [22, 24, 23, 24, 24, 23, 24, 24, 23, 24, 24, 24],
        tickets:  [18, 22, 15, 28, 24, 20, 26, 18, 22, 16, 20, 18],
        alerts:   [3, 5, 4, 7, 6, 4, 5, 3, 5, 4, 6, 5],
        uptime:   [99.9, 99.95, 99.8, 99.97, 99.98, 99.96, 99.98, 99.99, 99.97, 99.98, 99.99, 99.98],
    },
    hr: {
        headcount:   [1260, 1265, 1270, 1272, 1275, 1278, 1280, 1282, 1283, 1284, 1284, 1284],
        turnover:    [3.2, 3.0, 2.8, 3.1, 2.9, 2.7, 2.8, 2.7, 2.8, 2.8, 2.8, 2.8],
        openPositions:[18, 16, 15, 14, 16, 15, 14, 15, 14, 14, 14, 14],
        training:    [72, 75, 74, 78, 76, 79, 78, 80, 79, 81, 80, 81],
    },
    marketing: {
        roi:        [280, 295, 285, 300, 305, 298, 308, 310, 305, 312, 308, 312],
        budget:     [45, 50, 52, 55, 58, 60, 62, 63, 65, 66, 68, 68],
        leads:      [2100, 2200, 2150, 2400, 2350, 2500, 2600, 2650, 2700, 2780, 2820, 2840],
        conversion: [4.2, 4.4, 4.1, 4.6, 4.5, 4.8, 4.7, 4.9, 5.0, 5.1, 5.0, 5.1],
    },
    finance: {
        revenue:    [7.2, 7.5, 7.4, 7.8, 7.9, 8.0, 8.1, 8.2, 8.3, 8.4, 8.4, 8.4],
        variance:   [-1.2, -1.8, -1.5, -2.0, -2.2, -2.1, -2.3, -2.2, -2.4, -2.3, -2.3, -2.3],
        pending:    [180, 165, 172, 158, 150, 148, 145, 143, 142, 142, 142, 142],
        efficiency: [88, 89, 88, 90, 91, 90, 92, 91, 93, 92, 93, 93],
    },
});

const _intervals = [];
let   _reloadTimer = null;

// Pick a fresh delay between 15sâ€“20s so the cadence never feels mechanical.
const nextReloadMs = () => 15000 + Math.floor(Math.random() * 5001);

// Pull fresh server-side numbers in the background. `only:` keeps the payload
// small â€” Inertia re-evaluates just these props server-side and patches them
// in client-side without a full page reload.
function scheduleServerReload() {
    _reloadTimer = setTimeout(() => {
        isSyncing.value = true;
        router.reload({
            only: ['stats', 'recentEvents', 'employees', 'tickets', 'headcountByDept', 'leaveByMonth', 'ticketTrend'],
            preserveScroll: true,
            preserveState:  true,
            onFinish: () => {
                isSyncing.value = false;
                lastSync.value  = Date.now();
                scheduleServerReload();
            },
        });
    }, nextReloadMs());
}

onMounted(() => {
    liveTime.value = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    _intervals.push(setInterval(() => {
        liveTime.value = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }, 60000));

    // 1Hz tick that drives the "Live Â· Ns" countdown pill in the header
    _intervals.push(setInterval(() => { nowTick.value = Date.now(); }, 1000));

    // Fast in-memory random walk for sparklines (visual liveness)
    _intervals.push(setInterval(() => {
        const bump = (key, min, max, delta) => {
            const arr = sparkData.value[key];
            const last = arr[arr.length - 1];
            sparkData.value[key] = [...arr.slice(1), +(Math.max(min, Math.min(max, last + (Math.random() - 0.42) * delta)).toFixed(1))];
        };
        bump('employees', 1278, 1292, 3);
        bump('tickets', 18, 32, 2);
        bump('leave', 42, 55, 3);
        bump('compliance', 96.5, 99.5, 0.4);
    }, 3200));

    // Live Activity feed rotation
    _intervals.push(setInterval(() => {
        feedIdx.value = (feedIdx.value + 1) % activityPool.value.length;
    }, 1900));

    // Departmental sparklines (IT / HR / Marketing / Finance)
    _intervals.push(setInterval(() => {
        const jitter = (v, lo, hi, d) => +(Math.max(lo, Math.min(hi, v + (Math.random() - 0.44) * d)).toFixed(2));
        const it = deptSparkData.value.it; const hr = deptSparkData.value.hr;
        const mk = deptSparkData.value.marketing; const fi = deptSparkData.value.finance;
        const shift = (arr, lo, hi, d) => [...arr.slice(1), jitter(arr[arr.length - 1], lo, hi, d)];
        deptSparkData.value.it        = { servers: shift(it.servers, 22, 24, 0.5), tickets: shift(it.tickets, 12, 32, 2), alerts: shift(it.alerts, 2, 9, 1), uptime: shift(it.uptime, 99.7, 99.99, 0.05) };
        deptSparkData.value.hr        = { headcount: shift(hr.headcount, 1280, 1295, 1.5), turnover: shift(hr.turnover, 2.4, 3.5, 0.1), openPositions: shift(hr.openPositions, 10, 20, 1), training: shift(hr.training, 70, 95, 1.5) };
        deptSparkData.value.marketing = { roi: shift(mk.roi, 260, 360, 5), budget: shift(mk.budget, 60, 85, 1.5), leads: shift(mk.leads, 2600, 3100, 40), conversion: shift(mk.conversion, 4.0, 5.8, 0.1) };
        deptSparkData.value.finance   = { revenue: shift(fi.revenue, 8.0, 9.5, 0.08), variance: shift(fi.variance, -3.5, -1.0, 0.1), pending: shift(fi.pending, 120, 200, 4), efficiency: shift(fi.efficiency, 88, 98, 0.8) };
    }, 3800));

    // â”€â”€ Real backend sync every 15â€“20s (random) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    scheduleServerReload();
});

onBeforeUnmount(() => {
    _intervals.forEach(clearInterval);
    if (_reloadTimer) clearTimeout(_reloadTimer);
});
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

const filteredEmployees = computed(() => props.employees.filter((employee) =>
    (employee.name?.toLowerCase() || '').includes(search.value.toLowerCase()) ||
    employee.employee_no.toLowerCase().includes(search.value.toLowerCase()) ||
    employee.position.toLowerCase().includes(search.value.toLowerCase()),
));

const moduleLabel = computed(() => {
    if (props.activeModule === 'overview' || !props.activeModule) {
        if (usePage().props.auth.user.role === 'employee') return 'Employee Portal';
        if (usePage().props.auth.user.role === 'hr_admin') return 'HR Portal';
        return 'Executive Overview';
    }

    const labels = {
        employees: 'Employee Management',
        attendance: 'Attendance & Time Tracking',
        leave: 'Leave Management',
        tickets: 'Service Desk',
        recruitment: 'Recruitment',
        payroll: 'Payroll',
        performance: 'Performance Analytics',
        governance: 'Institutional Governance',
        assets: 'Asset Management',
        reports: 'Intelligence Reports',
        'audit-logs': 'System Audit Logs',
        benefits: 'Benefits & Welfare',
        learning: 'Learning & Development',
        'dept-it': 'IT & Technology',
        'dept-hr': 'Human Resources',
        'dept-marketing': 'Marketing',
        'dept-finance': 'Finance',
    };

    return labels[props.activeModule] ?? 'Executive Overview';
});

const getStatusColor = (status) => {
    const colors = {
        pending:    'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-700/40',
        onboarding: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-700/40',
        active:     'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 border-green-200 dark:border-green-700/40',
        away:       'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-700/40',
        approved:   'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 border-green-200 dark:border-green-700/40',
        rejected:   'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-700/40',
        open:       'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-700/40',
        closed:     'bg-slate-100 dark:bg-slate-800/50 text-slate-700 dark:text-slate-400 border-slate-200 dark:border-slate-600/40',
    };
    return colors[status?.toLowerCase()] || 'bg-slate-100 dark:bg-slate-800/50 text-slate-700 dark:text-slate-400 border-slate-200 dark:border-slate-600/40';
};
</script>

<template>
    <Head :title="moduleLabel" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div>
                        <h2 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ moduleLabel }}</h2>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            <template v-if="$page.props.auth.user.role === 'employee'">Access your personal institutional records and services.</template>
                            <template v-else-if="$page.props.auth.user.role === 'hr_admin'">Manage workforce operations, recruitment, and compliance.</template>
                            <template v-else>Real-time institutional performance metrics and workforce intelligence.</template>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2.5">
                    <!-- Live sync indicator: cyan pulse while syncing, gold ring when idle (institutional 5% accent) -->
                    <div class="flex items-center gap-1.5 rounded-full px-3 py-1.5 border live-pill"
                         :style="isSyncing
                            ? 'background:rgba(18,217,227,0.10);border-color:rgba(18,217,227,0.40);color:#0a2647;'
                            : 'background:rgba(255,215,0,0.10);border-color:rgba(255,215,0,0.45);color:#b88a08;'">
                        <span class="h-1.5 w-1.5 rounded-full"
                              :style="isSyncing
                                ? 'background:#12d9e3;box-shadow:0 0 8px rgba(18,217,227,0.75);'
                                : 'background:#ffd700;box-shadow:0 0 8px rgba(255,215,0,0.70);'"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest">
                            {{ isSyncing ? 'Syncing' : `Live · ${syncAgoLabel}` }}
                        </span>
                    </div>
                    <Link :href="route('reports.index')" class="flex items-center gap-2 rounded-xl border border-outline-variant/70 bg-surface-container-lowest px-4 py-2.5 text-[13px] font-bold text-on-surface shadow-sm transition-all duration-150 hover:bg-surface-container-low hover:border-outline-variant hover:-translate-y-px active:scale-[0.97]">
                        <span class="material-symbols-outlined text-[18px] text-on-surface-variant">download</span>
                        Export
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-8">
            <!-- Employee Directory Module -->
            <div v-if="activeModule === 'employees'" class="grid grid-cols-12 gap-8 animate-reveal-up">
                <!-- Directory Table -->
                <div class="col-span-12 lg:col-span-8 space-y-6">
                    <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                        <div class="border-b border-outline-variant/50 bg-surface-container-lowest/80 px-7 py-5 flex items-center justify-between flex-wrap gap-4">
                            <div class="flex items-center gap-4">
                                <h3 class="text-2xl font-black text-primary">Employee Directory</h3>
                                <div class="flex items-center gap-1.5 rounded-full bg-surface-container-low px-3 py-1 text-xs font-bold text-on-surface-variant">
                                    <span class="text-primary">{{ stats.employees || 0 }}</span>
                                    <span>Total</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-sm">search</span>
                                    <input v-model="search" class="rounded-full border-outline-variant bg-surface-container-low pl-9 pr-4 py-2 text-xs focus:ring-secondary/20 focus:border-secondary w-48" placeholder="Search..." />
                                </div>
                                <Link :href="route('employees.index')" class="flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-on-surface-variant hover:bg-surface-container-low transition-all">
                                    <span class="material-symbols-outlined text-xl">filter_list</span>
                                    Department
                                </Link>
                                <button @click="router.visit(route('employees.index', { new: 1 }))"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-95"
                                        style="background:linear-gradient(135deg,#0a2647,#205295);">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    Add Employee
                                </button>
                            </div>
                        </div>

                        <div class="canvas-scroll max-h-[420px] overflow-auto">
                            <table class="w-full text-left">
                                <thead class="sticky top-0 z-10">
                                    <tr class="bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                        <th class="px-8 py-4 w-12"><input type="checkbox" class="rounded border-outline-variant text-secondary focus:ring-secondary/20" /></th>
                                        <th class="px-4 py-4 w-16">ID</th>
                                        <th class="px-6 py-4">Employee Name</th>
                                        <th class="px-6 py-4">Department</th>
                                        <th class="px-6 py-4">Status</th>
                                        <th class="px-6 py-4">Leave Balance</th>
                                        <th class="px-6 py-4">Performance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-outline-variant/40">
                                    <tr v-for="employee in filteredEmployees" :key="employee.id" 
                                        class="group cursor-pointer transition-colors"
                                        :class="selectedEmployee?.id === employee.id ? 'bg-secondary/[0.07] border-l-4 border-l-secondary' : 'hover:bg-surface-container-low/50'"
                                        @click="selectEmployee(employee)"
                                    >
                                        <td class="px-8 py-6"><input type="checkbox" :checked="selectedEmployee?.id === employee.id" class="rounded border-outline-variant text-secondary focus:ring-secondary/20" /></td>
                                        <td class="px-4 py-6 text-xs font-mono font-bold text-on-surface-variant">#{{ employee.id }}</td>
                                        <td class="px-6 py-6">
                                            <div class="flex items-center gap-4">
                                                <div class="h-12 w-12 rounded-full bg-secondary/10 flex items-center justify-center font-bold text-secondary text-lg border-2 border-white shadow-sm overflow-hidden">
                                                    <img v-if="employee.avatar" :src="employee.avatar" class="h-full w-full object-cover" />
                                                    <span v-else>{{ employee.employee_no.charAt(0) }}</span>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-black text-primary">{{ employee.name || 'Member #' + employee.id }}</p>
                                                    <p class="text-[11px] font-medium text-on-surface-variant">{{ employee.position }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-6 text-sm font-bold text-on-surface-variant">{{ employee.department?.name || 'Engineering' }}</td>
                                        <td class="px-6 py-6">
                                            <span class="inline-flex rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider border" :class="getStatusColor(employee.status || 'Active')">
                                                {{ employee.status || 'Active' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-6 text-sm font-bold text-primary">12 Days</td>
                                        <td class="px-6 py-6">
                                            <div class="flex items-center gap-1.5 text-amber-500">
                                                <span class="material-symbols-outlined text-lg fill-1">star</span>
                                                <span class="text-sm font-black text-primary">4.8</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="!filteredEmployees.length">
                                        <td colspan="7" class="px-8 py-12 text-center text-sm font-bold text-on-surface-variant italic">No matching records found.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="border-t border-outline-variant bg-surface-container-lowest px-8 py-4 flex items-center justify-between">
                            <p class="text-xs font-bold text-on-surface-variant">Showing 1 to {{ filteredEmployees.length }} of {{ stats.employees || 0 }} employees</p>
                            <Link :href="route('employees.index')"
                                  class="flex items-center gap-2 text-[11px] font-bold text-secondary hover:underline">
                                Open full directory
                                <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Detail Panel -->
                <div class="col-span-12 lg:col-span-4 space-y-6">
                    <template v-if="selectedEmployee">
                        <!-- Profile Card -->
                        <div class="rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm text-center">
                            <div class="relative mx-auto mb-6 h-32 w-32">
                                <div class="h-full w-full rounded-full bg-secondary/10 flex items-center justify-center font-bold text-secondary text-4xl border-4 border-white shadow-xl overflow-hidden">
                                    <img v-if="selectedEmployee.avatar" :src="selectedEmployee.avatar" class="h-full w-full object-cover" />
                                    <span v-else>{{ selectedEmployee.employee_no.charAt(0) }}</span>
                                </div>
                                <div class="absolute bottom-1 right-1 h-6 w-6 rounded-full border-4 border-white bg-green-500 shadow-sm"></div>
                            </div>
                            <h4 class="text-2xl font-black text-primary">{{ selectedEmployee.name || 'Akua Mensah' }}</h4>
                            <p class="text-sm font-bold text-secondary">{{ selectedEmployee.position }}</p>
                            <p class="mt-1 text-xs font-medium text-on-surface-variant">ID: {{ selectedEmployee.employee_no }} â€¢ Joined 2021</p>
                            
                            <div class="mt-8 flex gap-3">
                                <Link v-if="selectedEmployee?.id" :href="route('employees.show', selectedEmployee.id)"
                                      class="flex-1 rounded-xl bg-secondary py-3 text-sm font-black text-white text-center shadow-lg shadow-secondary/20 hover:bg-secondary/90 transition-all">
                                    View Full Profile
                                </Link>
                                <button @click="router.visit(route('profile.edit'))"
                                        type="button"
                                        class="rounded-xl border border-outline-variant px-3 py-3 text-on-surface-variant hover:bg-surface-container-low transition-all">
                                    <span class="material-symbols-outlined">more_vert</span>
                                </button>
                            </div>

                            <div class="mt-8 text-left">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-black text-primary">Onboarding Progress</span>
                                    <span class="text-xs font-black text-secondary">100%</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-surface-container-low overflow-hidden">
                                    <div class="h-full w-full bg-green-500 rounded-full"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Performance Card -->
                        <div class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8">
                            <div class="flex items-center justify-between mb-6">
                                <h4 class="text-sm font-black text-primary uppercase tracking-widest">Performance Rating</h4>
                                <span class="material-symbols-outlined text-on-surface-variant">trending_up</span>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-4xl font-black text-primary">4.8</span>
                                <span class="text-sm font-bold text-on-surface-variant">/ 5.0</span>
                            </div>
                            <div class="mt-6 space-y-4">
                                <div class="flex items-center gap-4 rounded-2xl bg-surface-container-low/50 p-4 border border-outline-variant/30">
                                    <div class="h-10 w-10 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600">
                                        <span class="material-symbols-outlined">bolt</span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-black text-primary">Productivity</p>
                                        <p class="text-[10px] font-bold text-on-surface-variant">Top 5% in Engineering</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 rounded-2xl bg-surface-container-low/50 p-4 border border-outline-variant/30">
                                    <div class="h-10 w-10 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600">
                                        <span class="material-symbols-outlined">groups</span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-black text-primary">Leadership</p>
                                        <p class="text-[10px] font-bold text-on-surface-variant">Mentors 4 Junior Staff</p>
                                    </div>
                                </div>
                            </div>
                            <button @click="router.visit(route('performance.reviews.index'))"
                                    type="button"
                                    class="mt-6 w-full rounded-xl border border-outline-variant py-3 text-sm font-black text-primary hover:bg-surface-container-low transition-all">
                                View Review History
                            </button>
                        </div>

                        <!-- Leave Balance Card -->
                        <div class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8">
                            <h4 class="text-sm font-black text-primary uppercase tracking-widest mb-6">Available Leave</h4>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="rounded-2xl bg-surface-container-low/50 p-4 border border-outline-variant/30">
                                    <p class="text-[10px] font-black uppercase text-on-surface-variant mb-1">Annual</p>
                                    <p class="text-2xl font-black text-primary">12</p>
                                    <p class="text-[9px] font-bold text-green-600 uppercase tracking-tighter">Days Left</p>
                                </div>
                                <div class="rounded-2xl bg-surface-container-low/50 p-4 border border-outline-variant/30">
                                    <p class="text-[10px] font-black uppercase text-on-surface-variant mb-1">Sick</p>
                                    <p class="text-2xl font-black text-primary">05</p>
                                    <p class="text-[9px] font-bold text-amber-600 uppercase tracking-tighter">Days Left</p>
                                </div>
                            </div>
                        </div>
                    </template>
                    <div v-else class="rounded-3xl border-2 border-dashed border-outline-variant bg-surface-container-low/20 h-full flex flex-col items-center justify-center p-12 text-center">
                        <span class="material-symbols-outlined text-6xl text-outline-variant mb-4">badge</span>
                        <p class="text-sm font-bold text-on-surface-variant italic">Select an employee from the directory to view detailed profile and performance metrics.</p>
                    </div>
                </div>
            </div>

            <!-- Service Desk Dashboard Module -->
            <div v-if="activeModule === 'tickets'" class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                <!-- Header Actions -->
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-2xl font-black text-primary">Service Desk Dashboard</h3>
                        <p class="text-sm font-medium text-on-surface-variant">Manage internal employee support requests and track SLA performance.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 rounded-full bg-red-50 px-4 py-2 border border-red-100 shadow-sm">
                            <span class="h-2 w-2 rounded-full bg-red-600 animate-pulse"></span>
                            <span class="text-[11px] font-black text-red-700 uppercase tracking-wider">12 Critical SLAs</span>
                        </div>
                        <Link :href="route('tickets.index')" class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2.5 text-sm font-bold text-primary hover:bg-surface-container-low transition-all">
                            <span class="material-symbols-outlined text-xl">filter_list</span>
                            Filter
                        </Link>
                    </div>
                </div>

                <!-- Stats Grid with Sparklines -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(stat, si) in [
                        { label: 'Open Tickets',    val: Math.round(sparkData.tickets[sparkData.tickets.length-1]), badge: '12 critical',  badgeColor: 'bg-red-50 text-red-700',    icon: 'confirmation_number', color: '#12d9e3', rgb: '18,217,227',  spark: sparkData.tickets    },
                        { label: 'Avg Resolution',  val: '4.2h',                                                    badge: '↑ improving',  badgeColor: 'bg-green-50 text-green-700', icon: 'timer',               color: '#ffd700', rgb: '255,215,0',   spark: sparkData.compliance },
                        { label: 'SLA Compliance',  val: sparkData.compliance[sparkData.compliance.length-1].toFixed(1)+'%', badge: 'Target 95%', badgeColor: 'bg-green-50 text-green-700', icon: 'verified', color: '#205295', rgb: '32,82,149', spark: sparkData.compliance },
                        { label: 'Pending Review',  val: '8',                                                       badge: '3 escalated',  badgeColor: 'bg-amber-50 text-amber-700', icon: 'inbox',               color: '#d912e3', rgb: '217,18,227',  spark: sparkData.leave      },
                    ]" :key="si"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${si*0.06}s`">
                        <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${stat.color}`"></span>
                        </div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-xl" :style="`background:rgba(${stat.rgb},0.1)`">
                                <span class="material-symbols-outlined text-[18px]" :style="`color:${stat.color};font-variation-settings:'FILL' 1`">{{ stat.icon }}</span>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-bold" :class="stat.badgeColor">{{ stat.badge }}</span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ stat.label }}</p>
                        <p class="mt-1.5 text-2xl font-black text-primary kpi-val">{{ stat.val }}</p>
                        <div class="-mx-1 mt-3">
                            <Sparkline :data="stat.spark" :color="stat.color" :width="96" :height="28"
                                       :stroke-width="1.5" :label="stat.label" class="!block w-full"/>
                        </div>
                    </div>
                </div>

                <!-- Kanban Board -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Open Column -->
                    <div class="flex flex-col gap-4 rounded-2xl p-4"
                         style="background:rgba(0,0,0,0.025);border:1px solid rgba(0,0,0,0.06);">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-2 rounded-full bg-slate-400"></div>
                                <h4 class="text-[13px] font-black text-primary">Open</h4>
                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-surface-container-high text-[10px] font-black text-on-surface-variant">4</span>
                            </div>
                            <Link :href="route('tickets.index')" class="text-on-surface-variant hover:text-primary" title="Open full kanban"><span class="material-symbols-outlined text-[20px]">more_horiz</span></Link>
                        </div>
                        
                        <div class="space-y-4">
                            <!-- Ticket Card -->
                            <div class="group cursor-pointer rounded-xl border border-outline-variant/60 bg-surface-container-lowest p-4 transition-all duration-200 hover:border-secondary/30 hover:shadow-card-hover hover:-translate-y-0.5">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="rounded-lg bg-red-50 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-red-700 border border-red-100">High Priority</span>
                                    <span class="text-[10px] font-bold text-on-surface-variant/60">#SD-1024</span>
                                </div>
                                <h5 class="text-sm font-black text-primary leading-snug group-hover:text-secondary transition-colors">Payroll access denied for newly hired engineers</h5>
                                <div class="mt-4 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-red-600">
                                        <span class="material-symbols-outlined text-sm">schedule</span>
                                        <span class="text-[10px] font-black">14m left</span>
                                    </div>
                                    <img src="https://i.pravatar.cc/150?u=4" class="h-6 w-6 rounded-full border border-outline-variant" />
                                </div>
                            </div>

                            <div class="group cursor-pointer rounded-xl border border-outline-variant/60 bg-surface-container-lowest p-4 transition-all duration-200 hover:border-secondary/30 hover:shadow-card-hover hover:-translate-y-0.5">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="rounded-lg bg-blue-50 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-blue-700 border border-blue-100">Medium</span>
                                    <span class="text-[10px] font-bold text-on-surface-variant/60">#SD-1029</span>
                                </div>
                                <h5 class="text-sm font-black text-primary leading-snug group-hover:text-secondary transition-colors">Replacement laptop request: Accra Office</h5>
                                <div class="mt-4 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-on-surface-variant">
                                        <span class="material-symbols-outlined text-sm">schedule</span>
                                        <span class="text-[10px] font-black">2h 40m</span>
                                    </div>
                                    <div class="h-6 w-6 rounded-full bg-surface-container-low flex items-center justify-center text-[10px] font-bold text-on-surface-variant border border-outline-variant">??</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- In Progress Column -->
                    <div class="flex flex-col gap-4 rounded-2xl p-4"
                         style="background:rgba(32,82,149,0.03);border:1px solid rgba(32,82,149,0.1);">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-2 rounded-full bg-blue-500 animate-pulse"></div>
                                <h4 class="text-[13px] font-black text-primary">In Progress</h4>
                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-secondary text-[10px] font-black text-white shadow-glow-sm">2</span>
                            </div>
                            <Link :href="route('tickets.index')" class="text-on-surface-variant hover:text-primary" title="Open full kanban"><span class="material-symbols-outlined text-[20px]">more_horiz</span></Link>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="group cursor-pointer rounded-xl border border-secondary/25 border-l-4 border-l-secondary bg-surface-container-lowest p-4 transition-all duration-200 hover:shadow-card-hover hover:-translate-y-0.5">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="rounded-lg bg-blue-50 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-blue-700 border border-blue-100">Medium</span>
                                    <span class="text-[10px] font-bold text-on-surface-variant/60">#SD-1021</span>
                                </div>
                                <h5 class="text-sm font-black text-primary leading-snug">Quarterly Performance Review template update</h5>
                                <div class="mt-4 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-secondary">
                                        <span class="material-symbols-outlined text-sm animate-spin-slow">sync</span>
                                        <span class="text-[10px] font-black">In Review</span>
                                    </div>
                                    <img src="https://i.pravatar.cc/150?u=8" class="h-6 w-6 rounded-full border border-outline-variant" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Column -->
                    <div class="flex flex-col gap-4 rounded-2xl p-4"
                         style="background:rgba(245,158,11,0.03);border:1px solid rgba(245,158,11,0.12);">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-2 rounded-full bg-amber-400"></div>
                                <h4 class="text-[13px] font-black text-primary">Pending</h4>
                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-[10px] font-black text-amber-700">1</span>
                            </div>
                            <Link :href="route('tickets.index')" class="text-on-surface-variant hover:text-primary" title="Open full kanban"><span class="material-symbols-outlined text-[20px]">more_horiz</span></Link>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="group cursor-pointer rounded-xl border border-outline-variant/60 bg-surface-container-lowest p-4 transition-all duration-200 hover:border-secondary/30 hover:shadow-card-hover hover:-translate-y-0.5">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="rounded-lg bg-surface-container-low px-2 py-1 text-[9px] font-black uppercase tracking-wider text-on-surface-variant border border-outline-variant">Low</span>
                                    <span class="text-[10px] font-bold text-on-surface-variant/60">#SD-1018</span>
                                </div>
                                <h5 class="text-sm font-medium italic text-on-surface-variant leading-snug">Internal office party planning committee setup</h5>
                                <div class="mt-4 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-on-surface-variant/60">
                                        <span class="material-symbols-outlined text-sm">pause_circle</span>
                                        <span class="text-[10px] font-bold">Awaiting Feedback</span>
                                    </div>
                                    <img src="https://i.pravatar.cc/150?u=12" class="h-6 w-6 rounded-full border border-outline-variant" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Floating Action Button â€” opens new-ticket modal -->
                <button @click="router.visit(route('tickets.index', { new: 1 }))"
                        type="button"
                        title="Create new ticket"
                        class="btn-shimmer fixed bottom-8 right-8 flex h-14 w-14 items-center justify-center rounded-full text-white shadow-glow-lg transition-all hover:scale-110 hover:shadow-[0_0_48px_rgba(32,82,149,0.5)] active:scale-95 z-50"
                        style="background:linear-gradient(135deg,#0a2647,#205295);">
                    <span class="material-symbols-outlined text-2xl" style="font-variation-settings:'FILL' 1">add_task</span>
                </button>
            </div>

            <!-- Performance & KPI Dashboard Module -->
            <div v-if="activeModule === 'performance'" class="space-y-8 animate-in fade-in duration-500">
                <!-- Top Section: Team Productivity & Strategic OKRs -->
                <div class="grid grid-cols-12 gap-8">
                    <!-- Team Productivity Chart Placeholder -->
                    <div class="col-span-12 lg:col-span-8 rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm">
                        <div class="flex items-center justify-between mb-8">
                            <div>
                                <h3 class="text-xl font-black text-primary">Team Productivity</h3>
                                <p class="text-xs font-medium text-on-surface-variant">Aggregate output vs target across all departments</p>
                            </div>
                            <div class="flex items-center gap-2 rounded-full bg-green-50 px-3 py-1 border border-green-100">
                                <span class="text-[10px] font-black text-green-700 uppercase tracking-wider">+12.4% vs last mo</span>
                            </div>
                        </div>
                        
                        <!-- Productivity Bar Chart -->
                        <div class="flex items-center justify-between mb-3 px-1">
                            <div class="flex items-center gap-2">
                                <span class="h-1.5 w-1.5 rounded-full bg-blue-400"></span>
                                <span class="text-[9px] font-black uppercase tracking-widest text-secondary">Monthly leave volume</span>
                            </div>
                            <span class="text-[10px] font-bold text-on-surface-variant">{{ new Date().getFullYear() }}</span>
                        </div>
                        <LiveBars
                            :data="perfBarData.map((v,i) => ({ label: ['J','F','M','A','M','J','J','A','S','O','N','D'][i], value: v }))"
                            :height="208"
                            :format-value="v => `${Math.round(v)}%`"
                            color="#205295"
                            accent-color="#ffd700"
                            second-color="#12d9e3"
                            :show-median="true"
                            :rounded="6"
                        />
                    </div>

                    <!-- Strategic OKRs -->
                    <div class="col-span-12 lg:col-span-4 rounded-3xl p-8 text-white shadow-2xl relative overflow-hidden flex flex-col"
                         style="background:linear-gradient(135deg,#0c0e14 0%,#131620 100%);border:1px solid rgba(255,255,255,0.06);">
                        <div class="absolute -right-4 -top-4 opacity-10">
                            <span class="material-symbols-outlined text-9xl">stars</span>
                        </div>
                        <div class="flex items-center justify-between mb-8">
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-white/50">Strategic OKRs</p>
                            <span class="material-symbols-outlined text-secondary">verified</span>
                        </div>
                        
                        <h3 class="text-2xl font-black leading-tight">Institutional Resilience <br/> Score</h3>
                        
                        <div class="mt-10 space-y-8 flex-1">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between text-xs font-bold">
                                    <span class="text-white/70">Staff Retention</span>
                                    <span>94%</span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-surface-container-lowest/10 overflow-hidden">
                                    <div class="h-full w-[94%] bg-secondary rounded-full"></div>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <div class="flex items-center justify-between text-xs font-bold">
                                    <span class="text-white/70">Talent Development</span>
                                    <span>68%</span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-surface-container-lowest/10 overflow-hidden">
                                    <div class="h-full w-[68%] bg-secondary/60 rounded-full"></div>
                                </div>
                            </div>
                        </div>

                        <button @click="router.visit(route('performance.goals.index'))"
                                type="button"
                                class="btn-shimmer mt-10 w-full rounded-xl py-3 text-[13px] font-black text-white border transition-all hover:bg-white/20"
                                style="background:rgba(32,82,149,0.25);border-color:rgba(44,116,179,0.3);">
                            View Strategic Roadmap
                        </button>
                    </div>
                </div>

                <!-- Middle Section: Dept Efficiency & Top Performers -->
                <div class="grid grid-cols-12 gap-8">
                    <!-- Dept Efficiency Index -->
                    <div class="col-span-12 lg:col-span-6 rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm">
                        <h4 class="text-lg font-black text-primary mb-8">Dept. Efficiency Index</h4>
                        <div class="space-y-6">
                            <div v-for="dept in [
                                { name: 'Operations', score: 8.9, color: 'bg-green-500', blocks: 5 },
                                { name: 'Technology', score: 7.2, color: 'bg-blue-600', blocks: 4 },
                                { name: 'Marketing', score: 6.1, color: 'bg-orange-400', blocks: 3 },
                                { name: 'Human Res.', score: 9.4, color: 'bg-green-500', blocks: 5 }
                            ]" :key="dept.name" class="flex items-center gap-6">
                                <span class="w-24 text-xs font-bold text-on-surface-variant">{{ dept.name }}</span>
                                <div class="flex flex-1 gap-1.5">
                                    <div v-for="i in 5" :key="i" class="h-6 flex-1 rounded-sm transition-all" :class="i <= dept.blocks ? dept.color : 'bg-surface-container-low'"></div>
                                </div>
                                <span class="w-8 text-sm font-black text-right" :class="dept.score > 8 ? 'text-green-600' : 'text-primary'">{{ dept.score }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Top Performers Scorecard -->
                    <div class="col-span-12 lg:col-span-6 rounded-3xl border border-outline-variant bg-surface-container-lowest p-1 shadow-sm overflow-hidden">
                        <div class="border-b border-outline-variant/50 bg-surface-container-lowest/80 px-7 py-5 flex items-center justify-between">
                            <h4 class="text-lg font-black text-primary">Top Performers Scorecard</h4>
                            <Link :href="route('employees.index')" class="text-xs font-black text-secondary hover:underline">View All Records</Link>
                        </div>
                        <div class="canvas-scroll max-h-[340px] overflow-auto">
                            <table class="w-full text-left">
                                <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-widest text-on-surface-variant">
                                    <tr>
                                        <th class="px-8 py-4">Employee</th>
                                        <th class="px-4 py-4">Achievement</th>
                                        <th class="px-4 py-4">Trend</th>
                                        <th class="px-8 py-4 text-right">Rating</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-outline-variant/40">
                                    <tr v-for="performer in [
                                        { name: 'Akwasi Mensah', role: 'Project Manager', score: 4.9, trend: 'trending_up', trendColor: 'text-green-500', progress: 'w-[85%] bg-green-500' },
                                        { name: 'Esi Darko', role: 'Senior Engineer', score: 4.7, trend: 'east', trendColor: 'text-blue-500', progress: 'w-[75%] bg-blue-600' },
                                        { name: 'Obed Appiah', role: 'Financial Analyst', score: 4.5, trend: 'trending_up', trendColor: 'text-green-500', progress: 'w-[70%] bg-blue-600' }
                                    ]" :key="performer.name" class="hover:bg-surface-container-low/30 transition-colors">
                                        <td class="px-8 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="h-9 w-9 rounded-full bg-secondary/10 flex items-center justify-center font-bold text-secondary text-xs">
                                                    {{ performer.name.charAt(0) }}
                                                </div>
                                                <div>
                                                    <p class="text-xs font-black text-primary leading-tight">{{ performer.name }}</p>
                                                    <p class="text-[10px] font-medium text-on-surface-variant">{{ performer.role }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="h-1.5 w-24 rounded-full bg-surface-container-low overflow-hidden">
                                                <div class="h-full rounded-full" :class="performer.progress"></div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <span class="material-symbols-outlined text-lg" :class="performer.trendColor">{{ performer.trend }}</span>
                                        </td>
                                        <td class="px-8 py-4 text-right">
                                            <span class="inline-flex rounded-lg bg-surface-container-low px-2 py-1 text-[10px] font-black text-primary border border-outline-variant">
                                                {{ performer.score }}/5.0
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Bottom Section: Executive Recommendation -->
                <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                    <div class="grid grid-cols-12">
                        <div class="col-span-12 lg:col-span-5 p-10 border-r border-outline-variant">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-secondary/10 text-secondary mb-6 shadow-lg shadow-secondary/5">
                                <span class="material-symbols-outlined text-3xl">lightbulb</span>
                            </div>
                            <h3 class="text-xl font-black text-primary leading-tight">Executive <br/> Insight</h3>
                            <p class="mt-4 text-sm font-medium text-on-surface-variant leading-relaxed">
                                Institutional analysis indicates that prioritizing departmental efficiency in Operations could yield significant performance gains across the enterprise by Q4.
                            </p>
                        </div>
                        <div class="col-span-12 lg:col-span-7 grid grid-cols-1 sm:grid-cols-3 divide-x divide-outline-variant">
                            <div class="p-10 space-y-2">
                                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Risk Level</p>
                                <p class="text-lg font-black text-green-600">Low (Stable)</p>
                                <p class="text-[11px] font-medium text-on-surface-variant leading-relaxed">Retention and engagement scores are within target bands.</p>
                            </div>
                            <div class="p-10 space-y-2">
                                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Forecasted Growth</p>
                                <p class="text-lg font-black text-primary">+8.4%</p>
                                <p class="text-[11px] font-medium text-on-surface-variant leading-relaxed">Projected performance increase across core functional units.</p>
                            </div>
                            <div class="p-10 space-y-2">
                                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Resource Utilization</p>
                                <p class="text-lg font-black text-secondary">Optimal</p>
                                <p class="text-[11px] font-medium text-on-surface-variant leading-relaxed">Current headcounts align with operational demands.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Module -->
            <div v-if="activeModule === 'attendance'" class="space-y-6 animate-reveal-up">
                <!-- Today's snapshot -->
                <div class="grid grid-cols-12 gap-6">
                    <div class="col-span-12 lg:col-span-8 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                            <div>
                                <h3 class="text-[16px] font-black text-primary">Today's Attendance</h3>
                                <p class="text-[11px] text-on-surface-variant mt-0.5">{{ new Date().toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="success('Clocked in at ' + new Date().toLocaleTimeString('en-GH'))"
                                        type="button"
                                        class="rounded-xl px-4 py-2 text-[12px] font-bold text-white shadow-glow-sm"
                                        style="background:linear-gradient(135deg,#059669,#34d399)">
                                    <span class="material-symbols-outlined text-[16px] align-middle mr-1">login</span>
                                    Clock In
                                </button>
                                <button @click="success('Clocked out at ' + new Date().toLocaleTimeString('en-GH'))"
                                        type="button"
                                        class="rounded-xl border border-outline-variant px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low">
                                    <span class="material-symbols-outlined text-[16px] align-middle mr-1">logout</span>
                                    Clock Out
                                </button>
                            </div>
                        </div>

                        <!-- Live counters -->
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
                            <div class="rounded-xl border border-green-200/60 bg-green-50/60 dark:bg-green-950/20 p-4">
                                <p class="text-[10px] font-black uppercase tracking-wider text-green-700">Present</p>
                                <p class="mt-1 text-[22px] font-black text-green-700">{{ Math.max((stats?.employees ?? 0) - 6, 0) }}</p>
                            </div>
                            <div class="rounded-xl border border-amber-200/60 bg-amber-50/60 dark:bg-amber-950/20 p-4">
                                <p class="text-[10px] font-black uppercase tracking-wider text-amber-700">Late</p>
                                <p class="mt-1 text-[22px] font-black text-amber-700">3</p>
                            </div>
                            <div class="rounded-xl border border-blue-200/60 bg-blue-50/60 dark:bg-blue-950/20 p-4">
                                <p class="text-[10px] font-black uppercase tracking-wider text-blue-700">On Leave</p>
                                <p class="mt-1 text-[22px] font-black text-blue-700">{{ stats?.pendingLeave ?? 0 }}</p>
                            </div>
                            <div class="rounded-xl border border-red-200/60 bg-red-50/60 dark:bg-red-950/20 p-4">
                                <p class="text-[10px] font-black uppercase tracking-wider text-red-700">Absent</p>
                                <p class="mt-1 text-[22px] font-black text-red-700">2</p>
                            </div>
                        </div>

                        <!-- Weekday strip -->
                        <div class="grid grid-cols-7 gap-2">
                            <div v-for="(day, i) in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="day" class="text-center">
                                <p class="text-[10px] font-black uppercase text-on-surface-variant mb-1.5">{{ day }}</p>
                                <div :class="['h-20 rounded-xl flex flex-col items-center justify-center gap-1 transition-colors',
                                              i === new Date().getDay() - 1 ? 'bg-secondary/10 border border-secondary/30' : 'bg-surface-container-low hover:bg-surface-container']">
                                    <span class="text-[13px] font-black text-primary">{{ 92 - i * 2 }}%</span>
                                    <span class="text-[9px] text-on-surface-variant">present</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- My session -->
                    <div class="col-span-12 lg:col-span-4 space-y-4">
                        <div class="rounded-2xl p-6 text-white shadow-xl"
                             style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06)">
                            <h4 class="text-[11px] font-black uppercase tracking-widest text-white/60 mb-4">My Session</h4>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-[12px] font-bold text-white/70">Status</span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider bg-green-500/15 text-green-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-400 live-dot"></span>
                                        Clocked In
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[12px] font-bold text-white/70">Clock-in</span>
                                    <span class="text-[14px] font-black font-mono text-white">08:42 AM</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[12px] font-bold text-white/70">Hours today</span>
                                    <span class="text-[14px] font-black font-mono text-white">7h 18m</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[12px] font-bold text-white/70">This week</span>
                                    <span class="text-[14px] font-black font-mono text-white">36h 24m</span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[11px] font-black uppercase tracking-widest text-primary mb-3">Today's Events</h4>
                            <div class="space-y-3 text-[12px]">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px] text-green-600">login</span>
                                    <span class="text-on-surface flex-1">Clocked in</span>
                                    <span class="font-mono text-on-surface-variant">08:42</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px] text-amber-600">coffee</span>
                                    <span class="text-on-surface flex-1">Break (15m)</span>
                                    <span class="font-mono text-on-surface-variant">11:00</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px] text-blue-600">restaurant</span>
                                    <span class="text-on-surface flex-1">Lunch (45m)</span>
                                    <span class="font-mono text-on-surface-variant">13:00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance & Leave Dashboard Module -->
            <div v-if="activeModule === 'leave'" class="space-y-8 animate-reveal-up">
                <div class="grid grid-cols-12 gap-8">
                    <!-- Calendar/Overview -->
                    <div class="col-span-12 lg:col-span-8 rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm">
                        <div class="flex items-center justify-between mb-8">
                            <h3 class="text-xl font-black text-primary">Attendance Overview</h3>
                            <div class="flex gap-2">
                                <button @click="attendanceScope = 'today'"
                                        type="button"
                                        class="rounded-xl border px-3 py-2 text-xs font-bold transition-all"
                                        :class="attendanceScope === 'today' ? 'border-secondary bg-secondary/10 text-secondary' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'">
                                    Today
                                </button>
                                <button @click="attendanceScope = 'week'"
                                        type="button"
                                        class="rounded-xl border px-3 py-2 text-xs font-bold transition-all"
                                        :class="attendanceScope === 'week' ? 'border-secondary bg-secondary/10 text-secondary' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'">
                                    This Week
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-7 gap-4 mb-8">
                            <div v-for="day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="day" class="text-center">
                                <p class="text-[10px] font-black uppercase text-on-surface-variant mb-2">{{ day }}</p>
                                <div class="h-24 rounded-2xl bg-surface-container-low flex flex-col items-center justify-center gap-1 group hover:bg-secondary/5 transition-all cursor-pointer">
                                    <span class="text-sm font-black text-primary">12</span>
                                    <div class="flex gap-0.5">
                                        <div class="h-1 w-1 rounded-full bg-green-500"></div>
                                        <div class="h-1 w-1 rounded-full bg-amber-500"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <form class="mt-8 pt-8 border-t border-outline-variant space-y-5" @submit.prevent="leaveForm.post(route('leave.store'))">
                            <h4 class="text-sm font-black text-primary uppercase tracking-widest">Quick Leave Application</h4>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase text-on-surface-variant">Start Date</label>
                                    <input v-model="leaveForm.start_date" type="date" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-2.5 text-xs" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase text-on-surface-variant">End Date</label>
                                    <input v-model="leaveForm.end_date" type="date" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-2.5 text-xs" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase text-on-surface-variant">Leave Type</label>
                                    <select v-model="leaveForm.type" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-2.5 text-xs">
                                        <option value="annual">Annual Leave</option>
                                        <option value="sick">Sick Leave</option>
                                        <option value="maternity">Maternity</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" :disabled="leaveForm.processing"
                                    class="w-full rounded-xl bg-secondary py-3 text-sm font-black text-white shadow-lg shadow-secondary/20 transition-all hover:bg-secondary/90 disabled:opacity-60">
                                {{ leaveForm.processing ? 'Submitting...' : 'Submit Request' }}
                            </button>
                        </form>
                    </div>

                    <!-- Leave Balances -->
                    <div class="col-span-12 lg:col-span-4 space-y-6">
                        <div class="rounded-3xl p-8 text-white shadow-xl"
                             style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06);">
                            <h4 class="text-sm font-black uppercase tracking-widest mb-6 text-white/60">Your Balance</h4>
                            <div class="space-y-6">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">Annual Leave</span>
                                    <span class="text-2xl font-black">18 <span class="text-[10px] font-medium text-white/50">DAYS</span></span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-surface-container-lowest/10 overflow-hidden">
                                    <div class="h-full w-[60%] bg-secondary rounded-full"></div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">Sick Leave</span>
                                    <span class="text-2xl font-black">05 <span class="text-[10px] font-medium text-white/50">DAYS</span></span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-surface-container-lowest/10 overflow-hidden">
                                    <div class="h-full w-[25%] bg-amber-500 rounded-full"></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                            <h4 class="text-xs font-black uppercase tracking-widest text-primary mb-4">Pending Approvals</h4>
                            <div class="space-y-4">
                                <div v-for="i in 2" :key="i" class="flex items-center gap-3 p-3 rounded-2xl bg-surface-container-low/50 border border-outline-variant/30">
                                    <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-secondary font-black text-[10px]">AM</div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-black text-primary truncate">Annual Leave</p>
                                        <p class="text-[9px] font-medium text-on-surface-variant">3 days â€¢ Oct 12-15</p>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-[8px] font-black uppercase">Pending</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recruitment Dashboard Module -->
            <div v-if="activeModule === 'recruitment'" class="space-y-8 animate-reveal-up">
                <div class="grid grid-cols-12 gap-8">
                    <!-- Job Postings -->
                    <div class="col-span-12 lg:col-span-8 rounded-3xl border border-outline-variant bg-surface-container-lowest p-1 shadow-sm overflow-hidden">
                        <div class="px-8 py-6 border-b border-outline-variant flex items-center justify-between">
                            <h3 class="text-xl font-black text-primary">Active Opportunities</h3>
                            <button @click="router.visit(route('jobs.index', { new: 1 }))"
                                    type="button"
                                    class="rounded-xl bg-secondary px-4 py-2 text-xs font-black text-white shadow-md shadow-secondary/10 hover:bg-secondary/90 transition-colors">
                                + Post Job
                            </button>
                        </div>
                        <div class="divide-y divide-outline-variant/40">
                            <div v-for="job in [
                                { title: 'Senior Solutions Architect', dept: 'Technology', apps: 42, status: 'Active' },
                                { title: 'HR Business Partner', dept: 'Human Resources', apps: 128, status: 'Closing Soon' }
                            ]" :key="job.title" class="p-8 hover:bg-surface-container-low/30 transition-all flex items-center justify-between group cursor-pointer">
                                <div>
                                    <h4 class="text-sm font-black text-primary group-hover:text-secondary transition-colors">{{ job.title }}</h4>
                                    <p class="text-[10px] font-medium text-on-surface-variant mt-1">{{ job.dept }} â€¢ 12 days left</p>
                                </div>
                                <div class="flex items-center gap-8">
                                    <div class="text-right">
                                        <p class="text-sm font-black text-primary">{{ job.apps }}</p>
                                        <p class="text-[9px] font-bold text-on-surface-variant uppercase">Applicants</p>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase border" :class="job.status === 'Active' ? 'bg-green-50 text-green-700 border-green-100' : 'bg-red-50 text-red-700 border-red-100'">{{ job.status }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Candidate Pipeline -->
                    <div class="col-span-12 lg:col-span-4 rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm">
                        <h4 class="text-sm font-black uppercase tracking-widest mb-6 text-primary">Candidate Pipeline</h4>
                        <div class="space-y-6">
                            <div v-for="stage in [
                                { name: 'Applied', count: 245, color: 'bg-blue-500' },
                                { name: 'Interviewing', count: 18, color: 'bg-secondary' },
                                { name: 'Offer Phase', count: 3, color: 'bg-green-500' }
                            ]" :key="stage.name" class="space-y-2">
                                <div class="flex items-center justify-between text-xs font-bold">
                                    <span class="text-on-surface-variant">{{ stage.name }}</span>
                                    <span class="text-primary">{{ stage.count }}</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-surface-container-low overflow-hidden">
                                    <div class="h-full rounded-full" :class="stage.color" :style="{ width: (stage.count / 3) + '%' }"></div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-8 pt-8 border-t border-outline-variant">
                            <h5 class="text-[10px] font-black uppercase text-on-surface-variant mb-4">Recent Candidates</h5>
                            <div class="space-y-4">
                                <div v-for="i in 3" :key="i" class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-surface-container-low border border-outline-variant overflow-hidden">
                                        <img :src="`https://i.pravatar.cc/150?u=${i+20}`" class="h-full w-full object-cover" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-black text-primary truncate">Candidate #{{ i+100 }}</p>
                                        <p class="text-[9px] font-medium text-on-surface-variant">Applied 2h ago</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payroll Dashboard Module -->
            <div v-if="activeModule === 'payroll'" class="space-y-8 animate-reveal-up">
                <div class="grid grid-cols-12 gap-8">
                    <!-- Summary Card -->
                    <div class="col-span-12 lg:col-span-4 rounded-3xl p-8 text-white shadow-xl flex flex-col justify-between overflow-hidden relative"
                         style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06);">
                        <div class="absolute -right-4 -top-4 opacity-10"><span class="material-symbols-outlined text-9xl">payments</span></div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-white/50 mb-2">Total Monthly Payroll</p>
                            <h3 class="text-4xl font-black">GHS 2.45M</h3>
                            <p class="text-xs font-medium text-white/60 mt-2">Next cycle ends in 4 days</p>
                        </div>
                        <div class="mt-12 space-y-4">
                            <div class="flex items-center justify-between text-xs font-bold">
                                <span>Processing Status</span>
                                <span class="text-secondary">85% Complete</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-surface-container-lowest/10 overflow-hidden">
                                <div class="h-full w-[85%] bg-secondary rounded-full"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Breakdown & Taxes -->
                    <div class="col-span-12 lg:col-span-8 rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm">
                        <h4 class="text-lg font-black text-primary mb-8">Cost Breakdown</h4>
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div class="space-y-6">
                                <div v-for="item in [
                                    { label: 'Basic Salary', amount: '1.8M', color: 'bg-secondary' },
                                    { label: 'Allowances', amount: '450K', color: 'bg-blue-400' },
                                    { label: 'Statutory Deductions', amount: '200K', color: 'bg-amber-500' }
                                ]" :key="item.label" class="flex items-center gap-4">
                                    <div class="h-10 w-10 rounded-xl flex items-center justify-center text-white" :class="item.color">
                                        <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-black text-primary">{{ item.label }}</span>
                                            <span class="text-xs font-black text-primary">GHS {{ item.amount }}</span>
                                        </div>
                                        <div class="h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                                            <div class="h-full rounded-full" :class="item.color" :style="{ width: '70%' }"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-2xl bg-surface-container-low/30 p-6 flex flex-col justify-center text-center">
                                <span class="material-symbols-outlined text-4xl text-secondary mb-3">verified_user</span>
                                <h5 class="text-sm font-black text-primary">Statutory Compliance</h5>
                                <p class="text-[10px] font-medium text-on-surface-variant mt-2 px-4">All tax filings and SSNIT contributions for the current period have been validated.</p>
                                <Link :href="route('reports.index')" class="mt-6 text-xs font-black text-secondary hover:underline">Download Compliance Report</Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Intelligence Reports Module -->
            <div v-if="activeModule === 'reports'" class="space-y-8 animate-reveal-up">
                <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
                    <div v-for="report in [
                        { title: 'Q3 Institutional Health', sub: 'Comprehensive audit of all HR modules.', icon: 'analytics', color: 'text-secondary bg-secondary/10' },
                        { title: 'Workforce Diversity Index', sub: 'Analysis of inclusion across all tiers.', icon: 'diversity_3', color: 'text-blue-500 bg-blue-50' },
                        { title: 'Financial Compensation Audit', sub: 'Quarterly review of payroll and taxes.', icon: 'payments', color: 'text-amber-500 bg-amber-50' }
                    ]" :key="report.title" class="rounded-3xl border border-outline-variant bg-surface-container-lowest p-8 shadow-sm group hover:border-secondary/30 transition-all cursor-pointer">
                        <div class="h-14 w-14 rounded-2xl flex items-center justify-center mb-6 transition-all group-hover:scale-110" :class="report.color">
                            <span class="material-symbols-outlined text-3xl">{{ report.icon }}</span>
                        </div>
                        <h4 class="text-lg font-black text-primary leading-tight">{{ report.title }}</h4>
                        <p class="mt-2 text-xs font-medium text-on-surface-variant leading-relaxed">{{ report.sub }}</p>
                        <div class="mt-8 flex items-center justify-between">
                            <span class="text-[9px] font-black uppercase text-on-surface-variant/60">Generated 2d ago</span>
                            <Link :href="route('reports.index')" class="h-8 w-8 rounded-lg border border-outline-variant flex items-center justify-center text-primary hover:bg-surface-container-low transition-all" :title="report.title + ' export'"><span class="material-symbols-outlined text-lg">download</span></Link>
                        </div>
                    </div>
                </div>
            </div>



            <!-- System Audit Logs Module -->
            <div v-if="activeModule === 'audit-logs'" class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                    <div class="px-8 py-6 border-b border-outline-variant flex items-center justify-between">
                        <h3 class="text-xl font-black text-primary">Full System Audit Log</h3>
                        <Link :href="route('reports.index')" class="rounded-xl border border-outline-variant px-4 py-2 text-xs font-bold text-primary hover:bg-surface-container-low">Export Audit CSV</Link>
                    </div>
                    <div class="canvas-scroll max-h-[420px] overflow-auto">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                <tr>
                                    <th class="px-8 py-4">Timestamp</th>
                                    <th class="px-6 py-4">Actor</th>
                                    <th class="px-6 py-4">Action</th>
                                    <th class="px-6 py-4">Module</th>
                                    <th class="px-8 py-4 text-right">Metadata</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/40">
                                <tr v-for="log in recentEvents" :key="log.id" class="hover:bg-surface-container-low/30 transition-all">
                                    <td class="px-8 py-4 text-xs font-mono font-bold text-on-surface-variant">2026-05-12 10:15</td>
                                    <td class="px-6 py-4 text-sm font-bold text-primary">Admin User</td>
                                    <td class="px-6 py-4 text-xs font-bold text-secondary">{{ log.event }}</td>
                                    <td class="px-6 py-4"><span class="px-2 py-0.5 rounded-full bg-surface-container-low text-[9px] font-black uppercase text-on-surface-variant border border-outline-variant">System</span></td>
                                    <td class="px-8 py-4 text-right"><Link :href="route('audit-logs.index')" class="text-on-surface-variant hover:text-primary" title="Open audit log"><span class="material-symbols-outlined text-xl">info</span></Link></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Institutional Module Placeholder (For newly added modules) -->
            <div v-if="['governance-old', 'assets-old', 'reports-old', 'audit-logs-old'].includes(activeModule)" class="flex flex-col items-center justify-center py-20 text-center animate-in zoom-in duration-500">
                <div class="mb-6 flex h-32 w-32 items-center justify-center rounded-[2.5rem] bg-secondary/10 text-secondary shadow-2xl shadow-secondary/5 relative">
                    <div class="absolute inset-0 rounded-[2.5rem] border-2 border-dashed border-secondary/20 animate-spin-slow"></div>
                    <span class="material-symbols-outlined text-6xl">construction</span>
                </div>
                <h3 class="text-3xl font-black text-primary">Calibrating Module</h3>
                <p class="mt-4 max-w-md text-base font-medium text-on-surface-variant leading-relaxed">
                    The <span class="font-bold text-secondary text-lg">{{ moduleLabel }}</span> module is currently being integrated with institutional data. Please check back later for full access.
                </p>
                <div class="mt-10 flex gap-4">
                    <button @click="activeModule = 'overview'" class="rounded-2xl bg-primary px-8 py-4 text-sm font-black text-white shadow-xl shadow-primary/20 hover:bg-primary/90 transition-all hover:scale-105 active:scale-95 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">arrow_back</span>
                        Return to Overview
                    </button>
                    <button @click="router.visit(route('tickets.index', { new: 1, subject: 'Module access request' }))" type="button" class="rounded-2xl border border-outline-variant px-8 py-4 text-sm font-black text-primary hover:bg-surface-container-low transition-all">
                        Request Access
                    </button>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 IT & TECHNOLOGY DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <DeptIt v-if="activeModule === 'dept-it'" :spark="deptSparkData.it" :tickets="tickets" />

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 HUMAN RESOURCES DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <DeptHr v-if="activeModule === 'dept-hr'" :spark="deptSparkData.hr" :employees="employees" :stats="stats" />

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 MARKETING DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <DeptMarketing v-if="activeModule === 'dept-marketing'" :spark="deptSparkData.marketing" />

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 FINANCE DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <DeptFinance v-if="activeModule === 'dept-finance'" :spark="deptSparkData.finance" />

            <!-- Dashboard Overview -->
            <div v-if="activeModule === 'overview'" class="space-y-8">
                <!-- Employee Portal Dashboard -->
                <div v-if="$page.props.auth.user.role === 'employee'" class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-3xl font-black text-primary">Good Morning, {{ $page.props.auth.user.name.split(' ')[0] }}!</h2>
                            <p class="mt-1 text-sm font-medium text-on-surface-variant italic">"Success is not final, failure is not fatal: it is the courage to continue that counts."</p>
                        </div>
                        <div class="flex items-center gap-4 rounded-2xl bg-surface-container-lowest border border-outline-variant p-4 shadow-sm">
                            <div class="h-10 w-10 rounded-full bg-amber-50 flex items-center justify-center text-amber-600">
                                <span class="material-symbols-outlined text-2xl">wb_sunny</span>
                            </div>
                            <div>
                                <p class="text-xs font-black text-primary">28Â°C Accra</p>
                                <p class="text-[10px] font-bold text-on-surface-variant uppercase">Mostly Sunny</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-8">
                        <!-- Left Column: Quick Access & Announcements -->
                        <div class="col-span-12 lg:col-span-8 space-y-8">
                            <!-- Quick Stats for Employees -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                                <div class="rounded-3xl border border-outline-variant bg-surface-container-lowest p-6 shadow-sm hover:border-secondary/30 transition-all cursor-pointer group">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Leave Balance</p>
                                    <div class="mt-4 flex items-baseline justify-between">
                                        <p class="text-3xl font-black text-primary">12</p>
                                        <span class="text-[10px] font-bold text-secondary uppercase tracking-tighter">Days Left</span>
                                    </div>
                                    <div class="mt-4 h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                                        <div class="h-full w-[60%] bg-secondary rounded-full"></div>
                                    </div>
                                </div>
                                <div class="rounded-3xl border border-outline-variant bg-surface-container-lowest p-6 shadow-sm hover:border-secondary/30 transition-all cursor-pointer group">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Performance</p>
                                    <div class="mt-4 flex items-baseline justify-between">
                                        <p class="text-3xl font-black text-primary">4.8</p>
                                        <span class="text-[10px] font-bold text-green-600 uppercase tracking-tighter">Excellent</span>
                                    </div>
                                    <div class="mt-4 flex items-center gap-1 text-amber-500">
                                        <span v-for="i in 5" :key="i" class="material-symbols-outlined text-sm" :class="i <= 4 ? 'fill-1' : ''">star</span>
                                    </div>
                                </div>
                                <div class="rounded-3xl border border-outline-variant bg-surface-container-lowest p-6 shadow-sm hover:border-secondary/30 transition-all cursor-pointer group">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Pending Tasks</p>
                                    <div class="mt-4 flex items-baseline justify-between">
                                        <p class="text-3xl font-black text-primary">04</p>
                                        <span class="text-[10px] font-bold text-amber-600 uppercase tracking-tighter">Action Needed</span>
                                    </div>
                                    <div class="mt-4 flex -space-x-2">
                                        <div v-for="i in 3" :key="i" class="h-6 w-6 rounded-full border-2 border-white bg-surface-container-low flex items-center justify-center text-[8px] font-black text-on-surface-variant uppercase">
                                            {{ ['TR', 'PR', 'SR'][i-1] }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Company Announcements -->
                            <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                                <div class="border-b border-outline-variant/50 bg-surface-container-lowest/80 px-7 py-5 flex items-center justify-between">
                                    <h3 class="text-xl font-black text-primary">Institutional Announcements</h3>
                                    <button @click="router.visit(route('notifications.index'))" type="button" class="text-xs font-black text-secondary hover:underline">View All</button>
                                </div>
                                <div class="p-8 space-y-6">
                                    <div v-for="announcement in [
                                        { title: 'New Hybrid Work Policy Calibration', category: 'Policy', date: '2h ago', author: 'HR Dept', urgent: true },
                                        { title: 'Quarterly Town Hall Meeting - Q3 2026', category: 'Events', date: '1d ago', author: 'Executive Office', urgent: false },
                                        { title: 'Internal Security Protocol Upgrade', category: 'System', date: '3d ago', author: 'IT Governance', urgent: false }
                                    ]" :key="announcement.title" class="group cursor-pointer">
                                        <div class="flex gap-6 items-start">
                                            <div class="h-12 w-12 rounded-2xl flex-shrink-0 flex items-center justify-center" :class="announcement.urgent ? 'bg-red-50 text-red-600' : 'bg-surface-container-low text-primary'">
                                                <span class="material-symbols-outlined text-2xl">{{ announcement.urgent ? 'emergency_home' : 'campaign' }}</span>
                                            </div>
                                            <div class="flex-1 border-b border-outline-variant/50 pb-6 group-last:border-none group-last:pb-0">
                                                <div class="flex items-center justify-between mb-1">
                                                    <span class="text-[10px] font-black uppercase tracking-widest" :class="announcement.urgent ? 'text-red-600' : 'text-on-surface-variant'">{{ announcement.category }}</span>
                                                    <span class="text-[10px] font-bold text-on-surface-variant">{{ announcement.date }}</span>
                                                </div>
                                                <h4 class="text-base font-black text-primary group-hover:text-secondary transition-colors">{{ announcement.title }}</h4>
                                                <p class="mt-1 text-xs font-medium text-on-surface-variant">Published by {{ announcement.author }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- My Recent Activity / Timeline -->
                            <div class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8">
                                <h3 class="text-xl font-black text-primary mb-8">Personal Timeline</h3>
                                <div class="space-y-8 relative">
                                    <div class="absolute left-4 top-2 bottom-2 w-[2px] bg-surface-container-low"></div>
                                    <div v-for="event in [
                                        { time: '09:00 AM', title: 'Clocked In', sub: 'Successfully recorded attendance from Accra HQ', icon: 'login', color: 'text-green-600 bg-green-50' },
                                        { time: '11:30 AM', title: 'Payslip Generated', sub: 'Your payslip for May 2026 is now available for download', icon: 'payments', color: 'text-secondary bg-secondary/10' },
                                        { time: '02:00 PM', title: 'Performance Review', sub: 'Q2 Performance review session scheduled with Manager', icon: 'event', color: 'text-blue-600 bg-blue-50' }
                                    ]" :key="event.title" class="flex gap-8 relative z-10">
                                        <div class="h-8 w-8 rounded-full border-4 border-white flex-shrink-0 flex items-center justify-center shadow-sm" :class="event.color">
                                            <span class="material-symbols-outlined text-sm">{{ event.icon }}</span>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-3 mb-1">
                                                <span class="text-[10px] font-black text-on-surface-variant uppercase tracking-widest">{{ event.time }}</span>
                                                <h5 class="text-sm font-black text-primary">{{ event.title }}</h5>
                                            </div>
                                            <p class="text-xs font-medium text-on-surface-variant leading-relaxed">{{ event.sub }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: My Actions & Quick Tools -->
                        <div class="col-span-12 lg:col-span-4 space-y-8">
                            <!-- Latest Payslip Card -->
                            <div class="rounded-3xl p-8 text-white shadow-2xl relative overflow-hidden group cursor-pointer"
                                 style="background:linear-gradient(135deg,#0c0e14,#1a1d2e);border:1px solid rgba(255,255,255,0.06);">
                                <div class="absolute -right-4 -top-4 opacity-10 group-hover:scale-110 transition-transform">
                                    <span class="material-symbols-outlined text-9xl">receipt_long</span>
                                </div>
                                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-white/50 mb-6">Latest Payslip</p>
                                <div class="flex items-center justify-between mb-8">
                                    <div>
                                        <h4 class="text-2xl font-black leading-tight">May 2026</h4>
                                        <p class="text-xs font-bold text-secondary">Successfully Processed</p>
                                    </div>
                                    <div class="h-12 w-12 rounded-2xl bg-surface-container-lowest/10 flex items-center justify-center backdrop-blur-md">
                                        <span class="material-symbols-outlined">download</span>
                                    </div>
                                </div>
                                <div class="space-y-4 pt-4 border-t border-white/10">
                                    <div class="flex items-center justify-between text-xs font-bold">
                                        <span class="text-white/70">Net Pay</span>
                                        <span>GHS 12,450.00</span>
                                    </div>
                                    <Link :href="route('payments.index')" class="block w-full text-center rounded-xl bg-secondary py-3 text-sm font-black text-white shadow-lg shadow-secondary/20 hover:bg-secondary/90 transition-all">
                                        View Full Statement
                                    </Link>
                                </div>
                            </div>

                            <!-- My Actions / Tasks -->
                            <div class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8">
                                <div class="flex items-center justify-between mb-6">
                                    <h4 class="text-sm font-black text-primary uppercase tracking-widest">My Actions</h4>
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-secondary text-[10px] font-black text-white">4</span>
                                </div>
                                <div class="space-y-4">
                                    <div v-for="action in [
                                        { title: 'Upload ID Document', sub: 'Onboarding compliance', priority: 'High', color: 'border-l-red-500' },
                                        { title: 'Review Benefits Plan', sub: 'Annual update', priority: 'Medium', color: 'border-l-amber-500' },
                                        { title: 'Mandatory Safety Training', sub: 'Internal certification', priority: 'Medium', color: 'border-l-blue-500' }
                                    ]" :key="action.title" class="p-4 rounded-2xl bg-surface-container-low/50 border border-outline-variant/30 border-l-4 hover:bg-white hover:shadow-md transition-all cursor-pointer" :class="action.color">
                                        <h5 class="text-xs font-black text-primary">{{ action.title }}</h5>
                                        <p class="text-[10px] font-medium text-on-surface-variant mt-0.5">{{ action.sub }}</p>
                                    </div>
                                </div>
                                <button @click="router.visit(route('tickets.index', { assignee: 'me' }))" type="button" class="mt-6 w-full rounded-xl border border-outline-variant py-3 text-xs font-black text-primary hover:bg-surface-container-low transition-all">View All Tasks</button>
                            </div>

                            <!-- Today's Schedule -->
                            <div class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8">
                                <h4 class="text-sm font-black text-primary uppercase tracking-widest mb-6">Today's Schedule</h4>
                                <div class="space-y-6">
                                    <div v-for="slot in [
                                        { time: '10:00 - 11:00', title: 'Daily Standup', room: 'Conference Room B' },
                                        { time: '14:00 - 15:30', title: 'Design Sync', room: 'Virtual Meet' }
                                    ]" :key="slot.time" class="flex items-start gap-4">
                                        <div class="text-[10px] font-black text-on-surface-variant w-20 pt-1 uppercase">{{ slot.time }}</div>
                                        <div class="flex-1">
                                            <h5 class="text-xs font-black text-primary">{{ slot.title }}</h5>
                                            <p class="text-[10px] font-medium text-on-surface-variant mt-0.5">{{ slot.room }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admin / HR Executive Overview -->
                <section v-if="['super_admin', 'hr_admin'].includes($page.props.auth.user.role)" class="space-y-8 animate-reveal-up">

                    <!-- Hero Greeting Banner -->
                    <div class="relative overflow-hidden rounded-3xl px-10 py-8 text-white"
                         style="background:linear-gradient(135deg,#0c0e14 0%,#131620 100%);border:1px solid rgba(255,255,255,0.06);">
                        <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full bg-secondary/10 blur-3xl"></div>
                        <div class="pointer-events-none absolute bottom-0 left-1/3 h-48 w-48 rounded-full blur-2xl" style="background:rgba(124,92,255,0.07);"></div>
                        <div class="relative flex flex-wrap items-center justify-between gap-8">
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(255,255,255,0.35)">CIHRM Ghana Â· Executive Console</p>
                                <h2 class="text-3xl font-black leading-tight">
                                    Good Morning, {{ $page.props.auth.user.name.split(' ')[0] }}.
                                </h2>
                                <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">All systems are operational â€” here's your institutional snapshot.</p>
                                <div class="mt-6 flex items-center gap-3">
                                    <button @click="router.visit(route('employees.index', { new: 1 }))"
                                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                                            style="background:linear-gradient(135deg,#0a2647,#205295);">
                                        <span class="material-symbols-outlined text-[17px]">person_add</span>
                                        Add Employee
                                    </button>
                                    <button @click="router.visit(route('jobs.index', { new: 1 }))"
                                            class="flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold transition-all hover:-translate-y-px active:scale-[0.97]"
                                            style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.8);">
                                        <span class="material-symbols-outlined text-[17px]">work</span>
                                        Post Job
                                    </button>
                                    <button @click="router.visit(route('leave.index', { new: 1 }))"
                                            class="flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold transition-all hover:-translate-y-px active:scale-[0.97]"
                                            style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.8);">
                                        <span class="material-symbols-outlined text-[17px]">calendar_month</span>
                                        Leave Request
                                    </button>
                                </div>
                            </div>
                            <!-- Hero Inline KPIs â€” live -->
                            <div class="flex items-center gap-8 flex-shrink-0">
                                <div v-for="kpi in [
                                    { label: 'Active Staff',  val: Math.round(sparkData.employees[sparkData.employees.length-1]) },
                                    { label: 'Open Tickets',  val: Math.round(sparkData.tickets[sparkData.tickets.length-1]) },
                                    { label: 'Pending Leave', val: Math.round(sparkData.leave[sparkData.leave.length-1]) },
                                ]" :key="kpi.label" class="text-center">
                                    <p class="text-4xl font-black leading-none kpi-val">{{ kpi.val }}</p>
                                    <p class="mt-1.5 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live KPI Cards with Sparklines -->
                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <div v-for="(card, i) in kpiCards" :key="card.label"
                             class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all duration-300 hover:shadow-md hover:-translate-y-0.5"
                             :style="`animation:slideUpFade 0.45s cubic-bezier(0.22,1,0.36,1) both;animation-delay:${i*0.07}s`">
                            <!-- Live badge -->
                            <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                                <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
                                <span class="text-[7.5px] font-black uppercase tracking-widest" :style="`color:${card.color};opacity:0.65`">live</span>
                            </div>
                            <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-xl transition-transform group-hover:scale-110"
                                 :style="`background:rgba(${card.rgb},0.1)`">
                                <span class="material-symbols-outlined text-[18px]" :style="`color:${card.color};font-variation-settings:'FILL' 1`">{{ card.icon }}</span>
                            </div>
                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                            <p class="mt-1.5 text-2xl font-black text-primary leading-none kpi-val">{{ card.display }}</p>
                            <p class="mt-1 text-[10px] font-semibold" :style="`color:${card.up ? '#059669' : '#d97706'}`">
                                {{ card.up ? 'â†‘' : 'â†“' }} {{ card.trend }}
                            </p>
                            <!-- Live sparkline (animated draw-in + hover crosshair + glow dot) -->
                            <div class="-mx-1 mt-3">
                                <Sparkline :data="card.spark" :color="card.color" :width="96" :height="32"
                                           :stroke-width="1.6" :label="card.label" class="!block w-full"/>
                            </div>
                        </div>
                    </div>

                    <!-- Live Analytics Row: Bar Chart + Activity Feed -->
                    <div class="grid gap-6 lg:grid-cols-12">

                        <!-- Workforce Trend Bar Chart -->
                        <div class="lg:col-span-8 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 overflow-hidden flex flex-col">
                            <div class="mb-5 flex items-center justify-between flex-shrink-0">
                                <div>
                                    <h4 class="text-[13px] font-black text-primary">Approved Leave by Month</h4>
                                    <p class="text-[10px] font-medium text-on-surface-variant mt-0.5">Monthly leave volume Â· {{ new Date().getFullYear() }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center gap-1.5">
                                        <span class="h-2 w-4 rounded-full" style="background:linear-gradient(90deg,#205295,#2c74b3)"></span>
                                        <span class="text-[9.5px] font-bold text-on-surface-variant">Leave requests</span>
                                    </div>
                                </div>
                            </div>

                            <!-- LiveBars: analytical animation + live shimmer + hover crosshair + gold peak accent -->
                            <div class="flex-1 min-h-[160px] max-h-[300px]">
                                <LiveBars
                                    :data="chartLeaveByMonth.map((v,i) => ({ label: ['J','F','M','A','M','J','J','A','S','O','N','D'][i], value: v }))"
                                    :height="260"
                                    :format-value="v => `${v} requests`"
                                    color="#205295"
                                    accent-color="#ffd700"
                                    second-color="#12d9e3"
                                    :show-median="true"
                                    :rounded="6"
                                />
                            </div>
                        </div>

                        <!-- Live Activity Feed â€” cap height so it doesn't drive the row taller than the chart -->
                        <div class="lg:col-span-4 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden flex flex-col max-h-[420px]">
                            <div class="flex items-center justify-between px-5 py-4 border-b border-outline-variant/50 flex-shrink-0">
                                <h4 class="text-[13px] font-black text-primary">Live Activity</h4>
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2 w-2 rounded-full bg-green-400 live-dot"></span>
                                    <span class="text-[9px] font-black uppercase tracking-widest text-green-600">Streaming</span>
                                </div>
                            </div>
                            <div class="flex-1 overflow-y-auto relative p-4">
                                <div class="sticky top-0 left-0 right-0 h-4 -mt-4 -mx-4 mb-0 z-10 pointer-events-none" style="background:linear-gradient(to bottom,rgba(255,255,255,1) 30%,transparent)"></div>
                                <TransitionGroup name="feed-anim" tag="div" class="space-y-3">
                                    <div v-for="item in liveActivity" :key="item.text"
                                         class="flex items-start gap-3 rounded-xl p-3 transition-all"
                                         style="background:rgba(0,0,0,0.02);border:1px solid rgba(0,0,0,0.04)">
                                        <div class="h-7 w-7 rounded-xl flex-shrink-0 flex items-center justify-center mt-0.5"
                                             :style="`background:${item.color}15;`">
                                            <span class="material-symbols-outlined text-[14px]" :style="`color:${item.color};font-variation-settings:'FILL' 1`">{{ item.icon }}</span>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[11px] font-semibold text-on-surface leading-snug">{{ item.text }}</p>
                                            <p class="text-[9.5px] font-bold text-on-surface-variant mt-0.5">{{ item.time }}</p>
                                        </div>
                                    </div>
                                </TransitionGroup>
                            </div>
                        </div>
                    </div>

                    <!-- Main Grid -->
                    <div class="grid gap-8 lg:grid-cols-12">

                        <!-- Left: Recent Employees + Open Tickets -->
                        <div class="space-y-8 lg:col-span-8">

                            <!-- Recent Employees Table -->
                            <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                                <div class="border-b border-outline-variant bg-surface-container-lowest px-7 py-5 flex items-center justify-between">
                                    <h3 class="text-lg font-black text-primary flex items-center gap-2">
                                        <span class="material-symbols-outlined text-secondary text-xl" style="font-variation-settings:'FILL' 1">badge</span>
                                        Workforce Directory
                                    </h3>
                                    <div class="flex items-center gap-3">
                                        <button @click="router.visit(route('employees.index', { new: 1 }))"
                                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px active:scale-[0.97]"
                                                style="background:linear-gradient(135deg,#0a2647,#205295);">
                                            <span class="material-symbols-outlined text-[16px]">add</span>
                                            New Employee
                                        </button>
                                    </div>
                                </div>
                                <div class="canvas-scroll max-h-[340px] overflow-auto">
                                    <table class="w-full text-left">
                                        <thead class="sticky top-0 z-10">
                                            <tr class="bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                                <th class="px-7 py-4">Employee</th>
                                                <th class="px-6 py-4">Department</th>
                                                <th class="px-6 py-4">Status</th>
                                                <th class="px-6 py-4">Since</th>
                                                <th class="px-7 py-4 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-outline-variant/40">
                                            <tr v-for="employee in employees" :key="employee.id" class="group hover:bg-surface-container-low/30 transition-colors">
                                                <td class="px-7 py-4">
                                                    <div class="flex items-center gap-3">
                                                        <div class="h-9 w-9 rounded-full bg-secondary/10 flex items-center justify-center font-bold text-secondary text-sm border-2 border-white shadow-sm overflow-hidden">
                                                            <img v-if="employee.avatar" :src="employee.avatar" class="h-full w-full object-cover" />
                                                            <span v-else>{{ employee.employee_no?.charAt(0) }}</span>
                                                        </div>
                                                        <div>
                                                            <p class="text-sm font-black text-primary leading-tight">{{ employee.user?.name || 'Staff Member' }}</p>
                                                            <p class="text-[10px] font-medium text-on-surface-variant">{{ employee.position }}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 text-xs font-bold text-on-surface-variant">{{ employee.department?.name || 'â€”' }}</td>
                                                <td class="px-6 py-4">
                                                    <span class="inline-flex rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-wider border" :class="getStatusColor(employee.status || 'active')">
                                                        {{ employee.status_label || employee.status || 'Active' }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 text-xs font-bold text-on-surface-variant">{{ employee.hire_date || 'â€”' }}</td>
                                                <td class="px-7 py-4 text-right">
                                                    <Link :href="route('employees.show', employee.id)" class="text-on-surface-variant hover:text-secondary transition-colors" title="Open profile">
                                                        <span class="material-symbols-outlined text-xl">open_in_new</span>
                                                    </Link>
                                                </td>
                                            </tr>
                                            <tr v-if="!employees.length">
                                                <td colspan="5" class="px-7 py-10 text-center text-sm font-bold text-on-surface-variant italic">No employees found.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="border-t border-outline-variant bg-surface-container-lowest px-7 py-4 flex items-center justify-between">
                                    <p class="text-xs font-bold text-on-surface-variant">Showing {{ employees.length }} of {{ stats.employees || 0 }} employees</p>
                                    <Link :href="route('employees.index')" class="text-xs font-black text-secondary hover:underline">View Full Directory â†’</Link>
                                </div>
                            </div>

                            <!-- Open Service Tickets -->
                            <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                                <div class="border-b border-outline-variant bg-surface-container-lowest px-7 py-5 flex items-center justify-between">
                                    <h3 class="text-lg font-black text-primary flex items-center gap-2">
                                        <span class="material-symbols-outlined text-secondary text-xl" style="font-variation-settings:'FILL' 1">support_agent</span>
                                        Open Service Tickets
                                    </h3>
                                    <div class="flex items-center gap-3">
                                        <div class="flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 border border-red-100">
                                            <span class="h-1.5 w-1.5 rounded-full bg-red-500 animate-pulse"></span>
                                            <span class="text-[10px] font-black text-red-700 uppercase tracking-wider">{{ tickets.length }} Active</span>
                                        </div>
                                        <button @click="router.visit(route('tickets.index', { new: 1 }))" class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-1.5 text-xs font-bold text-primary hover:bg-surface-container-low transition-all">+ New Ticket</button>
                                    </div>
                                </div>
                                <div class="canvas-scroll max-h-[340px] overflow-auto">
                                    <table class="w-full text-left">
                                        <thead class="sticky top-0 z-10">
                                            <tr class="bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                                <th class="px-7 py-4">Issue</th>
                                                <th class="px-6 py-4">Priority</th>
                                                <th class="px-6 py-4">Status</th>
                                                <th class="px-6 py-4">Created</th>
                                                <th class="px-7 py-4 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-outline-variant/40">
                                            <tr v-for="ticket in tickets" :key="ticket.id" class="group hover:bg-surface-container-low/30 transition-colors">
                                                <td class="px-7 py-4">
                                                    <p class="text-sm font-bold text-primary">{{ ticket.title }}</p>
                                                    <p class="text-[10px] font-medium text-on-surface-variant line-clamp-1">{{ ticket.description }}</p>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span class="inline-flex rounded-lg px-2.5 py-1 text-[10px] font-black uppercase tracking-wider border" :class="getStatusColor(ticket.priority)">{{ ticket.priority }}</span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span class="inline-flex rounded-lg px-2.5 py-1 text-[10px] font-black uppercase tracking-wider border" :class="getStatusColor(ticket.status)">{{ ticket.status }}</span>
                                                </td>
                                                <td class="px-6 py-4 text-xs font-bold text-on-surface-variant">2d ago</td>
                                                <td class="px-7 py-4 text-right">
                                                    <Link :href="route('tickets.show', ticket.id)" class="text-on-surface-variant hover:text-secondary transition-colors" title="Open ticket">
                                                        <span class="material-symbols-outlined text-xl">open_in_new</span>
                                                    </Link>
                                                </td>
                                            </tr>
                                            <tr v-if="!tickets.length">
                                                <td colspan="5" class="px-7 py-10 text-center text-sm font-bold text-on-surface-variant italic">No open tickets found.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Quick Actions + Audit Trail -->
                        <div class="lg:col-span-4 space-y-6">

                            <!-- Quick Actions Panel -->
                            <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                                <div class="border-b border-outline-variant bg-surface-container-lowest px-6 py-4">
                                    <h3 class="text-sm font-black text-primary uppercase tracking-widest">Quick Actions</h3>
                                </div>
                                <div class="p-5 grid grid-cols-2 gap-3">
                                    <button @click="router.visit(route('employees.index', { new: 1 }))"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-secondary/30 hover:bg-secondary/5 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-secondary/10 flex items-center justify-center text-secondary transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">person_add</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Add Employee</span>
                                    </button>
                                    <button @click="router.visit(route('leave.index', { new: 1 }))"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-green-300 hover:bg-green-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-green-50 flex items-center justify-center text-green-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">calendar_month</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Leave Request</span>
                                    </button>
                                    <button @click="router.visit(route('tickets.index', { new: 1 }))"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-amber-300 hover:bg-amber-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">confirmation_number</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">New Ticket</span>
                                    </button>
                                    <button @click="router.visit(route('jobs.index', { new: 1 }))"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-blue-300 hover:bg-blue-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">work</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Post Job</span>
                                    </button>
                                    <button @click="router.visit(route('departments.index'))"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-cyan-300 hover:bg-cyan-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-cyan-50 flex items-center justify-center text-cyan-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">corporate_fare</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Add Dept.</span>
                                    </button>
                                    <Link :href="route('payments.index')"
                                          class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-rose-300 hover:bg-rose-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-rose-50 flex items-center justify-center text-rose-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">payments</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Run Payroll</span>
                                    </Link>
                                </div>
                            </div>

                            <!-- System Audit Trail -->
                            <div class="overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
                                <div class="border-b border-outline-variant bg-surface-container-lowest px-6 py-4 flex items-center justify-between">
                                    <h3 class="text-sm font-black text-primary uppercase tracking-widest">Audit Trail</h3>
                                    <span class="h-2 w-2 rounded-full bg-green-500 animate-pulse"></span>
                                </div>
                                <div class="canvas-scroll max-h-[360px] overflow-y-auto p-5 space-y-5">
                                    <div v-for="(event, idx) in recentEvents" :key="event.id" class="flex gap-4 relative">
                                        <div v-if="idx !== recentEvents.length - 1"
                                             class="absolute left-4 top-8 bottom-0 w-[1px] bg-outline-variant/60"></div>
                                        <div class="h-8 w-8 rounded-full bg-surface-container-low border border-outline-variant flex-shrink-0 flex items-center justify-center z-10">
                                            <span class="material-symbols-outlined text-sm text-on-surface-variant">history</span>
                                        </div>
                                        <div class="space-y-0.5 min-w-0">
                                            <p class="text-xs font-bold text-primary leading-snug line-clamp-2">{{ event.event }}</p>
                                            <p class="text-[10px] font-medium text-on-surface-variant">Just now â€¢ <span class="text-secondary">System</span></p>
                                        </div>
                                    </div>
                                    <div v-if="!recentEvents.length" class="py-6 text-center text-xs font-bold text-on-surface-variant italic">No recent activity.</div>
                                </div>
                                <div class="px-5 pb-5">
                                    <Link :href="route('audit-logs.index')" class="block w-full text-center rounded-xl border border-outline-variant py-3 text-xs font-black text-primary hover:bg-surface-container-low transition-all">
                                        View Full Audit Log
                                    </Link>
                                </div>
                            </div>

                            <!-- AI Workforce Insight -->
                            <div class="overflow-hidden rounded-2xl text-white relative"
                                 style="background:linear-gradient(135deg,#0a2647,#205295);border:1px solid rgba(255,255,255,0.1);">
                                <div class="absolute -right-4 -top-4 opacity-10">
                                    <span class="material-symbols-outlined text-8xl">auto_awesome</span>
                                </div>
                                <div class="relative p-6">
                                    <div class="flex items-center gap-2 mb-4">
                                        <span class="material-symbols-outlined text-white/80 text-xl" style="font-variation-settings:'FILL' 1">psychology</span>
                                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60">AI Insight</p>
                                    </div>
                                    <p class="text-sm font-bold leading-relaxed">Staff retention is at <span class="text-white font-black">94%</span> â€” above the 90% institutional target. Consider initiating a recognition programme to sustain momentum.</p>
                                    <button @click="router.visit(route('reports.index'))" type="button"
                                            class="mt-5 w-full rounded-xl py-2.5 text-xs font-black text-white transition-all hover:bg-white/20"
                                            style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);">
                                        View AI Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>

</template>

<style scoped>
/* â”€â”€ Internal canvas scroll (sticky-header tables, activity feeds) â”€â”€â”€ */
.canvas-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
.canvas-scroll::-webkit-scrollbar-track { background: transparent; }
.canvas-scroll::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, 0.25);
    border-radius: 8px;
    border: 2px solid transparent;
    background-clip: padding-box;
}
.canvas-scroll::-webkit-scrollbar-thumb:hover {
    background-color: rgba(100, 116, 139, 0.45);
    background-clip: padding-box;
}

/* â”€â”€ Live dot pulse â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.live-dot {
    animation: liveDot 1.6s ease-in-out infinite;
}
@keyframes liveDot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.3; transform: scale(0.75); }
}

/* â”€â”€ KPI number transition â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.kpi-val {
    transition: all 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}

/* â”€â”€ Activity feed transition â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.feed-anim-enter-active { transition: all 0.4s cubic-bezier(0.22, 1, 0.36, 1); }
.feed-anim-leave-active { transition: all 0.25s ease; position: absolute; width: 100%; }
.feed-anim-enter-from   { opacity: 0; transform: translateY(-10px); }
.feed-anim-leave-to     { opacity: 0; transform: translateY(8px); }
.feed-anim-move         { transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1); }

/* â”€â”€ Bar tooltip fix (overflow visible on parent) â”€â”€â”€â”€â”€ */
.group { isolation: isolate; }

/* â”€â”€ Slide-up stagger keyframe (referenced inline) â”€â”€â”€â”€â”€ */
@keyframes slideUpFade {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>