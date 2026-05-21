<script setup>
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    currentRun: Object,  // { period, status, totalGross, totalNet, totalDeductions, headcount } | null
    recentRuns: Array,   // [{ period, gross, net, headcount, status, approved_at }]
    stats:      Object,  // { pendingPayments, paidThisMonth, totalPayroll }
    activeModule: String,
});

// ── Ghana currency formatter ──────────────────────────────────────────────────
const ghs = (n) => {
    if (n == null || isNaN(n)) return 'GHS 0.00';
    return 'GHS ' + Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// ── Deadline countdowns ───────────────────────────────────────────────────────
const today = new Date();

function daysUntil(dayOfMonth) {
    const y  = today.getFullYear();
    const m  = today.getMonth();
    let target = new Date(y, m, dayOfMonth);
    if (target <= today) target = new Date(y, m + 1, dayOfMonth);
    return Math.ceil((target - today) / 86_400_000);
}

const ssnitDays = computed(() => daysUntil(14));
const payeDays  = computed(() => daysUntil(15));

function deadlineColor(days) {
    if (days <= 3) return { text: 'text-red-600 dark:text-red-400', bg: 'bg-red-50 dark:bg-red-900/20', border: 'border-red-200 dark:border-red-800/40', dot: 'bg-red-500' };
    if (days <= 7) return { text: 'text-amber-600 dark:text-amber-400', bg: 'bg-amber-50 dark:bg-amber-900/20', border: 'border-amber-200 dark:border-amber-800/40', dot: 'bg-amber-500' };
    return { text: 'text-green-600 dark:text-green-400', bg: 'bg-green-50 dark:bg-green-900/20', border: 'border-green-200 dark:border-green-800/40', dot: 'bg-green-500' };
}

const ssnitColor = computed(() => deadlineColor(ssnitDays.value));
const payeColor  = computed(() => deadlineColor(payeDays.value));

const monthName = computed(() => {
    const d  = new Date();
    const dd = 14;
    if (d.getDate() >= dd) d.setMonth(d.getMonth() + 1);
    return d.toLocaleString('en-GH', { month: 'long', year: 'numeric' });
});

const nextMonth = computed(() => {
    const d  = new Date();
    const dd = 15;
    if (d.getDate() >= dd) d.setMonth(d.getMonth() + 1);
    return d.toLocaleString('en-GH', { month: 'long', year: 'numeric' });
});

// ── Current run helpers ───────────────────────────────────────────────────────
const runGradient = computed(() => {
    const map = {
        draft:      'from-slate-600 to-slate-700',
        processing: 'from-blue-600 to-blue-700',
        approved:   'from-emerald-600 to-emerald-700',
        paid:       'from-[#1a237e] to-[#3949ab]',
    };
    return map[props.currentRun?.status] ?? map.draft;
});

const runAction = computed(() => {
    const map = {
        draft:      { label: 'Continue Run', icon: 'play_arrow', route: 'payroll.run-wizard' },
        processing: { label: 'View Progress', icon: 'hourglass_top', route: 'payroll.run-wizard' },
        approved:   { label: 'View Payslips', icon: 'receipt_long', route: 'payroll.payslips' },
        paid:       { label: 'Download Bank File', icon: 'download', route: 'payroll.bank-file' },
    };
    return map[props.currentRun?.status] ?? map.draft;
});

// ── Quick links ───────────────────────────────────────────────────────────────
const quickLinks = [
    { label: 'Salary Structures', icon: 'account_tree',    route: 'payroll.salary-structures', color: 'text-blue-500',   bg: 'bg-blue-500/10'   },
    { label: 'Salary Bands',      icon: 'bar_chart',        route: 'payroll.salary-bands',      color: 'text-blue-500', bg: 'bg-blue-500/10' },
    { label: 'Staff Loans',       icon: 'request_quote',    route: 'payroll.loans',             color: 'text-amber-500',  bg: 'bg-amber-500/10'  },
    { label: 'Statutory Reports', icon: 'description',      route: 'payroll.statutory-reports', color: 'text-green-500',  bg: 'bg-green-500/10'  },
];

// ── Live sync: Inertia partial reload every 15—20s (random) ─────────────────
const lastSync  = ref(Date.now());
const isSyncing = ref(false);
const nowTick   = ref(Date.now());
const syncAgoLabel = computed(() => {
    const s = Math.max(0, Math.floor((nowTick.value - lastSync.value) / 1000));
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    return Math.floor(s / 3600) + 'h';
});

const _intervals = [];
let   _reloadTimer = null;
const nextReloadMs = () => 15000 + Math.floor(Math.random() * 5001);

function scheduleServerReload() {
    _reloadTimer = setTimeout(() => {
        isSyncing.value = true;
        router.reload({
            only: ['stats', 'currentRun', 'recentRuns'],
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
    _intervals.push(setInterval(() => { nowTick.value = Date.now(); }, 1000));
    scheduleServerReload();
});

onBeforeUnmount(() => {
    _intervals.forEach(clearInterval);
    if (_reloadTimer) clearTimeout(_reloadTimer);
});
</script>

<template>
    <Head title="Payroll" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Payroll</h2>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Manage payroll runs, SSNIT/PAYE compliance, and statutory reporting.
                        </p>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <!-- Live sync pill — pulses while reloading, otherwise shows seconds since last refresh -->
                        <div class="flex items-center gap-1.5 rounded-full px-3 py-1.5 border"
                             :class="isSyncing
                                ? 'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-950/40 dark:border-blue-800/40 dark:text-blue-300'
                                : 'bg-green-50 border-green-100 text-green-700 dark:bg-green-950/40 dark:border-green-800/40 dark:text-green-300'">
                            <span class="h-1.5 w-1.5 rounded-full"
                                  :class="isSyncing ? 'bg-blue-500 animate-pulse' : 'bg-green-500 live-dot'"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest">
                                {{ isSyncing ? 'Syncing…' : `Live · ${syncAgoLabel}` }}
                            </span>
                        </div>
                        <Link
                            :href="route('payroll.statutory-reports')"
                            class="flex items-center gap-2 rounded-xl border border-outline-variant/70 bg-surface-container-lowest px-4 py-2.5 text-[13px] font-bold text-on-surface shadow-sm transition-all duration-150 hover:bg-surface-container-low hover:-translate-y-px active:scale-[0.97]"
                        >
                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant">description</span>
                            Statutory Reports
                        </Link>
                        <Link
                            :href="route('payroll.run-wizard')"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all duration-150 hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e);"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Start New Payroll Run
                        </Link>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-7 animate-reveal-up">

                <!-- ── Deadline countdown widgets ──────────────────────────────── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- SSNIT Deadline -->
                    <div
                        :class="['flex items-center gap-4 rounded-2xl border px-5 py-4 transition-all', ssnitColor.bg, ssnitColor.border]"
                    >
                        <div :class="['flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl', ssnitColor.bg]">
                            <span class="material-symbols-outlined text-[22px]" :class="ssnitColor.text"
                                  style="font-variation-settings:'FILL' 1">account_balance</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-black uppercase tracking-widest" :class="ssnitColor.text">SSNIT Remittance</p>
                            <div class="flex items-baseline gap-2 mt-0.5">
                                <span class="text-[26px] font-black leading-none text-on-surface">{{ ssnitDays }}</span>
                                <span class="text-[13px] font-semibold text-on-surface-variant">days remaining</span>
                            </div>
                            <p class="text-[11px] font-medium text-on-surface-variant mt-0.5">Due 14th {{ monthName }}</p>
                        </div>
                        <div :class="['h-2 w-2 rounded-full flex-shrink-0 animate-pulse', ssnitColor.dot]"></div>
                    </div>

                    <!-- PAYE Deadline -->
                    <div
                        :class="['flex items-center gap-4 rounded-2xl border px-5 py-4 transition-all', payeColor.bg, payeColor.border]"
                    >
                        <div :class="['flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl', payeColor.bg]">
                            <span class="material-symbols-outlined text-[22px]" :class="payeColor.text"
                                  style="font-variation-settings:'FILL' 1">receipt_long</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-black uppercase tracking-widest" :class="payeColor.text">PAYE Remittance</p>
                            <div class="flex items-baseline gap-2 mt-0.5">
                                <span class="text-[26px] font-black leading-none text-on-surface">{{ payeDays }}</span>
                                <span class="text-[13px] font-semibold text-on-surface-variant">days remaining</span>
                            </div>
                            <p class="text-[11px] font-medium text-on-surface-variant mt-0.5">Due 15th {{ nextMonth }}</p>
                        </div>
                        <div :class="['h-2 w-2 rounded-full flex-shrink-0 animate-pulse', payeColor.dot]"></div>
                    </div>
                </div>

                <!-- ── Current payroll run card ────────────────────────────────── -->
                <div v-if="currentRun" class="overflow-hidden rounded-2xl border border-outline-variant/50 shadow-card">
                    <!-- Gradient header -->
                    <div :class="['bg-gradient-to-r px-7 py-5 text-white', runGradient]">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-[11px] font-black uppercase tracking-widest text-white/60">Current Payroll Run</p>
                                <h3 class="text-[22px] font-black mt-1 leading-tight">{{ currentRun.period }}</h3>
                            </div>
                            <div class="flex items-center gap-3">
                                <StatusBadge :status="currentRun.status" type="payment" />
                                <span class="text-[13px] font-semibold text-white/70">
                                    <span class="material-symbols-outlined text-[16px] align-middle mr-1">people</span>
                                    {{ currentRun.headcount?.toLocaleString() }} employees
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics row -->
                    <div class="bg-surface-container-lowest px-7 py-5">
                        <div class="grid grid-cols-3 gap-6 mb-5">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-on-surface-variant">Total Gross</p>
                                <p class="text-[20px] font-black text-on-surface mt-1">{{ ghs(currentRun.totalGross) }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-on-surface-variant">Total Deductions</p>
                                <p class="text-[20px] font-black text-red-600 dark:text-red-400 mt-1">- {{ ghs(currentRun.totalDeductions) }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-on-surface-variant">Net Pay</p>
                                <p class="text-[20px] font-black text-secondary mt-1">{{ ghs(currentRun.totalNet) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <Link
                                :href="route(runAction.route)"
                                class="flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white transition-all hover:-translate-y-px active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);"
                            >
                                <span class="material-symbols-outlined text-[18px]">{{ runAction.icon }}</span>
                                {{ runAction.label }}
                            </Link>
                            <Link
                                :href="route('payroll.show', currentRun)"
                                class="flex items-center gap-2 rounded-xl border border-outline-variant px-5 py-2.5 text-[13px] font-bold text-on-surface hover:bg-surface-container-low transition-all"
                            >
                                <span class="material-symbols-outlined text-[18px]">visibility</span>
                                View Details
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- ── Stats row ──────────────────────────────────────────────── -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <StatCard
                        icon="pending_actions"
                        :value="stats?.pendingPayments ?? 0"
                        label="Pending Payments"
                        color="amber"
                        trend="Awaiting disbursement"
                        :trend-up="false"
                    />
                    <StatCard
                        icon="check_circle"
                        :value="stats?.paidThisMonth ?? 0"
                        label="Paid This Month"
                        color="green"
                        trend="On schedule"
                        :trend-up="true"
                    />
                    <StatCard
                        icon="payments"
                        :value="ghs(stats?.totalPayroll)"
                        label="Total Payroll Cost YTD"
                        color="gold"
                        trend="Year-to-date"
                        :trend-up="true"
                    />
                </div>

                <!-- ── Quick links ─────────────────────────────────────────────── -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <Link
                        v-for="link in quickLinks"
                        :key="link.label"
                        :href="route(link.route)"
                        class="group flex flex-col items-center gap-3 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 text-center transition-all hover:border-secondary/30 hover:shadow-card hover:-translate-y-0.5"
                    >
                        <div :class="['h-11 w-11 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110', link.bg]">
                            <span class="material-symbols-outlined text-[22px]" :class="link.color"
                                  style="font-variation-settings:'FILL' 1">{{ link.icon }}</span>
                        </div>
                        <span class="text-[12px] font-bold text-on-surface leading-tight">{{ link.label }}</span>
                    </Link>
                </div>

                <!-- ── Run history table ────────────────────────────────────────── -->
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/40">
                        <div class="flex items-center gap-3">
                            <h3 class="text-[16px] font-black text-on-surface">Payroll Run History</h3>
                            <span class="rounded-full bg-surface-container-low px-2.5 py-0.5 text-[11px] font-bold text-on-surface-variant">
                                {{ recentRuns?.length ?? 0 }} runs
                            </span>
                        </div>
                        <Link :href="route('reports.index')" class="flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low transition-all">
                            <span class="material-symbols-outlined text-[16px]">download</span>
                            Export All
                        </Link>
                    </div>

                    <EmptyState
                        v-if="!recentRuns || recentRuns.length === 0"
                        icon="payments"
                        title="No payroll runs yet"
                        description="Start your first payroll run to see history here."
                    />

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-[13px]">
                            <thead>
                                <tr class="border-b border-outline-variant/40 bg-surface-container-low/50">
                                    <th class="px-6 py-3 text-left text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Period</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Headcount</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Gross (GHS)</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Deductions</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Net Pay</th>
                                    <th class="px-4 py-3 text-center text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Status</th>
                                    <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Approved</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/30">
                                <tr
                                    v-for="run in recentRuns"
                                    :key="run.period"
                                    class="hover:bg-surface-container-low/40 transition-colors"
                                >
                                    <td class="px-6 py-3.5 font-bold text-on-surface">{{ run.period }}</td>
                                    <td class="px-4 py-3.5 text-right text-on-surface-variant">{{ run.headcount?.toLocaleString() }}</td>
                                    <td class="px-4 py-3.5 text-right font-semibold text-on-surface">{{ ghs(run.gross) }}</td>
                                    <td class="px-4 py-3.5 text-right text-red-600 dark:text-red-400 font-medium">
                                        {{ run.deductions ? '- ' + ghs(run.deductions) : '—' }}
                                    </td>
                                    <td class="px-4 py-3.5 text-right font-bold text-secondary">{{ ghs(run.net) }}</td>
                                    <td class="px-4 py-3.5 text-center">
                                        <StatusBadge :status="run.status" type="payment" />
                                    </td>
                                    <td class="px-4 py-3.5 text-on-surface-variant text-[12px]">
                                        {{ run.approved_at ? new Date(run.approved_at).toLocaleDateString('en-GH') : '—' }}
                                    </td>
                                    <td class="px-4 py-3.5 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <Link
                                                :href="route('payments.index', { month: run.period })"
                                                class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-[11px] font-bold text-secondary hover:bg-secondary/8 transition-colors"
                                            >
                                                <span class="material-symbols-outlined text-[14px]">receipt_long</span>
                                                Payslips
                                            </Link>
                                            <Link
                                                :href="route('reports.index')"
                                                class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors"
                                                title="Export payroll report"
                                            >
                                                <span class="material-symbols-outlined text-[14px]">download</span>
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
    </div>
</template>
