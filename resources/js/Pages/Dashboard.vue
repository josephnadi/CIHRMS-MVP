<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useToast } from '@/composables/useToast';

const { comingSoon, success } = useToast();

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

// Modal visibility state
const showAddEmployeeModal = ref(false);
const showAddDeptModal = ref(false);
const showLeaveModal = ref(false);
const showTicketModal = ref(false);
const showJobModal = ref(false);

const selectEmployee = (employee) => {
    selectedEmployee.value = employee;
};

const departmentForm = useForm({ name: '', code: '', description: '' });
const employeeForm = useForm({ department_id: '', employee_no: '', position: '', hire_date: '', phone: '' });
const leaveForm = useForm({ employee_id: '', start_date: '', end_date: '', type: 'annual', reason: '' });
const ticketForm = useForm({ employee_id: '', title: '', description: '', priority: 'medium', due_at: '' });
const jobForm = useForm({ title: '', description: '', closes_at: '' });

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
const activityPool = [
    { text: 'New hire onboarded â€” Ama Asante (Technology)', icon: 'person_add',    color: '#316bf3', time: '2m ago' },
    { text: 'Leave approved â€” K. Boateng, 5 days annual',  icon: 'calendar_today', color: '#d97706', time: '5m ago' },
    { text: 'Payroll cycle completed â€” 1,284 staff paid',   icon: 'payments',       color: '#059669', time: '8m ago' },
    { text: 'Ticket #SD-1029 escalated to IT Lead',         icon: 'warning',        color: '#dc2626', time: '12m ago' },
    { text: 'Q2 Performance reports generated',             icon: 'analytics',      color: '#7c5cff', time: '18m ago' },
    { text: 'Compliance audit: Grade A+ certified',         icon: 'verified_user',  color: '#059669', time: '25m ago' },
    { text: 'Job posted â€” HR Business Partner role',        icon: 'work',           color: '#0891b2', time: '31m ago' },
    { text: 'Security scan completed â€” all clear',          icon: 'shield',         color: '#316bf3', time: '44m ago' },
];
const liveActivity = computed(() =>
    Array.from({ length: 5 }, (_, i) => activityPool[(feedIdx.value + i) % activityPool.length])
);

const kpiCards = computed(() => {
    const e = sparkData.value.employees;
    const t = sparkData.value.tickets;
    const l = sparkData.value.leave;
    const c = sparkData.value.compliance;
    const s = props.stats ?? {};
    return [
        { label: 'Active Staff',    display: (s.employees ?? 0).toLocaleString(),                                       trend: s.openJobs ? `${s.openJobs} open roles` : 'Workforce',     icon: 'badge',          color: '#316bf3', rgb: '49,107,243', spark: e, up: true  },
        { label: 'Open Tickets',    display: s.openTickets ?? 0,                                                        trend: 'Service desk',                                            icon: 'support_agent',  color: '#dc2626', rgb: '220,38,38',  spark: t, up: false },
        { label: 'Pending Leave',   display: s.pendingLeave ?? 0,                                                       trend: 'Awaiting approval',                                       icon: 'calendar_today', color: '#d97706', rgb: '217,119,6',  spark: l, up: false },
        { label: 'Pending Payroll', display: s.pendingPayments ?? 0,                                                    trend: s.openComplaints ? `${s.openComplaints} complaints` : 'â€”', icon: 'payments',       color: '#059669', rgb: '5,150,105',  spark: c, up: true  },
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
        feedIdx.value = (feedIdx.value + 1) % activityPool.length;
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
                    <!-- Live sync indicator: pulses while reloading, otherwise shows seconds since last refresh -->
                    <div class="flex items-center gap-1.5 rounded-full px-3 py-1.5 border"
                         :class="isSyncing
                            ? 'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-950/40 dark:border-blue-800/40 dark:text-blue-300'
                            : 'bg-green-50 border-green-100 text-green-700 dark:bg-green-950/40 dark:border-green-800/40 dark:text-green-300'">
                        <span class="h-1.5 w-1.5 rounded-full"
                              :class="isSyncing ? 'bg-blue-500 animate-pulse' : 'bg-green-500 live-dot'"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest">
                            {{ isSyncing ? 'Syncingâ€¦' : `Live Â· ${syncAgoLabel}` }}
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
                                <button @click="showAddEmployeeModal = true"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-95"
                                        style="background:linear-gradient(135deg,#0051d5,#316bf3);">
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
                                    <div class="h-10 w-10 rounded-xl bg-purple-100 flex items-center justify-center text-purple-600">
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
                        { label: 'Open Tickets',    val: Math.round(sparkData.tickets[sparkData.tickets.length-1]), badge: '12 critical',  badgeColor: 'bg-red-50 text-red-700',    icon: 'confirmation_number', color: '#dc2626', rgb: '220,38,38',   spark: sparkData.tickets    },
                        { label: 'Avg Resolution',  val: '4.2h',                                                    badge: 'â†‘ improving',  badgeColor: 'bg-green-50 text-green-700', icon: 'timer',               color: '#059669', rgb: '5,150,105',   spark: sparkData.compliance },
                        { label: 'SLA Compliance',  val: sparkData.compliance[sparkData.compliance.length-1].toFixed(1)+'%', badge: 'Target 95%', badgeColor: 'bg-green-50 text-green-700', icon: 'verified', color: '#059669', rgb: '5,150,105', spark: sparkData.compliance },
                        { label: 'Pending Review',  val: '8',                                                       badge: '3 escalated',  badgeColor: 'bg-amber-50 text-amber-700', icon: 'inbox',               color: '#d97706', rgb: '217,119,6',   spark: sparkData.leave      },
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
                            <svg viewBox="0 0 96 24" class="w-full" style="height:24px;overflow:visible">
                                <defs>
                                    <linearGradient :id="`tsg${si}`" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" :stop-color="stat.color" stop-opacity="0.18"/>
                                        <stop offset="100%" :stop-color="stat.color" stop-opacity="0.01"/>
                                    </linearGradient>
                                </defs>
                                <path :d="sparkArea(stat.spark, 96, 24)" :fill="`url(#tsg${si})`"/>
                                <polyline :points="sparkLine(stat.spark, 96, 24)" fill="none" :stroke="stat.color" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
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
                         style="background:rgba(0,81,213,0.03);border:1px solid rgba(0,81,213,0.1);">
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
                <button @click="showTicketModal = true"
                        type="button"
                        title="Create new ticket"
                        class="btn-shimmer fixed bottom-8 right-8 flex h-14 w-14 items-center justify-center rounded-full text-white shadow-glow-lg transition-all hover:scale-110 hover:shadow-[0_0_48px_rgba(0,81,213,0.5)] active:scale-95 z-50"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3);">
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
                        <div class="flex h-52 items-end justify-between gap-1.5 px-1">
                            <div v-for="(h, i) in perfBarData" :key="i"
                                 class="group relative flex flex-1 flex-col items-center gap-2">
                                <div class="w-full rounded-t relative overflow-hidden cursor-default"
                                     :style="`height:${h}%;transition:height 0.9s cubic-bezier(0.22,1,0.36,1);`">
                                    <div class="absolute inset-0 rounded-t" style="background:linear-gradient(to top,#0051d5,rgba(99,131,255,0.6))"></div>
                                    <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity rounded-t" style="background:rgba(49,107,243,0.9)"></div>
                                    <div class="absolute -top-7 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity z-10 whitespace-nowrap rounded-lg px-2 py-1 text-[8px] font-black text-white" style="background:#0c0e14">{{ Math.round(h) }}%</div>
                                </div>
                                <span class="text-[8px] font-black text-on-surface-variant/40 uppercase">{{ ['J','F','M','A','M','J','J','A','S','O','N','D'][i] }}</span>
                            </div>
                        </div>
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
                                style="background:rgba(0,81,213,0.25);border-color:rgba(49,107,243,0.3);">
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
                            <button @click="showJobModal = true"
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

            <!-- Governance & Compliance Module -->
            <div v-if="activeModule === 'governance'" class="space-y-8 animate-reveal-up">
                <div class="grid grid-cols-12 gap-8">
                    <div class="col-span-12 lg:col-span-7 rounded-3xl border border-outline-variant bg-surface-container-lowest p-1 shadow-sm overflow-hidden">
                        <div class="px-8 py-6 border-b border-outline-variant flex items-center justify-between">
                            <h3 class="text-xl font-black text-primary">Compliance Audit</h3>
                            <span class="px-3 py-1 rounded-full bg-green-50 text-green-700 border border-green-100 text-[10px] font-black uppercase">Institutional Grade: A+</span>
                        </div>
                        <div class="p-8 space-y-8">
                            <div v-for="rule in [
                                { name: 'Labor Act 2003 Compliance', status: 'Passed', date: 'Oct 12, 2026' },
                                { name: 'Data Protection (GDPR/Act 843)', status: 'Passed', date: 'Oct 08, 2026' },
                                { name: 'Institutional Policy Review', status: 'In Review', date: 'Ongoing' }
                            ]" :key="rule.name" class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div class="h-10 w-10 rounded-xl bg-surface-container-low flex items-center justify-center text-primary">
                                        <span class="material-symbols-outlined">gavel</span>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-primary">{{ rule.name }}</p>
                                        <p class="text-[10px] font-medium text-on-surface-variant">Last verified: {{ rule.date }}</p>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase border" :class="rule.status === 'Passed' ? 'bg-green-50 text-green-700 border-green-100' : 'bg-amber-50 text-amber-700 border-amber-100'">{{ rule.status }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-span-12 lg:col-span-5 rounded-2xl p-8 text-white shadow-xl relative overflow-hidden"
                         style="background:linear-gradient(135deg,#0051d5,#316bf3);">
                        <h4 class="text-sm font-black uppercase tracking-widest mb-6 text-white/60">Risk Management</h4>
                        <div class="space-y-8">
                            <div class="p-5 rounded-2xl bg-surface-container-lowest/10 border border-white/10">
                                <h5 class="text-xs font-black mb-2">Policy Updates Required</h5>
                                <p class="text-[10px] font-medium text-white/70 leading-relaxed">Update the remote work policy to align with the latest executive mandate for hybrid operations.</p>
                                <button @click="comingSoon('Policy update workflow')" type="button" class="mt-4 text-[10px] font-black text-white hover:underline">Draft Update</button>
                            </div>
                            <div class="p-5 rounded-2xl bg-surface-container-lowest/10 border border-white/10">
                                <h5 class="text-xs font-black mb-2">Certification Expiry</h5>
                                <p class="text-[10px] font-medium text-white/70 leading-relaxed">34 staff certifications in the Technical department expire within 48 hours.</p>
                                <button @click="comingSoon('Bulk staff notifications')" type="button" class="mt-4 text-[10px] font-black text-white hover:underline">Notify Staff</button>
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
                    <button @click="comingSoon('Module access request')" type="button" class="rounded-2xl border border-outline-variant px-8 py-4 text-sm font-black text-primary hover:bg-surface-container-low transition-all">
                        Request Access
                    </button>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 IT & TECHNOLOGY DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeModule === 'dept-it'" class="space-y-6 animate-reveal-up">

                <!-- Hero Banner -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white"
                     style="background:linear-gradient(135deg,#0c0e14 0%,#111827 100%);border:1px solid rgba(255,255,255,0.06);">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(0,81,213,0.25),transparent 70%)"></div>
                    <div class="relative flex flex-wrap items-center justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="h-14 w-14 rounded-2xl flex items-center justify-center flex-shrink-0" style="background:rgba(49,107,243,0.2);border:1px solid rgba(49,107,243,0.3)">
                                <span class="material-symbols-outlined text-3xl text-blue-400" style="font-variation-settings:'FILL' 1">computer</span>
                            </div>
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-1" style="color:rgba(255,255,255,0.3)">Department</p>
                                <h2 class="text-2xl font-black leading-tight">IT &amp; Technology</h2>
                                <p class="text-sm font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Infrastructure Â· Support Â· Security Â· Development</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-10 flex-shrink-0">
                            <div v-for="m in [
                                { label: 'Team Members', val: '42' },
                                { label: 'Servers Online', val: Math.round(deptSparkData.it.servers[deptSparkData.it.servers.length-1]) },
                                { label: 'Uptime SLA',    val: deptSparkData.it.uptime[deptSparkData.it.uptime.length-1].toFixed(2) + '%' },
                            ]" :key="m.label" class="text-center">
                                <p class="text-3xl font-black leading-none kpi-val">{{ m.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.3)">{{ m.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Servers Online',  display: Math.round(deptSparkData.it.servers[deptSparkData.it.servers.length-1]) + ' / 24', trend: '100% capacity', color: '#316bf3', rgb: '49,107,243',  icon: 'dns',            up: true,  spark: deptSparkData.it.servers  },
                        { label: 'Open IT Tickets', display: Math.round(deptSparkData.it.tickets[deptSparkData.it.tickets.length-1]), trend: '3 critical',      color: '#dc2626',  rgb: '220,38,38',   icon: 'bug_report',     up: false, spark: deptSparkData.it.tickets  },
                        { label: 'Security Alerts', display: Math.round(deptSparkData.it.alerts[deptSparkData.it.alerts.length-1]),  trend: 'Low severity',    color: '#d97706',  rgb: '217,119,6',   icon: 'security',       up: false, spark: deptSparkData.it.alerts   },
                        { label: 'Uptime SLA',      display: deptSparkData.it.uptime[deptSparkData.it.uptime.length-1].toFixed(2) + '%', trend: 'Target: 99.9%', color: '#059669', rgb: '5,150,105',  icon: 'electric_bolt',  up: true,  spark: deptSparkData.it.uptime   },
                    ]" :key="i"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.07}s`">
                        <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
                            <span class="text-[7.5px] font-black uppercase tracking-widest" :style="`color:${card.color};opacity:0.65`">live</span>
                        </div>
                        <div class="mb-3 h-9 w-9 rounded-xl flex items-center justify-center" :style="`background:rgba(${card.rgb},0.1)`">
                            <span class="material-symbols-outlined text-[18px]" :style="`color:${card.color};font-variation-settings:'FILL' 1`">{{ card.icon }}</span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1.5 text-2xl font-black text-primary leading-none kpi-val">{{ card.display }}</p>
                        <p class="mt-1 text-[10px] font-semibold" :style="`color:${card.up ? '#059669' : '#d97706'}`">{{ card.up ? 'â†‘' : 'â†“' }} {{ card.trend }}</p>
                        <div class="-mx-1 mt-3">
                            <svg viewBox="0 0 96 24" class="w-full" style="height:24px;overflow:visible">
                                <defs><linearGradient :id="`itg${i}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="card.color" stop-opacity="0.2"/><stop offset="100%" :stop-color="card.color" stop-opacity="0.01"/></linearGradient></defs>
                                <path :d="sparkArea(card.spark, 96, 24)" :fill="`url(#itg${i})`"/>
                                <polyline :points="sparkLine(card.spark, 96, 24)" fill="none" :stroke="card.color" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="grid gap-6 lg:grid-cols-12">

                    <!-- Infrastructure Status + Incidents -->
                    <div class="lg:col-span-8 space-y-6">

                        <!-- Infrastructure Status -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                                <h3 class="text-[15px] font-black text-primary">Infrastructure Status</h3>
                                <div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-green-400 live-dot"></span><span class="text-[9px] font-black text-green-600 uppercase tracking-widest">All Systems</span></div>
                            </div>
                            <div class="divide-y divide-outline-variant/40">
                                <div v-for="sys in [
                                    { name: 'CIHRM Production API',        status: 'Operational',  latency: '42ms',  uptime: '99.98%', icon: 'api',            color: 'text-green-600 bg-green-50' },
                                    { name: 'PostgreSQL Database Cluster',  status: 'Operational',  latency: '8ms',   uptime: '99.99%', icon: 'storage',        color: 'text-green-600 bg-green-50' },
                                    { name: 'Redis Cache Layer',            status: 'Operational',  latency: '1ms',   uptime: '100%',   icon: 'memory',         color: 'text-green-600 bg-green-50' },
                                    { name: 'Mail & Notification Service',  status: 'Degraded',     latency: '320ms', uptime: '98.2%',  icon: 'mail',           color: 'text-amber-600 bg-amber-50' },
                                    { name: 'Document Storage (S3)',        status: 'Operational',  latency: '85ms',  uptime: '99.95%', icon: 'folder_open',    color: 'text-green-600 bg-green-50' },
                                    { name: 'VPN Gateway (Accra HQ)',       status: 'Operational',  latency: '12ms',  uptime: '99.97%', icon: 'vpn_lock',       color: 'text-green-600 bg-green-50' },
                                ]" :key="sys.name"
                                     class="flex items-center justify-between px-6 py-3.5 hover:bg-surface-container-low/30 transition-colors">
                                    <div class="flex items-center gap-4">
                                        <div class="h-8 w-8 rounded-xl flex items-center justify-center flex-shrink-0" :class="sys.color.split(' ')[1]">
                                            <span class="material-symbols-outlined text-[16px]" :class="sys.color.split(' ')[0]">{{ sys.icon }}</span>
                                        </div>
                                        <div>
                                            <p class="text-[13px] font-bold text-primary">{{ sys.name }}</p>
                                            <p class="text-[10px] font-medium text-on-surface-variant">Latency: {{ sys.latency }} Â· Uptime: {{ sys.uptime }}</p>
                                        </div>
                                    </div>
                                    <span class="rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-wider" :class="sys.status === 'Operational' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-amber-50 text-amber-700 border border-amber-100'">{{ sys.status }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Open Incidents -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                                <h3 class="text-[15px] font-black text-primary">Active Incidents &amp; Tickets</h3>
                                <button @click="showTicketModal = true" class="btn-shimmer flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-black text-white" style="background:linear-gradient(135deg,#0051d5,#316bf3)">
                                    <span class="material-symbols-outlined text-[15px]">add</span> New Ticket
                                </button>
                            </div>
                            <div class="divide-y divide-outline-variant/40">
                                <div v-for="ticket in tickets.slice(0, 5)" :key="ticket.id"
                                     class="flex items-center justify-between px-6 py-3.5 hover:bg-surface-container-low/30 transition-colors group cursor-pointer">
                                    <div class="flex items-center gap-4">
                                        <span class="text-[10px] font-mono font-bold text-on-surface-variant/50">#SD-{{ 1000 + ticket.id }}</span>
                                        <p class="text-[13px] font-bold text-primary group-hover:text-secondary transition-colors">{{ ticket.title || 'IT Support Request' }}</p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase"
                                              :class="(ticket.priority||'medium') === 'high' ? 'bg-red-50 text-red-700 border border-red-100' : (ticket.priority||'medium') === 'medium' ? 'bg-amber-50 text-amber-700 border border-amber-100' : 'bg-surface-container-low text-on-surface-variant border border-outline-variant'">
                                            {{ ticket.priority || 'Medium' }}
                                        </span>
                                        <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase bg-blue-50 text-blue-700 border border-blue-100">{{ ticket.status || 'Open' }}</span>
                                    </div>
                                </div>
                                <div v-if="!tickets.length" class="px-6 py-8 text-center text-sm font-bold text-on-surface-variant italic">No open tickets â€” all clear.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Team + On-Call -->
                    <div class="lg:col-span-4 space-y-6">

                        <!-- On-Call Roster -->
                        <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06)">
                            <div class="absolute -right-4 -top-4 opacity-10"><span class="material-symbols-outlined text-9xl">phonelink_ring</span></div>
                            <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-4" style="color:rgba(255,255,255,0.35)">On-Call Roster Â· Today</p>
                            <div class="space-y-3">
                                <div v-for="oncall in [
                                    { name: 'Kwame Asiedu',   role: 'Senior DevOps',     shift: '08:00â€“16:00', primary: true },
                                    { name: 'Efua Boateng',   role: 'Network Engineer',  shift: '16:00â€“00:00', primary: false },
                                    { name: 'Isaac Mensah',   role: 'Security Analyst',  shift: '00:00â€“08:00', primary: false },
                                ]" :key="oncall.name"
                                     class="flex items-center gap-3 rounded-xl p-3"
                                     :style="oncall.primary ? 'background:rgba(49,107,243,0.18);border:1px solid rgba(49,107,243,0.25)' : 'background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.05)'">
                                    <div class="h-8 w-8 rounded-full flex items-center justify-center text-[11px] font-black text-white flex-shrink-0" style="background:linear-gradient(135deg,#0051d5,#316bf3)">{{ oncall.name.charAt(0) }}</div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[12px] font-black text-white truncate">{{ oncall.name }}</p>
                                        <p class="text-[9.5px] font-medium" style="color:rgba(255,255,255,0.4)">{{ oncall.role }}</p>
                                    </div>
                                    <span class="text-[8.5px] font-bold flex-shrink-0" style="color:rgba(255,255,255,0.3)">{{ oncall.shift }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- IT Team Directory -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-[13px] font-black text-primary">IT Team (42)</h4>
                                <span class="text-[10px] font-black text-secondary">View All</span>
                            </div>
                            <div class="space-y-2.5">
                                <div v-for="member in [
                                    { name: 'Ama Asante',    role: 'Lead Engineer',    status: 'online' },
                                    { name: 'Kofi Darko',    role: 'Backend Dev',      status: 'online' },
                                    { name: 'Yaa Osei',      role: 'QA Engineer',      status: 'away' },
                                    { name: 'Nana Adjei',    role: 'Sys Admin',        status: 'online' },
                                    { name: 'Abena Mensah',  role: 'Data Engineer',    status: 'offline' },
                                ]" :key="member.name"
                                     class="flex items-center gap-3">
                                    <div class="relative flex-shrink-0">
                                        <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[11px] font-black text-secondary">{{ member.name.charAt(0) }}</div>
                                        <div class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white"
                                             :class="member.status === 'online' ? 'bg-green-400' : member.status === 'away' ? 'bg-amber-400' : 'bg-slate-300'"></div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[12px] font-bold text-primary truncate">{{ member.name }}</p>
                                        <p class="text-[10px] font-medium text-on-surface-variant">{{ member.role }}</p>
                                    </div>
                                    <span class="text-[9px] font-bold capitalize" :class="member.status === 'online' ? 'text-green-600' : member.status === 'away' ? 'text-amber-600' : 'text-on-surface-variant/40'">{{ member.status }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tech Stack -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[13px] font-black text-primary mb-4">Tech Stack Health</h4>
                            <div class="space-y-3">
                                <div v-for="tech in [
                                    { name: 'Laravel API',     pct: 99, color: 'bg-red-500' },
                                    { name: 'Vue.js Frontend', pct: 100, color: 'bg-green-500' },
                                    { name: 'PostgreSQL',      pct: 99, color: 'bg-blue-500' },
                                    { name: 'Redis Cache',     pct: 100, color: 'bg-purple-500' },
                                    { name: 'Nginx Proxy',     pct: 98, color: 'bg-amber-500' },
                                ]" :key="tech.name" class="space-y-1">
                                    <div class="flex items-center justify-between text-[11px] font-bold">
                                        <span class="text-on-surface-variant">{{ tech.name }}</span>
                                        <span :class="tech.pct >= 99 ? 'text-green-600' : 'text-amber-600'">{{ tech.pct }}%</span>
                                    </div>
                                    <div class="h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-700" :class="tech.color" :style="`width:${tech.pct}%`"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 HUMAN RESOURCES DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeModule === 'dept-hr'" class="space-y-6 animate-reveal-up">

                <!-- Hero Banner -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white"
                     style="background:linear-gradient(135deg,#0c0e14,#111827);border:1px solid rgba(255,255,255,0.06)">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(5,150,105,0.2),transparent 70%)"></div>
                    <div class="relative flex flex-wrap items-center justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="h-14 w-14 rounded-2xl flex items-center justify-center flex-shrink-0" style="background:rgba(5,150,105,0.2);border:1px solid rgba(5,150,105,0.3)">
                                <span class="material-symbols-outlined text-3xl text-green-400" style="font-variation-settings:'FILL' 1">people</span>
                            </div>
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-1" style="color:rgba(255,255,255,0.3)">Department</p>
                                <h2 class="text-2xl font-black leading-tight">Human Resources</h2>
                                <p class="text-sm font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Talent Â· Culture Â· Compliance Â· Payroll</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-10 flex-shrink-0">
                            <div v-for="m in [
                                { label: 'HR Staff',      val: '28' },
                                { label: 'Total Headcount', val: Math.round(deptSparkData.hr.headcount[deptSparkData.hr.headcount.length-1]).toLocaleString() },
                                { label: 'Turnover Rate',   val: deptSparkData.hr.turnover[deptSparkData.hr.turnover.length-1].toFixed(1) + '%' },
                            ]" :key="m.label" class="text-center">
                                <p class="text-3xl font-black leading-none kpi-val">{{ m.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.3)">{{ m.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Total Headcount',  display: Math.round(deptSparkData.hr.headcount[deptSparkData.hr.headcount.length-1]).toLocaleString(), trend: '+2 this week',  color: '#059669', rgb: '5,150,105',   icon: 'badge',            up: true,  spark: deptSparkData.hr.headcount    },
                        { label: 'Turnover Rate',     display: deptSparkData.hr.turnover[deptSparkData.hr.turnover.length-1].toFixed(1) + '%',               trend: 'vs 5% target', color: '#316bf3',  rgb: '49,107,243',  icon: 'person_remove',    up: true,  spark: deptSparkData.hr.turnover     },
                        { label: 'Open Positions',    display: Math.round(deptSparkData.hr.openPositions[deptSparkData.hr.openPositions.length-1]),           trend: '6 in pipeline', color: '#d97706', rgb: '217,119,6',   icon: 'work_outline',     up: false, spark: deptSparkData.hr.openPositions},
                        { label: 'Training Completion', display: deptSparkData.hr.training[deptSparkData.hr.training.length-1].toFixed(0) + '%',              trend: 'Annual goal',  color: '#7c5cff',  rgb: '124,92,255',  icon: 'school',           up: true,  spark: deptSparkData.hr.training     },
                    ]" :key="i"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.07}s`">
                        <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
                        </div>
                        <div class="mb-3 h-9 w-9 rounded-xl flex items-center justify-center" :style="`background:rgba(${card.rgb},0.1)`">
                            <span class="material-symbols-outlined text-[18px]" :style="`color:${card.color};font-variation-settings:'FILL' 1`">{{ card.icon }}</span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1.5 text-2xl font-black text-primary leading-none kpi-val">{{ card.display }}</p>
                        <p class="mt-1 text-[10px] font-semibold" :style="`color:${card.up ? '#059669' : '#d97706'}`">{{ card.up ? 'â†‘' : 'â†“' }} {{ card.trend }}</p>
                        <div class="-mx-1 mt-3">
                            <svg viewBox="0 0 96 24" class="w-full" style="height:24px;overflow:visible">
                                <defs><linearGradient :id="`hrg${i}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="card.color" stop-opacity="0.2"/><stop offset="100%" :stop-color="card.color" stop-opacity="0.01"/></linearGradient></defs>
                                <path :d="sparkArea(card.spark, 96, 24)" :fill="`url(#hrg${i})`"/>
                                <polyline :points="sparkLine(card.spark, 96, 24)" fill="none" :stroke="card.color" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="grid gap-6 lg:grid-cols-12">

                    <div class="lg:col-span-8 space-y-6">

                        <!-- Recruitment Pipeline -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                                <h3 class="text-[15px] font-black text-primary">Recruitment Pipeline</h3>
                                <button @click="showJobModal = true" class="btn-shimmer flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-black text-white" style="background:linear-gradient(135deg,#0051d5,#316bf3)">
                                    <span class="material-symbols-outlined text-[15px]">add</span> Post Job
                                </button>
                            </div>
                            <div class="p-6 space-y-4">
                                <div v-for="stage in [
                                    { name: 'Applications Received', count: 245, total: 245, color: 'bg-blue-500',   pct: 100 },
                                    { name: 'Shortlisted',           count: 82,  total: 245, color: 'bg-secondary',  pct: 33  },
                                    { name: 'First Interview',       count: 34,  total: 245, color: 'bg-purple-500', pct: 14  },
                                    { name: 'Second Interview',      count: 12,  total: 245, color: 'bg-amber-500',  pct: 5   },
                                    { name: 'Offer Extended',        count: 4,   total: 245, color: 'bg-green-500',  pct: 1.6 },
                                ]" :key="stage.name"
                                     class="flex items-center gap-4">
                                    <span class="w-44 text-[12px] font-bold text-on-surface-variant flex-shrink-0">{{ stage.name }}</span>
                                    <div class="flex-1 h-6 rounded-full bg-surface-container-low overflow-hidden relative">
                                        <div class="h-full rounded-full transition-all duration-700 flex items-center justify-end pr-3" :class="stage.color" :style="`width:${stage.pct}%`">
                                            <span class="text-[9px] font-black text-white">{{ stage.count }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Hires Table -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden flex flex-col">
                            <div class="px-6 py-4 border-b border-outline-variant/50 flex-shrink-0">
                                <h3 class="text-[15px] font-black text-primary">Recent Hires</h3>
                            </div>
                            <div class="canvas-scroll max-h-[340px] overflow-auto">
                            <table class="w-full text-left">
                                <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                    <tr>
                                        <th class="px-6 py-3">Employee</th>
                                        <th class="px-6 py-3">Department</th>
                                        <th class="px-6 py-3">Start Date</th>
                                        <th class="px-6 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-outline-variant/40">
                                    <tr v-for="emp in employees" :key="emp.id" class="hover:bg-surface-container-low/30 transition-colors">
                                        <td class="px-6 py-3.5">
                                            <div class="flex items-center gap-3">
                                                <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[11px] font-black text-secondary flex-shrink-0">{{ (emp.employee_no || 'E').charAt(0) }}</div>
                                                <div>
                                                    <p class="text-[12.5px] font-bold text-primary">{{ emp.user?.name || emp.name || 'New Employee' }}</p>
                                                    <p class="text-[10px] text-on-surface-variant">{{ emp.position }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3.5 text-[12.5px] font-bold text-on-surface-variant">{{ emp.department?.name || 'General' }}</td>
                                        <td class="px-6 py-3.5 text-[12px] font-medium text-on-surface-variant">{{ emp.hire_date || 'May 2026' }}</td>
                                        <td class="px-6 py-3.5"><span class="rounded-full px-2.5 py-1 text-[9px] font-black uppercase bg-green-50 text-green-700 border border-green-100">Active</span></td>
                                    </tr>
                                    <tr v-if="!employees.length"><td colspan="4" class="px-6 py-8 text-center text-sm italic text-on-surface-variant">No recent hires recorded.</td></tr>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="lg:col-span-4 space-y-6">

                        <!-- Dept Breakdown -->
                        <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06)">
                            <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-5" style="color:rgba(255,255,255,0.3)">Workforce by Department</p>
                            <div class="space-y-3">
                                <div v-for="dept in [
                                    { name: 'Technology',  count: 285, pct: 22, color: '#316bf3' },
                                    { name: 'Operations',  count: 412, pct: 32, color: '#059669' },
                                    { name: 'Finance',     count: 156, pct: 12, color: '#d97706' },
                                    { name: 'Marketing',   count: 98,  pct: 8,  color: '#7c5cff' },
                                    { name: 'HR & Admin',  count: 72,  pct: 6,  color: '#0891b2' },
                                    { name: 'Other',       count: 261, pct: 20, color: '#6b7280' },
                                ]" :key="dept.name" class="space-y-1">
                                    <div class="flex items-center justify-between text-[11px] font-bold">
                                        <span style="color:rgba(255,255,255,0.7)">{{ dept.name }}</span>
                                        <span style="color:rgba(255,255,255,0.4)">{{ dept.count }} ({{ dept.pct }}%)</span>
                                    </div>
                                    <div class="h-1.5 w-full rounded-full overflow-hidden" style="background:rgba(255,255,255,0.06)">
                                        <div class="h-full rounded-full transition-all duration-700" :style="`width:${dept.pct}%;background:${dept.color}`"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Leave Summary -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[13px] font-black text-primary mb-4">Leave Overview Â· This Month</h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div v-for="ls in [
                                    { label: 'On Leave',     val: Math.round(deptSparkData.hr.openPositions[11] * 3.4), color: 'text-amber-600', bg: 'bg-amber-50' },
                                    { label: 'Pending',      val: stats.pendingLeave ?? 0,                               color: 'text-blue-600',  bg: 'bg-blue-50' },
                                    { label: 'Approved',     val: 38,                                                   color: 'text-green-600', bg: 'bg-green-50' },
                                    { label: 'Annual Left',  val: '12d',                                                color: 'text-secondary', bg: 'bg-secondary/10' },
                                ]" :key="ls.label"
                                     class="rounded-xl p-3 text-center" :class="ls.bg">
                                    <p class="text-xl font-black" :class="ls.color">{{ ls.val }}</p>
                                    <p class="text-[9px] font-black uppercase mt-0.5 text-on-surface-variant">{{ ls.label }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Compliance -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[13px] font-black text-primary mb-4">HR Compliance</h4>
                            <div class="space-y-3">
                                <div v-for="item in [
                                    { label: 'Labor Act 2003',      pct: 100, pass: true  },
                                    { label: 'Data Protection',     pct: 97,  pass: true  },
                                    { label: 'Policy Review',       pct: 82,  pass: false },
                                    { label: 'Anti-Harassment',     pct: 100, pass: true  },
                                ]" :key="item.label" class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-[18px]" :class="item.pass ? 'text-green-500' : 'text-amber-500'">{{ item.pass ? 'check_circle' : 'warning' }}</span>
                                    <span class="flex-1 text-[12px] font-bold text-on-surface-variant">{{ item.label }}</span>
                                    <span class="text-[11px] font-black" :class="item.pass ? 'text-green-600' : 'text-amber-600'">{{ item.pct }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 MARKETING DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeModule === 'dept-marketing'" class="space-y-6 animate-reveal-up">

                <!-- Hero Banner -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white"
                     style="background:linear-gradient(135deg,#0c0e14,#111827);border:1px solid rgba(255,255,255,0.06)">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(124,92,255,0.22),transparent 70%)"></div>
                    <div class="relative flex flex-wrap items-center justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="h-14 w-14 rounded-2xl flex items-center justify-center flex-shrink-0" style="background:rgba(124,92,255,0.2);border:1px solid rgba(124,92,255,0.3)">
                                <span class="material-symbols-outlined text-3xl text-purple-400" style="font-variation-settings:'FILL' 1">campaign</span>
                            </div>
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-1" style="color:rgba(255,255,255,0.3)">Department</p>
                                <h2 class="text-2xl font-black leading-tight">Marketing</h2>
                                <p class="text-sm font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Campaigns Â· Brand Â· Digital Â· Content</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-10 flex-shrink-0">
                            <div v-for="m in [
                                { label: 'Team Members', val: '35' },
                                { label: 'Campaign ROI',   val: deptSparkData.marketing.roi[deptSparkData.marketing.roi.length-1].toFixed(0) + '%' },
                                { label: 'Budget Used',    val: deptSparkData.marketing.budget[deptSparkData.marketing.budget.length-1].toFixed(0) + '%' },
                            ]" :key="m.label" class="text-center">
                                <p class="text-3xl font-black leading-none kpi-val">{{ m.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.3)">{{ m.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Campaign ROI',     display: deptSparkData.marketing.roi[deptSparkData.marketing.roi.length-1].toFixed(0) + '%',         trend: 'vs 200% target', color: '#7c5cff', rgb: '124,92,255', icon: 'trending_up',    up: true,  spark: deptSparkData.marketing.roi        },
                        { label: 'Budget Utilised',  display: deptSparkData.marketing.budget[deptSparkData.marketing.budget.length-1].toFixed(0) + '%',   trend: 'of GHS 420K',    color: '#0891b2', rgb: '8,145,178',  icon: 'account_balance_wallet', up: false, spark: deptSparkData.marketing.budget  },
                        { label: 'Leads Generated',  display: Math.round(deptSparkData.marketing.leads[deptSparkData.marketing.leads.length-1]).toLocaleString(), trend: '+8% this week', color: '#316bf3', rgb: '49,107,243', icon: 'group_add',  up: true,  spark: deptSparkData.marketing.leads     },
                        { label: 'Conversion Rate',  display: deptSparkData.marketing.conversion[deptSparkData.marketing.conversion.length-1].toFixed(1) + '%', trend: 'Target: 4%',  color: '#d97706', rgb: '217,119,6',  icon: 'swap_horiz',   up: true,  spark: deptSparkData.marketing.conversion},
                    ]" :key="i"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.07}s`">
                        <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
                        </div>
                        <div class="mb-3 h-9 w-9 rounded-xl flex items-center justify-center" :style="`background:rgba(${card.rgb},0.1)`">
                            <span class="material-symbols-outlined text-[18px]" :style="`color:${card.color};font-variation-settings:'FILL' 1`">{{ card.icon }}</span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1.5 text-2xl font-black text-primary leading-none kpi-val">{{ card.display }}</p>
                        <p class="mt-1 text-[10px] font-semibold" :style="`color:${card.up ? '#059669' : '#d97706'}`">{{ card.up ? 'â†‘' : 'â†“' }} {{ card.trend }}</p>
                        <div class="-mx-1 mt-3">
                            <svg viewBox="0 0 96 24" class="w-full" style="height:24px;overflow:visible">
                                <defs><linearGradient :id="`mkg${i}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="card.color" stop-opacity="0.2"/><stop offset="100%" :stop-color="card.color" stop-opacity="0.01"/></linearGradient></defs>
                                <path :d="sparkArea(card.spark, 96, 24)" :fill="`url(#mkg${i})`"/>
                                <polyline :points="sparkLine(card.spark, 96, 24)" fill="none" :stroke="card.color" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="grid gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-8 space-y-6">

                        <!-- Active Campaigns -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                            <div class="px-6 py-4 border-b border-outline-variant/50 flex items-center justify-between">
                                <h3 class="text-[15px] font-black text-primary">Active Campaigns</h3>
                                <span class="rounded-full px-3 py-1 bg-purple-50 text-purple-700 border border-purple-100 text-[9.5px] font-black uppercase">6 Running</span>
                            </div>
                            <div class="divide-y divide-outline-variant/40">
                                <div v-for="campaign in [
                                    { name: 'Q2 Institutional Awareness Drive',   channel: 'Digital + OOH',    spend: 'GHS 45,000', roi: '342%', status: 'Active',  progress: 72 },
                                    { name: 'CIHRM Graduate Recruitment 2026',    channel: 'Social + Print',   spend: 'GHS 28,000', roi: '218%', status: 'Active',  progress: 45 },
                                    { name: 'Annual HR Summit Sponsorship',       channel: 'Events',           spend: 'GHS 12,500', roi: '185%', status: 'Active',  progress: 88 },
                                    { name: 'Staff Wellness Brand Initiative',    channel: 'Internal Media',   spend: 'GHS 8,200',  roi: '290%', status: 'Active',  progress: 30 },
                                ]" :key="campaign.name"
                                     class="px-6 py-4 hover:bg-surface-container-low/30 transition-colors">
                                    <div class="flex items-start justify-between mb-2">
                                        <div>
                                            <p class="text-[13px] font-bold text-primary">{{ campaign.name }}</p>
                                            <p class="text-[10px] text-on-surface-variant mt-0.5">{{ campaign.channel }} Â· Spend: {{ campaign.spend }}</p>
                                        </div>
                                        <div class="text-right flex-shrink-0 ml-4">
                                            <p class="text-sm font-black text-green-600">{{ campaign.roi }}</p>
                                            <p class="text-[9px] text-on-surface-variant">ROI</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 mt-2">
                                        <div class="flex-1 h-1.5 rounded-full bg-surface-container-low overflow-hidden">
                                            <div class="h-full bg-purple-500 rounded-full transition-all duration-700" :style="`width:${campaign.progress}%`"></div>
                                        </div>
                                        <span class="text-[10px] font-black text-on-surface-variant flex-shrink-0">{{ campaign.progress }}%</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Content Pipeline Kanban -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                            <div class="px-6 py-4 border-b border-outline-variant/50">
                                <h3 class="text-[15px] font-black text-primary">Content Pipeline</h3>
                            </div>
                            <div class="grid grid-cols-3 gap-px bg-outline-variant/20 overflow-hidden">
                                <div v-for="col in [
                                    { title: 'In Production', count: 8, color: 'bg-blue-400', items: ['Q3 Annual Report Design', 'Social Media Calendar', 'Brand Refresh Deck'] },
                                    { title: 'In Review',     count: 5, color: 'bg-amber-400', items: ['CIHRM Brand Guidelines', 'Video Script â€” Recruitment'] },
                                    { title: 'Published',     count: 12, color: 'bg-green-400', items: ['May Newsletter', 'LinkedIn Campaign Posts', 'Staff Magazine Issue 4'] },
                                ]" :key="col.title" class="p-4 bg-surface-container-lowest">
                                    <div class="flex items-center gap-2 mb-3">
                                        <span class="h-2 w-2 rounded-full" :class="col.color"></span>
                                        <h4 class="text-[11px] font-black text-primary">{{ col.title }}</h4>
                                        <span class="ml-auto h-5 w-5 rounded-full bg-surface-container-low flex items-center justify-center text-[9px] font-black text-on-surface-variant">{{ col.count }}</span>
                                    </div>
                                    <div class="space-y-2">
                                        <div v-for="item in col.items" :key="item"
                                             class="rounded-lg bg-surface-container-low/60 border border-outline-variant/40 px-3 py-2 text-[11px] font-medium text-on-surface cursor-default hover:border-secondary/20 transition-colors">
                                            {{ item }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="lg:col-span-4 space-y-6">

                        <!-- Social Media Metrics -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-[13px] font-black text-primary">Social Media</h4>
                                <div class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full live-dot bg-green-400"></span><span class="text-[9px] font-black text-green-600">Live</span></div>
                            </div>
                            <div class="space-y-3">
                                <div v-for="social in [
                                    { platform: 'LinkedIn',   followers: '12.4K', growth: '+8.2%', icon: 'group',        color: '#0077b5' },
                                    { platform: 'Twitter/X',  followers: '8.1K',  growth: '+3.4%', icon: 'alternate_email', color: '#1da1f2' },
                                    { platform: 'Facebook',   followers: '22.8K', growth: '+1.9%', icon: 'thumb_up',     color: '#1877f2' },
                                    { platform: 'Instagram',  followers: '5.2K',  growth: '+12.1%',icon: 'camera_alt',   color: '#e1306c' },
                                ]" :key="social.platform"
                                     class="flex items-center gap-3 rounded-xl p-3 bg-surface-container-low/40 border border-outline-variant/30">
                                    <div class="h-8 w-8 rounded-xl flex items-center justify-center flex-shrink-0" :style="`background:${social.color}15`">
                                        <span class="material-symbols-outlined text-[16px]" :style="`color:${social.color}`">{{ social.icon }}</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[12px] font-bold text-primary">{{ social.platform }}</p>
                                        <p class="text-[10px] text-on-surface-variant">{{ social.followers }} followers</p>
                                    </div>
                                    <span class="text-[11px] font-black text-green-600">{{ social.growth }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Budget Tracker -->
                        <div class="rounded-2xl p-5 text-white" style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06)">
                            <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-1" style="color:rgba(255,255,255,0.35)">Annual Marketing Budget</p>
                            <p class="text-3xl font-black mb-4">GHS 420,000</p>
                            <div class="space-y-3">
                                <div v-for="line in [
                                    { label: 'Digital Advertising', spent: 145000, total: 200000 },
                                    { label: 'Events & PR',         spent: 62000,  total: 100000 },
                                    { label: 'Content Production',  spent: 38000,  total: 80000  },
                                    { label: 'Brand & Design',      spent: 27000,  total: 40000  },
                                ]" :key="line.label" class="space-y-1">
                                    <div class="flex items-center justify-between text-[10px] font-bold">
                                        <span style="color:rgba(255,255,255,0.6)">{{ line.label }}</span>
                                        <span style="color:rgba(255,255,255,0.35)">GHS {{ (line.spent/1000).toFixed(0) }}K / {{ (line.total/1000).toFixed(0) }}K</span>
                                    </div>
                                    <div class="h-1.5 w-full rounded-full overflow-hidden" style="background:rgba(255,255,255,0.08)">
                                        <div class="h-full rounded-full transition-all duration-700" style="background:linear-gradient(90deg,#7c5cff,#a78bfa)" :style="`width:${Math.round(line.spent/line.total*100)}%`"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Team -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[13px] font-black text-primary mb-4">Marketing Team (35)</h4>
                            <div class="flex flex-wrap gap-2">
                                <div v-for="m in ['Content', 'Design', 'Digital', 'Events', 'Brand', 'PR', 'Analytics', 'SEO']" :key="m"
                                     class="rounded-full px-3 py-1 text-[10px] font-black border border-outline-variant text-on-surface-variant hover:bg-surface-container-low transition-colors cursor-default">
                                    {{ m }}
                                </div>
                            </div>
                            <div class="mt-4 flex -space-x-2">
                                <div v-for="i in 8" :key="i"
                                     class="h-8 w-8 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-black text-white"
                                     :style="`background:linear-gradient(135deg,${['#0051d5','#7c5cff','#059669','#d97706','#dc2626','#0891b2','#316bf3','#6d28d9'][i-1]},${['#316bf3','#a78bfa','#34d399','#fbbf24','#f87171','#22d3ee','#60a5fa','#7c3aed'][i-1]})`">
                                    {{ 'ABCDEFGH'[i-1] }}
                                </div>
                                <div class="h-8 w-8 rounded-full border-2 border-white bg-surface-container-low flex items-center justify-center text-[9px] font-black text-on-surface-variant">+27</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 FINANCE DEPARTMENT
                 â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeModule === 'dept-finance'" class="space-y-6 animate-reveal-up">

                <!-- Hero Banner -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white"
                     style="background:linear-gradient(135deg,#0c0e14,#111827);border:1px solid rgba(255,255,255,0.06)">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(217,119,6,0.2),transparent 70%)"></div>
                    <div class="relative flex flex-wrap items-center justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="h-14 w-14 rounded-2xl flex items-center justify-center flex-shrink-0" style="background:rgba(217,119,6,0.2);border:1px solid rgba(217,119,6,0.3)">
                                <span class="material-symbols-outlined text-3xl text-amber-400" style="font-variation-settings:'FILL' 1">account_balance_wallet</span>
                            </div>
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-1" style="color:rgba(255,255,255,0.3)">Department</p>
                                <h2 class="text-2xl font-black leading-tight">Finance</h2>
                                <p class="text-sm font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Payroll Â· Audit Â· Compliance Â· Reporting</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-10 flex-shrink-0">
                            <div v-for="m in [
                                { label: 'Finance Staff', val: '22' },
                                { label: 'Monthly Revenue', val: 'GHS ' + deptSparkData.finance.revenue[deptSparkData.finance.revenue.length-1].toFixed(1) + 'M' },
                                { label: 'Cost Efficiency', val: deptSparkData.finance.efficiency[deptSparkData.finance.efficiency.length-1].toFixed(0) + '%' },
                            ]" :key="m.label" class="text-center">
                                <p class="text-3xl font-black leading-none kpi-val">{{ m.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.3)">{{ m.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Monthly Revenue',   display: 'GHS ' + deptSparkData.finance.revenue[deptSparkData.finance.revenue.length-1].toFixed(1) + 'M',    trend: '+12% YoY',     color: '#059669', rgb: '5,150,105',  icon: 'trending_up',    up: true,  spark: deptSparkData.finance.revenue   },
                        { label: 'Budget Variance',   display: deptSparkData.finance.variance[deptSparkData.finance.variance.length-1].toFixed(1) + '%',            trend: 'Under target',  color: '#d97706',  rgb: '217,119,6',  icon: 'show_chart',     up: false, spark: deptSparkData.finance.variance  },
                        { label: 'Pending Payments',  display: 'GHS ' + deptSparkData.finance.pending[deptSparkData.finance.pending.length-1].toFixed(0) + 'K',    trend: '18 invoices',   color: '#dc2626',  rgb: '220,38,38',  icon: 'receipt_long',   up: false, spark: deptSparkData.finance.pending   },
                        { label: 'Cost Efficiency',   display: deptSparkData.finance.efficiency[deptSparkData.finance.efficiency.length-1].toFixed(0) + '%',        trend: 'Target: 90%',   color: '#316bf3',  rgb: '49,107,243', icon: 'savings',        up: true,  spark: deptSparkData.finance.efficiency},
                    ]" :key="i"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.07}s`">
                        <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
                        </div>
                        <div class="mb-3 h-9 w-9 rounded-xl flex items-center justify-center" :style="`background:rgba(${card.rgb},0.1)`">
                            <span class="material-symbols-outlined text-[18px]" :style="`color:${card.color};font-variation-settings:'FILL' 1`">{{ card.icon }}</span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1.5 text-2xl font-black text-primary leading-none kpi-val">{{ card.display }}</p>
                        <p class="mt-1 text-[10px] font-semibold" :style="`color:${card.up ? '#059669' : '#d97706'}`">{{ card.up ? 'â†‘' : 'â†“' }} {{ card.trend }}</p>
                        <div class="-mx-1 mt-3">
                            <svg viewBox="0 0 96 24" class="w-full" style="height:24px;overflow:visible">
                                <defs><linearGradient :id="`fig${i}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="card.color" stop-opacity="0.2"/><stop offset="100%" :stop-color="card.color" stop-opacity="0.01"/></linearGradient></defs>
                                <path :d="sparkArea(card.spark, 96, 24)" :fill="`url(#fig${i})`"/>
                                <polyline :points="sparkLine(card.spark, 96, 24)" fill="none" :stroke="card.color" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="grid gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-8 space-y-6">

                        <!-- Budget vs Actuals Live Chart -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 overflow-hidden">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <h3 class="text-[15px] font-black text-primary">Budget vs Actuals Â· 2026</h3>
                                    <p class="text-[10px] text-on-surface-variant mt-0.5">Monthly financial performance tracking</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center gap-1.5"><span class="h-2 w-4 rounded-full" style="background:linear-gradient(90deg,#0051d5,#316bf3)"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Actual</span></div>
                                    <div class="flex items-center gap-1.5"><span class="h-2 w-4 rounded-full bg-outline-variant"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Budget</span></div>
                                </div>
                            </div>
                            <div class="flex items-end gap-2" style="height:120px;">
                                <div v-for="(month, mi) in ['J','F','M','A','M','J','J','A','S','O','N','D']" :key="month"
                                     class="flex-1 flex flex-col items-center gap-0.5 group">
                                    <!-- Budget bar (background) -->
                                    <div class="relative w-full" style="height:100px">
                                        <div class="absolute bottom-0 w-full rounded-t bg-outline-variant/30 transition-all duration-700"
                                             :style="`height:${[65,68,70,72,74,76,78,80,82,84,86,88][mi]}%;`"></div>
                                        <!-- Actual bar (foreground) -->
                                        <div class="absolute bottom-0 w-3/4 left-1/2 -translate-x-1/2 rounded-t transition-all duration-700"
                                             style="background:linear-gradient(to top,#0051d5,rgba(99,131,255,0.6));"
                                             :style="`height:${[60,65,62,70,74,71,76,78,80,84,85,88][mi]}%;`"></div>
                                        <!-- Tooltip -->
                                        <div class="absolute -top-8 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity z-10 whitespace-nowrap rounded-lg px-2 py-1 text-[8px] font-black text-white" style="background:#0c0e14">GHS {{ [8.1,8.3,8.2,8.6,8.8,8.7,8.9,9.0,9.1,9.2,9.3,9.4][mi] }}M</div>
                                    </div>
                                    <span class="text-[8px] font-bold text-on-surface-variant/40">{{ month }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Expense Breakdown + Pending Approvals -->
                        <div class="grid gap-6 sm:grid-cols-2">

                            <!-- Expense Breakdown -->
                            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                                <h4 class="text-[13px] font-black text-primary mb-4">Expense Breakdown</h4>
                                <div class="space-y-3">
                                    <div v-for="exp in [
                                        { label: 'Staff Payroll',    pct: 68, amount: 'GHS 1.67M', color: '#316bf3' },
                                        { label: 'Operations',       pct: 14, amount: 'GHS 344K',  color: '#059669' },
                                        { label: 'IT & Technology',  pct: 8,  amount: 'GHS 197K',  color: '#7c5cff' },
                                        { label: 'Marketing',        pct: 6,  amount: 'GHS 148K',  color: '#d97706' },
                                        { label: 'Other',            pct: 4,  amount: 'GHS 98K',   color: '#6b7280' },
                                    ]" :key="exp.label" class="space-y-1">
                                        <div class="flex items-center justify-between text-[11px] font-bold">
                                            <span class="text-on-surface-variant">{{ exp.label }}</span>
                                            <span class="text-primary">{{ exp.pct }}%</span>
                                        </div>
                                        <div class="h-2 w-full rounded-full bg-surface-container-low overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-700" :style="`width:${exp.pct}%;background:${exp.color}`"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Pending Approvals -->
                            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                                <h4 class="text-[13px] font-black text-primary mb-4">Pending Approvals</h4>
                                <div class="space-y-2.5">
                                    <div v-for="approval in [
                                        { ref: 'PAY-2026-048', amount: 'GHS 12,400', type: 'Payroll Supplementary', urgency: 'High' },
                                        { ref: 'EXP-2026-112', amount: 'GHS 4,850',  type: 'Travel & Conference',   urgency: 'Medium' },
                                        { ref: 'INV-2026-089', amount: 'GHS 28,000', type: 'Vendor Invoice',         urgency: 'High' },
                                        { ref: 'REF-2026-031', amount: 'GHS 1,200',  type: 'Staff Reimbursement',   urgency: 'Low' },
                                    ]" :key="approval.ref"
                                         class="rounded-xl border border-outline-variant/50 p-3 hover:border-secondary/20 transition-colors cursor-pointer group">
                                        <div class="flex items-center justify-between">
                                            <span class="text-[9px] font-mono font-bold text-on-surface-variant/50">{{ approval.ref }}</span>
                                            <span class="text-[9px] font-black" :class="approval.urgency === 'High' ? 'text-red-600' : approval.urgency === 'Medium' ? 'text-amber-600' : 'text-green-600'">{{ approval.urgency }}</span>
                                        </div>
                                        <p class="text-[12px] font-bold text-primary mt-1 group-hover:text-secondary transition-colors">{{ approval.type }}</p>
                                        <p class="text-[13px] font-black text-primary mt-0.5">{{ approval.amount }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="lg:col-span-4 space-y-6">

                        <!-- Payroll Summary -->
                        <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06)">
                            <div class="absolute -right-4 -top-4 opacity-10"><span class="material-symbols-outlined text-9xl">payments</span></div>
                            <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-2" style="color:rgba(255,255,255,0.3)">Monthly Payroll</p>
                            <p class="text-3xl font-black mb-1">GHS 2.45M</p>
                            <p class="text-[10px] mb-5" style="color:rgba(255,255,255,0.4)">Next cycle ends in 4 days Â· 1,284 staff</p>
                            <div class="space-y-2.5">
                                <div class="flex items-center justify-between text-[11px] font-bold">
                                    <span style="color:rgba(255,255,255,0.55)">Processing Status</span>
                                    <span style="color:#34d399">85% Complete</span>
                                </div>
                                <div class="h-2 w-full rounded-full overflow-hidden" style="background:rgba(255,255,255,0.08)">
                                    <div class="h-full rounded-full transition-all duration-1000" style="width:85%;background:linear-gradient(90deg,#059669,#34d399)"></div>
                                </div>
                            </div>
                            <div class="mt-5 pt-5 border-t space-y-2" style="border-color:rgba(255,255,255,0.08)">
                                <div v-for="row in [
                                    { label: 'Basic Salary',       val: 'GHS 1.80M' },
                                    { label: 'Allowances',         val: 'GHS 450K'  },
                                    { label: 'SSNIT Deductions',   val: 'GHS 200K'  },
                                ]" :key="row.label" class="flex items-center justify-between text-[11px]">
                                    <span style="color:rgba(255,255,255,0.45)">{{ row.label }}</span>
                                    <span class="font-black text-white">{{ row.val }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Compliance Status -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[13px] font-black text-primary mb-4">Statutory Compliance</h4>
                            <div class="space-y-3">
                                <div v-for="item in [
                                    { label: 'SSNIT Filing â€” May 2026',    status: 'Filed',     color: 'text-green-600 bg-green-50 border-green-100' },
                                    { label: 'Income Tax (PAYE)',           status: 'Filed',     color: 'text-green-600 bg-green-50 border-green-100' },
                                    { label: 'Provident Fund Contribution', status: 'Pending',   color: 'text-amber-600 bg-amber-50 border-amber-100' },
                                    { label: 'Annual Returns',              status: 'Filed',     color: 'text-green-600 bg-green-50 border-green-100' },
                                    { label: 'VAT Declaration',             status: 'Due Jun 15',color: 'text-blue-600 bg-blue-50 border-blue-100' },
                                ]" :key="item.label"
                                     class="flex items-center justify-between">
                                    <div class="flex items-center gap-2.5 flex-1 min-w-0 mr-3">
                                        <span class="material-symbols-outlined text-[16px] flex-shrink-0"
                                              :class="item.status === 'Filed' ? 'text-green-500' : item.status === 'Pending' ? 'text-amber-500' : 'text-blue-500'">
                                            {{ item.status === 'Filed' ? 'check_circle' : item.status === 'Pending' ? 'schedule' : 'event' }}
                                        </span>
                                        <p class="text-[11.5px] font-bold text-on-surface-variant truncate">{{ item.label }}</p>
                                    </div>
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border flex-shrink-0" :class="item.color">{{ item.status }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Finance Team -->
                        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                            <h4 class="text-[13px] font-black text-primary mb-4">Finance Team (22)</h4>
                            <div class="space-y-2.5">
                                <div v-for="member in [
                                    { name: 'Esi Amponsah',   role: 'CFO',                  status: 'online' },
                                    { name: 'Yaw Mensah',     role: 'Senior Accountant',    status: 'online' },
                                    { name: 'Akua Owusu',     role: 'Payroll Specialist',   status: 'online' },
                                    { name: 'Kwesi Acheampong', role: 'Financial Analyst',  status: 'away'   },
                                    { name: 'Abena Darko',    role: 'Tax Compliance',       status: 'online' },
                                ]" :key="member.name"
                                     class="flex items-center gap-3">
                                    <div class="relative flex-shrink-0">
                                        <div class="h-8 w-8 rounded-full bg-amber-500/10 flex items-center justify-center text-[11px] font-black text-amber-600">{{ member.name.charAt(0) }}</div>
                                        <div class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white"
                                             :class="member.status === 'online' ? 'bg-green-400' : 'bg-amber-400'"></div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[12px] font-bold text-primary truncate">{{ member.name }}</p>
                                        <p class="text-[10px] font-medium text-on-surface-variant">{{ member.role }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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
                                    <button @click="showAddEmployeeModal = true"
                                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                                            style="background:linear-gradient(135deg,#0051d5,#316bf3);">
                                        <span class="material-symbols-outlined text-[17px]">person_add</span>
                                        Add Employee
                                    </button>
                                    <button @click="showJobModal = true"
                                            class="flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold transition-all hover:-translate-y-px active:scale-[0.97]"
                                            style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.8);">
                                        <span class="material-symbols-outlined text-[17px]">work</span>
                                        Post Job
                                    </button>
                                    <button @click="showLeaveModal = true"
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
                            <!-- Inline sparkline -->
                            <div class="-mx-1 mt-3">
                                <svg viewBox="0 0 96 30" class="w-full" style="height:30px;overflow:visible">
                                    <defs>
                                        <linearGradient :id="`sg${i}`" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" :stop-color="card.color" stop-opacity="0.22"/>
                                            <stop offset="100%" :stop-color="card.color" stop-opacity="0.01"/>
                                        </linearGradient>
                                        <clipPath :id="`sc${i}`"><rect x="0" y="0" width="96" height="30"/></clipPath>
                                    </defs>
                                    <g :clip-path="`url(#sc${i})`">
                                        <path :d="sparkArea(card.spark)" :fill="`url(#sg${i})`" style="transition:d 0.8s ease"/>
                                        <polyline :points="sparkLine(card.spark)" fill="none" :stroke="card.color" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="transition:points 0.8s ease"/>
                                        <circle :cx="96" :cy="sparkLine(card.spark).split(' ').at(-1).split(',')[1]" r="2.5" :fill="card.color"/>
                                    </g>
                                </svg>
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
                                        <span class="h-2 w-4 rounded-full" style="background:linear-gradient(90deg,#0051d5,#316bf3)"></span>
                                        <span class="text-[9.5px] font-bold text-on-surface-variant">Leave requests</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Bars â€” grow to fill remaining card height with sensible bounds -->
                            <div class="flex items-end gap-1.5 flex-1 min-h-[140px] max-h-[280px]">
                                <div v-for="(h, i) in perfBarData" :key="i"
                                     class="flex-1 rounded-t relative overflow-hidden group cursor-default"
                                     :style="`height:${h}%;transition:height 0.6s cubic-bezier(0.22,1,0.36,1);`">
                                    <div class="absolute inset-0 rounded-t" style="background:linear-gradient(to top,#0051d5,rgba(99,131,255,0.65))"></div>
                                    <div class="absolute inset-0 opacity-0 group-hover:opacity-100 rounded-t transition-opacity" style="background:linear-gradient(to top,#7c5cff,rgba(124,92,255,0.7))"></div>
                                    <!-- Tooltip -->
                                    <div class="absolute -top-7 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity z-10 whitespace-nowrap rounded-lg px-2 py-1 text-[8px] font-black text-white shadow-lg" style="background:#0c0e14">{{ chartLeaveByMonth[i] ?? 0 }} requests</div>
                                </div>
                            </div>
                            <!-- Month labels -->
                            <div class="flex justify-between mt-2 flex-shrink-0">
                                <span v-for="m in ['J','F','M','A','M','J','J','A','S','O','N','D']" :key="m"
                                      class="flex-1 text-center text-[8.5px] font-bold text-on-surface-variant/40">{{ m }}</span>
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
                                        <button @click="showAddEmployeeModal = true"
                                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px active:scale-[0.97]"
                                                style="background:linear-gradient(135deg,#0051d5,#316bf3);">
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
                                        <button @click="showTicketModal = true" class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-1.5 text-xs font-bold text-primary hover:bg-surface-container-low transition-all">+ New Ticket</button>
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
                                    <button @click="showAddEmployeeModal = true"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-secondary/30 hover:bg-secondary/5 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-secondary/10 flex items-center justify-center text-secondary transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">person_add</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Add Employee</span>
                                    </button>
                                    <button @click="showLeaveModal = true"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-green-300 hover:bg-green-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-green-50 flex items-center justify-center text-green-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">calendar_month</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Leave Request</span>
                                    </button>
                                    <button @click="showTicketModal = true"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-amber-300 hover:bg-amber-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">confirmation_number</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">New Ticket</span>
                                    </button>
                                    <button @click="showJobModal = true"
                                            class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 text-center transition-all hover:border-purple-300 hover:bg-purple-50/40 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="h-10 w-10 rounded-xl bg-purple-50 flex items-center justify-center text-purple-600 transition-transform group-hover:scale-110">
                                            <span class="material-symbols-outlined text-xl" style="font-variation-settings:'FILL' 1">work</span>
                                        </div>
                                        <span class="text-[11px] font-black text-primary leading-snug">Post Job</span>
                                    </button>
                                    <button @click="showAddDeptModal = true"
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
                                 style="background:linear-gradient(135deg,#0051d5,#316bf3);border:1px solid rgba(255,255,255,0.1);">
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

    <!-- â”€â”€â”€ Modals â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->

    <!-- Add Employee Modal -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showAddEmployeeModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);" @click.self="showAddEmployeeModal = false">
                <Transition
                    enter-active-class="transition-all duration-250 ease-spring"
                    enter-from-class="opacity-0 scale-95 translate-y-4"
                    enter-to-class="opacity-100 scale-100 translate-y-0"
                >
                    <div v-if="showAddEmployeeModal" class="w-full max-w-2xl rounded-3xl bg-surface-container-lowest shadow-lifted-lg overflow-hidden">
                        <!-- Modal Header -->
                        <div class="px-8 pt-8 pb-6 border-b border-outline-variant flex items-center justify-between"
                             style="background:linear-gradient(135deg,#0c0e14,#131620);">
                            <div class="flex items-center gap-4">
                                <div class="h-11 w-11 rounded-2xl bg-secondary/20 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-secondary text-2xl" style="font-variation-settings:'FILL' 1">badge</span>
                                </div>
                                <div>
                                    <h3 class="text-xl font-black text-white">Add New Employee</h3>
                                    <p class="text-[11px] font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Complete the onboarding profile below</p>
                                </div>
                            </div>
                            <button @click="showAddEmployeeModal = false"
                                    class="h-9 w-9 rounded-xl flex items-center justify-center transition-all hover:bg-white/10"
                                    style="color:rgba(255,255,255,0.5);">
                                <span class="material-symbols-outlined text-xl">close</span>
                            </button>
                        </div>
                        <!-- Modal Body -->
                        <form class="p-8 space-y-6" @submit.prevent="employeeForm.post(route('employees.store'), { onSuccess: () => showAddEmployeeModal = false })">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Employee No.</label>
                                    <input v-model="employeeForm.employee_no"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm font-mono focus:ring-secondary/20 focus:border-secondary transition-all"
                                           placeholder="e.g. EMP-001" required />
                                    <p v-if="employeeForm.errors.employee_no" class="text-[10px] font-bold text-red-600">{{ employeeForm.errors.employee_no }}</p>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Position / Role</label>
                                    <input v-model="employeeForm.position"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all"
                                           placeholder="e.g. Senior Engineer" required />
                                    <p v-if="employeeForm.errors.position" class="text-[10px] font-bold text-red-600">{{ employeeForm.errors.position }}</p>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Hire Date</label>
                                    <input v-model="employeeForm.hire_date"
                                           type="date"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all"
                                           required />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Phone Number</label>
                                    <input v-model="employeeForm.phone"
                                           type="tel"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all"
                                           placeholder="+233 XX XXX XXXX" />
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Department</label>
                                <input v-model="employeeForm.department_id"
                                       class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all"
                                       placeholder="Department ID" />
                                <p v-if="employeeForm.errors.department_id" class="text-[10px] font-bold text-red-600">{{ employeeForm.errors.department_id }}</p>
                            </div>
                            <div class="flex gap-4 pt-2">
                                <button type="button" @click="showAddEmployeeModal = false"
                                        class="flex-1 rounded-xl border border-outline-variant py-3.5 text-sm font-black text-primary hover:bg-surface-container-low transition-all">
                                    Cancel
                                </button>
                                <button type="submit"
                                        class="btn-shimmer flex-1 rounded-xl py-3.5 text-sm font-black text-white shadow-glow-sm transition-all hover:shadow-glow disabled:opacity-60"
                                        style="background:linear-gradient(135deg,#0051d5,#316bf3);"
                                        :disabled="employeeForm.processing">
                                    {{ employeeForm.processing ? 'Addingâ€¦' : 'Add Employee' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>

    <!-- Add Department Modal -->
    <Teleport to="body">
        <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-all duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showAddDeptModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);" @click.self="showAddDeptModal = false">
                <div class="w-full max-w-md rounded-3xl bg-surface-container-lowest shadow-lifted-lg overflow-hidden">
                    <div class="px-8 pt-7 pb-5 border-b border-outline-variant flex items-center justify-between" style="background:linear-gradient(135deg,#0c0e14,#131620);">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-cyan-500/20 flex items-center justify-center">
                                <span class="material-symbols-outlined text-cyan-400 text-xl" style="font-variation-settings:'FILL' 1">corporate_fare</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-white">New Department</h3>
                                <p class="text-[10px] font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Create an organisational unit</p>
                            </div>
                        </div>
                        <button @click="showAddDeptModal = false" class="h-8 w-8 rounded-xl flex items-center justify-center hover:bg-white/10" style="color:rgba(255,255,255,0.5);">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                    <form class="p-8 space-y-5" @submit.prevent="departmentForm.post(route('departments.store'), { onSuccess: () => showAddDeptModal = false })">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Department Name</label>
                            <input v-model="departmentForm.name" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="e.g. Technology" required />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Department Code</label>
                            <input v-model="departmentForm.code" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm font-mono focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="e.g. TECH" required />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Description (optional)</label>
                            <textarea v-model="departmentForm.description" rows="3" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm resize-none focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="Brief descriptionâ€¦"></textarea>
                        </div>
                        <div class="flex gap-4 pt-2">
                            <button type="button" @click="showAddDeptModal = false" class="flex-1 rounded-xl border border-outline-variant py-3.5 text-sm font-black text-primary hover:bg-surface-container-low transition-all">Cancel</button>
                            <button type="submit" class="btn-shimmer flex-1 rounded-xl py-3.5 text-sm font-black text-white shadow-glow-sm transition-all hover:shadow-glow" style="background:linear-gradient(135deg,#0051d5,#316bf3);" :disabled="departmentForm.processing">
                                {{ departmentForm.processing ? 'Creatingâ€¦' : 'Create Department' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Leave Request Modal -->
    <Teleport to="body">
        <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-all duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showLeaveModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);" @click.self="showLeaveModal = false">
                <div class="w-full max-w-lg rounded-3xl bg-surface-container-lowest shadow-lifted-lg overflow-hidden">
                    <div class="px-8 pt-7 pb-5 border-b border-outline-variant flex items-center justify-between" style="background:linear-gradient(135deg,#0c0e14,#131620);">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-green-500/20 flex items-center justify-center">
                                <span class="material-symbols-outlined text-green-400 text-xl" style="font-variation-settings:'FILL' 1">calendar_month</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-white">Leave Application</h3>
                                <p class="text-[10px] font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Submit a leave or time-off request</p>
                            </div>
                        </div>
                        <button @click="showLeaveModal = false" class="h-8 w-8 rounded-xl flex items-center justify-center hover:bg-white/10" style="color:rgba(255,255,255,0.5);">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                    <form class="p-8 space-y-5" @submit.prevent="leaveForm.post(route('leave.store'), { onSuccess: () => showLeaveModal = false })">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Staff ID</label>
                            <input v-model="leaveForm.employee_id" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="Enter Staff ID" required />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Start Date</label>
                                <input v-model="leaveForm.start_date" type="date" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" required />
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">End Date</label>
                                <input v-model="leaveForm.end_date" type="date" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" required />
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Leave Type</label>
                            <select v-model="leaveForm.type" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all">
                                <option value="annual">Annual Leave</option>
                                <option value="sick">Sick Leave</option>
                                <option value="maternity">Maternity / Paternity</option>
                                <option value="study">Study Leave</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Reason</label>
                            <textarea v-model="leaveForm.reason" rows="3" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm resize-none focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="Brief reason for leaveâ€¦"></textarea>
                        </div>
                        <div class="flex gap-4 pt-2">
                            <button type="button" @click="showLeaveModal = false" class="flex-1 rounded-xl border border-outline-variant py-3.5 text-sm font-black text-primary hover:bg-surface-container-low transition-all">Cancel</button>
                            <button type="submit" class="btn-shimmer flex-1 rounded-xl py-3.5 text-sm font-black text-white shadow-glow-sm transition-all hover:shadow-glow" style="background:linear-gradient(135deg,#0051d5,#316bf3);" :disabled="leaveForm.processing">
                                {{ leaveForm.processing ? 'Submittingâ€¦' : 'Submit Request' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- New Ticket Modal -->
    <Teleport to="body">
        <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-all duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showTicketModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);" @click.self="showTicketModal = false">
                <div class="w-full max-w-lg rounded-3xl bg-surface-container-lowest shadow-lifted-lg overflow-hidden">
                    <div class="px-8 pt-7 pb-5 border-b border-outline-variant flex items-center justify-between" style="background:linear-gradient(135deg,#0c0e14,#131620);">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-amber-500/20 flex items-center justify-center">
                                <span class="material-symbols-outlined text-amber-400 text-xl" style="font-variation-settings:'FILL' 1">confirmation_number</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-white">Open Service Ticket</h3>
                                <p class="text-[10px] font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Submit an IT or HR support request</p>
                            </div>
                        </div>
                        <button @click="showTicketModal = false" class="h-8 w-8 rounded-xl flex items-center justify-center hover:bg-white/10" style="color:rgba(255,255,255,0.5);">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                    <form class="p-8 space-y-5" @submit.prevent="ticketForm.post(route('tickets.store'), { onSuccess: () => showTicketModal = false })">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Issue Summary</label>
                            <input v-model="ticketForm.title" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="Brief title of the issue" required />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Description</label>
                            <textarea v-model="ticketForm.description" rows="4" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm resize-none focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="Describe the issue in detailâ€¦"></textarea>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Priority</label>
                                <select v-model="ticketForm.priority" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High â€” Urgent</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Due Date (optional)</label>
                                <input v-model="ticketForm.due_at" type="date" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" />
                            </div>
                        </div>
                        <div class="flex gap-4 pt-2">
                            <button type="button" @click="showTicketModal = false" class="flex-1 rounded-xl border border-outline-variant py-3.5 text-sm font-black text-primary hover:bg-surface-container-low transition-all">Cancel</button>
                            <button type="submit" class="btn-shimmer flex-1 rounded-xl py-3.5 text-sm font-black text-white shadow-glow-sm transition-all hover:shadow-glow" style="background:linear-gradient(135deg,#0051d5,#316bf3);" :disabled="ticketForm.processing">
                                {{ ticketForm.processing ? 'Submittingâ€¦' : 'Open Ticket' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Post Job Modal -->
    <Teleport to="body">
        <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-all duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showJobModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);" @click.self="showJobModal = false">
                <div class="w-full max-w-lg rounded-3xl bg-surface-container-lowest shadow-lifted-lg overflow-hidden">
                    <div class="px-8 pt-7 pb-5 border-b border-outline-variant flex items-center justify-between" style="background:linear-gradient(135deg,#0c0e14,#131620);">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-purple-500/20 flex items-center justify-center">
                                <span class="material-symbols-outlined text-purple-400 text-xl" style="font-variation-settings:'FILL' 1">work</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-white">Post Job Opening</h3>
                                <p class="text-[10px] font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Create a new recruitment posting</p>
                            </div>
                        </div>
                        <button @click="showJobModal = false" class="h-8 w-8 rounded-xl flex items-center justify-center hover:bg-white/10" style="color:rgba(255,255,255,0.5);">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                    <form class="p-8 space-y-5" @submit.prevent="jobForm.post(route('jobs.store'), { onSuccess: () => showJobModal = false })">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Job Title</label>
                            <input v-model="jobForm.title" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="e.g. Senior Solutions Architect" required />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Job Description</label>
                            <textarea v-model="jobForm.description" rows="5" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm resize-none focus:ring-secondary/20 focus:border-secondary transition-all" placeholder="Role overview, responsibilities, requirementsâ€¦"></textarea>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Application Deadline</label>
                            <input v-model="jobForm.closes_at" type="date" class="w-full rounded-xl border-outline-variant bg-surface-container-low px-4 py-3 text-sm focus:ring-secondary/20 focus:border-secondary transition-all" required />
                        </div>
                        <div class="flex gap-4 pt-2">
                            <button type="button" @click="showJobModal = false" class="flex-1 rounded-xl border border-outline-variant py-3.5 text-sm font-black text-primary hover:bg-surface-container-low transition-all">Cancel</button>
                            <button type="submit" class="btn-shimmer flex-1 rounded-xl py-3.5 text-sm font-black text-white shadow-glow-sm transition-all hover:shadow-glow" style="background:linear-gradient(135deg,#0051d5,#316bf3);" :disabled="jobForm.processing">
                                {{ jobForm.processing ? 'Postingâ€¦' : 'Post Job' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>
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
