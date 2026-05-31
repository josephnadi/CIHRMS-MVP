<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    payments:     Object,
    employees:    Array,
    analytics:    Object,
    filters:      Object,
    activeModule: String,
});

// ── Editorial-Sovereign masthead label ───────────────────────────
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date:    d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

const cediShort = (v) => {
    const n = Number(v) || 0;
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1_000)     return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
    return n.toLocaleString('en-GH');
};

// ── Analytics helpers ────────────────────────────────────────────────────────
const A = computed(() => props.analytics ?? {});
const totals = computed(() => A.value.totals ?? {});

const volumeMax = computed(() => Math.max(...(A.value.volumeByMonth ?? []).map(d => d.value), 1));

const statusColorMap = {
    pending:   '#d97706',
    paid:      '#059669',
    failed:    '#dc2626',
    cancelled: '#94a3b8',
};

const statusBreakdown = computed(() => {
    const data = A.value.statusBreakdown ?? [];
    const total = data.reduce((s, d) => s + d.value, 0);
    if (!total) return [];
    let offset = 0;
    const c = 2 * Math.PI * 42;
    return data.map(d => {
        const key = d.label.toLowerCase();
        const pct = d.value / total;
        const len = pct * c;
        const seg = {
            ...d,
            color: statusColorMap[key] ?? '#3949ab',
            dashArray: `${len} ${c - len}`,
            dashOffset: -offset,
            pct: (pct * 100).toFixed(1),
        };
        offset += len;
        return seg;
    });
});

const totalStatusCount = computed(() => (A.value.statusBreakdown ?? []).reduce((s, d) => s + d.value, 0));
const currencyMax = computed(() => Math.max(...(A.value.currencySplit ?? []).map(d => d.value), 1));

// ── Tabs ─────────────────────────────────────────────────────────────────────
const tab = ref(props.filters?.status === 'pending' ? 'pending' : 'all');

const localFilters = reactive({
    status:      props.filters?.status      ?? '',
    employee_id: props.filters?.employee_id ?? '',
    month:       props.filters?.month       ?? '',
});

const applyFilters = () => {
    router.get(route('payments.index'), {
        status:      localFilters.status      || undefined,
        employee_id: localFilters.employee_id || undefined,
        month:       localFilters.month       || undefined,
    }, { preserveState: true, replace: true });
};

const switchTab = (next) => {
    tab.value = next;
    localFilters.status = next === 'pending' ? 'pending' : next === 'paid' ? 'paid' : '';
    applyFilters();
};

// ── Stats ────────────────────────────────────────────────────────────────────
const data = computed(() => props.payments?.data ?? []);
const stats = computed(() => ({
    total:     props.payments?.meta?.total ?? data.value.length,
    pending:   data.value.filter(p => p.status === 'pending').length,
    paid:      data.value.filter(p => p.status === 'paid').length,
    totalGhs:  data.value.reduce((sum, p) => sum + parseFloat(p.amount ?? 0), 0),
}));

// ── Quick payment form ───────────────────────────────────────────────────────
const showCreatePanel = ref(false);

// Auto-open the "Record Payment" panel when arriving via Quick Action (?new=1).
// Strip the flag immediately so refresh + post-submit back() don't re-trigger
// the panel and leave the backdrop stuck over the page.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showCreatePanel.value = true;
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash,
        );
    }
});
const form = useForm({
    employee_id: '',
    description: '',
    amount:      '',
    currency:    'GHS',
});

const submit = () => {
    form.post(route('payments.store'), {
        onSuccess: () => {
            form.reset();
            showCreatePanel.value = false;
        },
    });
};

// ── Ghana payslip generator ─────────────────────────────────────────────────
const showPayslipPanel = ref(false);
const currentMonth = new Date().toISOString().slice(0, 7);

const payslipForm = useForm({
    employee_id:          '',
    period:               currentMonth,
    basic:                '',
    allowances: [
        { label: 'Transport Allowance',     amount: '' },
        { label: 'Rent / Housing Allowance',amount: '' },
    ],
    voluntary_deductions: [],
    tier3_employee:       '',
    mark_paid:            false,
});

const addAllowance = () => payslipForm.allowances.push({ label: '', amount: '' });
const removeAllowance = (i) => payslipForm.allowances.splice(i, 1);
const addDeduction = () => payslipForm.voluntary_deductions.push({ label: '', amount: '' });
const removeDeduction = (i) => payslipForm.voluntary_deductions.splice(i, 1);

// Live PAYE bracket table (mirrors backend, used for instant preview)
const PAYE_BANDS = [
    { upper: 490.00,    rate: 0.00  },
    { upper: 600.00,    rate: 0.05  },
    { upper: 730.00,    rate: 0.10  },
    { upper: 3896.67,   rate: 0.175 },
    { upper: 19896.67,  rate: 0.25  },
    { upper: 50416.67,  rate: 0.30  },
    { upper: null,      rate: 0.35  },
];
const SSNIT_TIER1_EMP = 0.055;
const SSNIT_TIER1_ER  = 0.13;
const SSNIT_TIER2_ER  = 0.05;
const SSNIT_MAX_INS   = 61000;
const TIER3_CAP_RATE  = 0.165;

const computePAYE = (chargeable) => {
    if (chargeable <= 0) return { total: 0, bands: [] };
    let tax = 0, prev = 0;
    const bands = [];
    for (const b of PAYE_BANDS) {
        if (b.upper === null) {
            const span = chargeable - prev;
            if (span > 0) {
                const t = span * b.rate;
                tax += t;
                bands.push({ lower: prev, upper: null, rate: b.rate, amount: span, tax: t });
            }
            break;
        }
        const width = b.upper - prev;
        if (chargeable > b.upper) {
            const t = width * b.rate;
            tax += t;
            bands.push({ lower: prev, upper: b.upper, rate: b.rate, amount: width, tax: t });
            prev = b.upper;
        } else {
            const span = chargeable - prev;
            if (span > 0) {
                const t = span * b.rate;
                tax += t;
                bands.push({ lower: prev, upper: b.upper, rate: b.rate, amount: span, tax: t });
            }
            break;
        }
    }
    return { total: Math.round(tax * 100) / 100, bands };
};

const payslipPreview = computed(() => {
    const basic = parseFloat(payslipForm.basic) || 0;
    const allowances = (payslipForm.allowances || []).map(a => ({
        label:  a.label,
        amount: parseFloat(a.amount) || 0,
    }));
    const voluntary = (payslipForm.voluntary_deductions || []).map(d => ({
        label:  d.label,
        amount: parseFloat(d.amount) || 0,
    }));
    const tier3 = parseFloat(payslipForm.tier3_employee) || 0;

    const allowanceTotal = allowances.reduce((s, a) => s + a.amount, 0);
    const voluntaryTotal = voluntary.reduce((s, d) => s + d.amount, 0);
    const gross = basic + allowanceTotal;

    const ssnitBase = Math.min(basic, SSNIT_MAX_INS);
    const ssnitEmp  = Math.round(ssnitBase * SSNIT_TIER1_EMP * 100) / 100;
    const ssnitEr1  = Math.round(ssnitBase * SSNIT_TIER1_ER  * 100) / 100;
    const ssnitEr2  = Math.round(ssnitBase * SSNIT_TIER2_ER  * 100) / 100;

    const tier3Cap        = Math.round(basic * TIER3_CAP_RATE * 100) / 100;
    const tier3Deductible = Math.min(Math.max(tier3, 0), tier3Cap);

    const chargeable = Math.max(gross - ssnitEmp - tier3Deductible, 0);
    const paye = computePAYE(chargeable);

    const netPay = Math.round((gross - ssnitEmp - paye.total - voluntaryTotal - tier3) * 100) / 100;
    const employerCost = Math.round((gross + ssnitEr1 + ssnitEr2) * 100) / 100;

    return {
        basic, allowances, allowanceTotal, voluntary, voluntaryTotal,
        gross, ssnitEmp, ssnitEr1, ssnitEr2, tier3, tier3Cap, tier3Deductible,
        chargeable, paye, netPay, employerCost,
    };
});

const submitPayslip = () => {
    payslipForm.post(route('payments.payslip.generate'), {
        onSuccess: () => {
            showPayslipPanel.value = false;
        },
    });
};

// ── Compliance deadlines ─────────────────────────────────────────────────────
const compliance = computed(() => {
    const today = new Date();
    const yr = today.getFullYear();
    const mo = today.getMonth();
    const day = today.getDate();

    // SSNIT due 14th of following month, PAYE due 15th
    const ssnitDueDay = 14;
    const payeDueDay  = 15;

    const buildDeadline = (dueDay) => {
        // The current obligation refers to the previous month's payroll
        let target = new Date(yr, mo, dueDay);
        if (day > dueDay) target = new Date(yr, mo + 1, dueDay);

        const obligationMonth = new Date(target.getFullYear(), target.getMonth() - 1, 1);
        const daysLeft = Math.ceil((target.getTime() - today.getTime()) / 86400000);

        const tone = daysLeft < 0 ? 'overdue' : daysLeft <= 3 ? 'urgent' : daysLeft <= 7 ? 'warning' : 'ok';

        return {
            obligation: obligationMonth.toLocaleDateString('en-GH', { month: 'long', year: 'numeric' }),
            deadline:   target.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }),
            daysLeft,
            tone,
        };
    };

    return {
        ssnit: buildDeadline(ssnitDueDay),
        paye:  buildDeadline(payeDueDay),
    };
});

const toneClass = (tone) => ({
    ok:      'border-green-200 dark:border-green-900/40 bg-green-50/60 dark:bg-green-950/20 text-green-700 dark:text-green-300',
    warning: 'border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-950/20 text-amber-700 dark:text-amber-300',
    urgent:  'border-orange-200 dark:border-orange-900/40 bg-orange-50/60 dark:bg-orange-950/20 text-orange-700 dark:text-orange-300',
    overdue: 'border-red-200 dark:border-red-900/40 bg-red-50/60 dark:bg-red-950/20 text-red-700 dark:text-red-300',
}[tone] ?? '');

// ── Mark paid ────────────────────────────────────────────────────────────────
const markPaid = (id) => {
    router.patch(route('payments.paid', id), {}, { preserveScroll: true });
};

// ── Helpers ──────────────────────────────────────────────────────────────────
const formatCurrency = (amount, currency = 'GHS') => {
    if (amount == null) return `${currency} 0.00`;
    return `${currency} ${Number(amount).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head title="Payroll" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">payments</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">PAYMENT RECORD</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Payroll</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            PAYE · SSNIT Tier-1/Tier-2 · GRA remittance — Ghana-compliant payslip processing.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showPayslipPanel = true" type="button"
                                class="flex items-center gap-2 rounded-xl border border-outline-variant/50 bg-surface-container-lowest px-4 py-2.5 text-[13px] font-black text-primary shadow-card transition-all hover:-translate-y-px hover:shadow-card-hover">
                            <span class="material-symbols-outlined text-[17px]">receipt_long</span>
                            Generate Payslip
                        </button>
                        <button @click="showCreatePanel = true" type="button"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">payments</span>
                            Quick Payment
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- ── GRA / SSNIT compliance strip ──────────────────────────── -->
                <div class="grid gap-3 md:grid-cols-2">
                    <div :class="['rounded-2xl border shadow-card p-4 flex items-center gap-4', toneClass(compliance.ssnit.tone)]">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/50 dark:bg-black/20 flex-shrink-0">
                            <span class="material-symbols-outlined text-[22px]" style="font-variation-settings:'FILL' 1">verified_user</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] opacity-80">SSNIT Tier-1 remittance</p>
                            <p class="mt-0.5 text-[13px] font-bold">For {{ compliance.ssnit.obligation }} payroll · due {{ compliance.ssnit.deadline }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-[20px] font-black tabular-nums leading-none">
                                <span v-if="compliance.ssnit.daysLeft < 0">{{ Math.abs(compliance.ssnit.daysLeft) }}d</span>
                                <span v-else>{{ compliance.ssnit.daysLeft }}d</span>
                            </p>
                            <p class="text-[10px] font-bold uppercase tracking-wider">
                                {{ compliance.ssnit.daysLeft < 0 ? 'overdue' : 'remaining' }}
                            </p>
                        </div>
                    </div>

                    <div :class="['rounded-2xl border shadow-card p-4 flex items-center gap-4', toneClass(compliance.paye.tone)]">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/50 dark:bg-black/20 flex-shrink-0">
                            <span class="material-symbols-outlined text-[22px]" style="font-variation-settings:'FILL' 1">account_balance</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] opacity-80">PAYE remittance (GRA)</p>
                            <p class="mt-0.5 text-[13px] font-bold">For {{ compliance.paye.obligation }} payroll · due {{ compliance.paye.deadline }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-[20px] font-black tabular-nums leading-none">
                                <span v-if="compliance.paye.daysLeft < 0">{{ Math.abs(compliance.paye.daysLeft) }}d</span>
                                <span v-else>{{ compliance.paye.daysLeft }}d</span>
                            </p>
                            <p class="text-[10px] font-bold uppercase tracking-wider">
                                {{ compliance.paye.daysLeft < 0 ? 'overdue' : 'remaining' }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ── Hero analytics row ────────────────────────────────────── -->
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">

                    <!-- This month -->
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-5 relative overflow-hidden"
                         style="background:linear-gradient(135deg,#1a237e,#3949ab);color:#fff">
                        <div class="absolute -right-6 -top-6 opacity-10">
                            <span class="material-symbols-outlined text-[90px]">payments</span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] opacity-60">This month paid</p>
                        <p class="mt-2 text-[24px] font-black font-mono leading-none">{{ formatCurrency(totals.this_month) }}</p>
                        <div class="mt-3 flex items-center gap-1.5 text-[11px]">
                            <span
                                :class="['inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 font-bold',
                                         (totals.delta_pct ?? 0) >= 0 ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300']"
                            >
                                <span class="material-symbols-outlined text-[13px]">{{ (totals.delta_pct ?? 0) >= 0 ? 'trending_up' : 'trending_down' }}</span>
                                {{ Math.abs(totals.delta_pct ?? 0) }}%
                            </span>
                            <span class="opacity-60">vs last month ({{ formatCurrency(totals.last_month) }})</span>
                        </div>
                    </div>

                    <!-- Pending volume -->
                    <div class="rounded-2xl border border-amber-200/60 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-950/20 shadow-card p-5">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-700 dark:text-amber-400">Pending payout</p>
                            <span class="material-symbols-outlined text-[20px] text-amber-600">schedule</span>
                        </div>
                        <p class="mt-2 text-[24px] font-black font-mono text-amber-900 dark:text-amber-200 leading-none">{{ formatCurrency(totals.total_pending) }}</p>
                        <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-400 font-semibold">{{ totals.pending_count ?? 0 }} records awaiting approval</p>
                    </div>

                    <!-- Total paid -->
                    <div class="rounded-2xl border border-green-200/60 dark:border-green-900/40 bg-green-50/60 dark:bg-green-950/20 shadow-card p-5">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-700 dark:text-green-400">Lifetime paid</p>
                            <span class="material-symbols-outlined text-[20px] text-green-600">check_circle</span>
                        </div>
                        <p class="mt-2 text-[24px] font-black font-mono text-green-900 dark:text-green-200 leading-none">{{ formatCurrency(totals.total_paid) }}</p>
                        <p class="mt-2 text-[11px] text-green-700 dark:text-green-400 font-semibold">{{ totals.paid_count ?? 0 }} completed payouts</p>
                    </div>

                    <!-- Earnings vs Deductions -->
                    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card p-5">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Cumulative split</p>
                        <div class="mt-3 space-y-2">
                            <div>
                                <div class="flex justify-between text-[11px] font-bold">
                                    <span class="text-green-700 dark:text-green-400">Earnings</span>
                                    <span class="text-on-surface font-mono">{{ formatCurrency(A.earningsVsDeductions?.earnings) }}</span>
                                </div>
                                <div class="mt-1 h-1.5 rounded-full bg-surface-container-low overflow-hidden">
                                    <div class="h-full bg-green-500" style="width:100%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-[11px] font-bold">
                                    <span class="text-red-700 dark:text-red-400">Deductions</span>
                                    <span class="text-on-surface font-mono">âˆ’{{ formatCurrency(A.earningsVsDeductions?.deductions) }}</span>
                                </div>
                                <div class="mt-1 h-1.5 rounded-full bg-surface-container-low overflow-hidden">
                                    <div
                                        class="h-full bg-red-500"
                                        :style="`width:${(A.earningsVsDeductions?.earnings ?? 0) > 0 ? Math.min(((A.earningsVsDeductions?.deductions ?? 0) / A.earningsVsDeductions.earnings) * 100, 100) : 0}%`"
                                    ></div>
                                </div>
                            </div>
                            <div class="pt-2 border-t border-outline-variant/40 flex justify-between text-[12px] font-bold">
                                <span class="text-on-surface-variant">Net</span>
                                <span class="text-on-surface font-mono">{{ formatCurrency(A.earningsVsDeductions?.net) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Charts row ────────────────────────────────────────────── -->
                <div class="grid gap-4 lg:grid-cols-3">

                    <!-- Monthly volume area chart -->
                    <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-[14px] font-bold text-on-surface">Monthly Payroll Volume</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">Paid amounts over the past 12 months</p>
                            </div>
                            <div class="inline-flex items-center gap-1.5 text-[10px] font-bold">
                                <span class="h-2 w-3 rounded-full" style="background:linear-gradient(90deg,#0d1452,#1a237e)"></span>
                                <span class="text-on-surface-variant">Paid (GHS)</span>
                            </div>
                        </div>

                        <svg viewBox="0 0 720 200" preserveAspectRatio="xMidYMid meet" class="w-full h-[200px]">
                            <defs>
                                <linearGradient id="payVolFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#1a237e" stop-opacity="0.32"/>
                                    <stop offset="100%" stop-color="#1a237e" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <g stroke="currentColor" class="text-outline-variant/40" stroke-dasharray="3 4">
                                <line x1="30" y1="30" x2="690" y2="30" />
                                <line x1="30" y1="100" x2="690" y2="100" />
                                <line x1="30" y1="170" x2="690" y2="170" />
                            </g>

                            <g v-if="(A.volumeByMonth ?? []).length">
                                <path
                                    :d="(() => {
                                        const data = A.volumeByMonth;
                                        const max = volumeMax;
                                        const step = (660) / Math.max(data.length - 1, 1);
                                        const top = data.map((d, i) => {
                                            const x = 30 + i * step;
                                            const y = 170 - (d.value / max) * 140;
                                            return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
                                        }).join(' ');
                                        return `${top} L 690 170 L 30 170 Z`;
                                    })()"
                                    fill="url(#payVolFill)"
                                />
                                <path
                                    :d="(() => {
                                        const data = A.volumeByMonth;
                                        const max = volumeMax;
                                        const step = (660) / Math.max(data.length - 1, 1);
                                        return data.map((d, i) => {
                                            const x = 30 + i * step;
                                            const y = 170 - (d.value / max) * 140;
                                            return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
                                        }).join(' ');
                                    })()"
                                    fill="none" stroke="url(#payVolLine)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                />
                                <linearGradient id="payVolLine" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0%" stop-color="#0d1452"/>
                                    <stop offset="100%" stop-color="#1a237e"/>
                                </linearGradient>
                            </g>

                            <g v-for="(p, i) in A.volumeByMonth ?? []" :key="i">
                                <circle
                                    v-if="p.value > 0"
                                    :cx="30 + i * (660 / Math.max((A.volumeByMonth?.length ?? 1) - 1, 1))"
                                    :cy="170 - (p.value / volumeMax) * 140"
                                    r="3" fill="#fff" stroke="#1a237e" stroke-width="2"
                                />
                                <text
                                    :x="30 + i * (660 / Math.max((A.volumeByMonth?.length ?? 1) - 1, 1))"
                                    y="190"
                                    class="fill-current text-on-surface-variant"
                                    text-anchor="middle" font-size="9.5" font-weight="600"
                                >{{ p.label }}</text>
                            </g>
                        </svg>
                    </div>

                    <!-- Status donut -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Status Breakdown</h3>
                        <p class="text-[11px] text-on-surface-variant mb-4">All payment records by state</p>

                        <div v-if="totalStatusCount === 0" class="py-10 text-center text-[12px] text-on-surface-variant/60 italic">
                            No payment data yet.
                        </div>

                        <div v-else class="flex items-center gap-3">
                            <svg viewBox="0 0 100 100" class="h-28 w-28 flex-shrink-0 -rotate-90">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" class="text-surface-container-low" stroke-width="13" />
                                <circle
                                    v-for="(seg, i) in statusBreakdown" :key="i"
                                    cx="50" cy="50" r="42" fill="none"
                                    :stroke="seg.color"
                                    stroke-width="13"
                                    :stroke-dasharray="seg.dashArray"
                                    :stroke-dashoffset="seg.dashOffset"
                                    style="transition: stroke-dasharray 0.8s ease, stroke-dashoffset 0.8s ease;"
                                />
                                <text x="50" y="50" text-anchor="middle" dominant-baseline="central" transform="rotate(90 50 50)" font-size="18" font-weight="900" class="fill-current text-on-surface">{{ totalStatusCount }}</text>
                            </svg>

                            <div class="flex-1 space-y-1.5 min-w-0">
                                <div v-for="(seg, i) in statusBreakdown" :key="i" class="flex items-center justify-between text-[11px]">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="h-2 w-2 rounded-full flex-shrink-0" :style="`background:${seg.color}`"></span>
                                        <span class="font-semibold text-on-surface truncate">{{ seg.label }}</span>
                                    </div>
                                    <span class="font-mono font-bold text-on-surface-variant flex-shrink-0">{{ seg.value }} · {{ seg.pct }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Insight row: top earners + currency split ─────────────── -->
                <div class="grid gap-4 lg:grid-cols-3">

                    <!-- Top earners -->
                    <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-[14px] font-bold text-on-surface">Top Earners</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">Highest-paid employees, last 6 months</p>
                            </div>
                        </div>

                        <div v-if="(A.topEarners ?? []).length === 0" class="py-6 text-center text-[12px] text-on-surface-variant/60 italic">
                            No paid payroll records in the last 6 months.
                        </div>

                        <div v-else class="space-y-2">
                            <div
                                v-for="(emp, i) in A.topEarners"
                                :key="emp.id"
                                class="group flex items-center gap-3 rounded-xl bg-surface-container-low/50 p-3 hover:bg-secondary/[0.05] transition-colors"
                            >
                                <div class="relative flex-shrink-0">
                                    <div
                                        class="flex h-10 w-10 items-center justify-center rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm text-[13px] font-black text-white transition-transform group-hover:scale-105"
                                        :style="`background:${['linear-gradient(135deg,#0d1452,#1a237e)','linear-gradient(135deg,#1a237e,#7986cb)','linear-gradient(135deg,#070b3a,#0d1452)','linear-gradient(135deg,#0d1452,#1a237e,#d912e3)','linear-gradient(135deg,#1a237e,#12d9e3)'][i % 5]}`"
                                    >
                                        {{ emp.name?.charAt(0) ?? '?' }}
                                    </div>
                                    <!-- Rank dot for top 3: gold for #1 (5% accent), then silver/bronze -->
                                    <span
                                        v-if="i < 3"
                                        class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full text-[8px] font-black text-white shadow-sm ring-2 ring-white dark:ring-surface-container-lowest"
                                        :style="`background:${['#ffd700','#94a3b8','#b88a08'][i]};color:${i === 0 ? '#7a5400' : '#fff'}`"
                                    >{{ i + 1 }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13px] font-bold text-on-surface truncate">{{ emp.name }}</p>
                                    <p class="text-[10px] text-on-surface-variant/70 truncate">{{ emp.department ?? '—' }} · {{ emp.count }} payments</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-[14px] font-black font-mono text-on-surface tabular-nums">{{ formatCurrency(emp.total) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Currency split -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Currency Mix</h3>
                        <p class="text-[11px] text-on-surface-variant mb-4">Paid volume per currency</p>

                        <div v-if="(A.currencySplit ?? []).length === 0" class="py-6 text-center text-[12px] text-on-surface-variant/60 italic">
                            No paid records.
                        </div>

                        <div v-else class="space-y-3">
                            <div v-for="ccy in A.currencySplit" :key="ccy.label" class="space-y-1.5">
                                <div class="flex items-center justify-between text-[12px]">
                                    <span class="font-bold text-on-surface">{{ ccy.label }}</span>
                                    <span class="font-mono text-on-surface-variant">{{ formatCurrency(ccy.value, ccy.label) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-surface-container-low overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :style="`width:${(ccy.value / currencyMax) * 100}%;background:linear-gradient(90deg,#0d1452,#1a237e);transition-duration:0.8s`"
                                    ></div>
                                </div>
                                <p class="text-[10px] text-on-surface-variant/70">{{ ccy.count }} payments</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats — disciplined palette. Total Volume gets the gold token
                     (the 5% institutional accent — money/volume is what payroll exists to track). -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard :value="stats.total" label="Total Records" icon="receipt_long" color="navy" />
                    <StatCard :value="stats.pending" label="Pending" icon="schedule" color="amber" />
                    <StatCard :value="stats.paid" label="Paid" icon="check_circle" color="green" />
                    <StatCard
                        :value="formatCurrency(stats.totalGhs)"
                        label="Total Volume" icon="payments" color="gold"
                    />
                </div>

                <!-- Tabs + month filter -->
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="opt in [
                            { id: 'all',     label: 'All Payments',  icon: 'list_alt' },
                            { id: 'pending', label: 'Pending',       icon: 'schedule' },
                            { id: 'paid',    label: 'Paid',          icon: 'check_circle' },
                        ]"
                        :key="opt.id"
                        @click="switchTab(opt.id)"
                        :class="[
                            'flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-bold transition-all',
                            tab === opt.id
                                ? 'bg-secondary text-white shadow-glow-sm hover:-translate-y-px'
                                : 'border border-outline-variant text-on-surface-variant hover:bg-surface-container hover:border-secondary/30 hover:text-secondary',
                        ]"
                    >
                        <span class="material-symbols-outlined text-[15px]" :style="tab === opt.id ? `font-variation-settings:'FILL' 1` : ''">{{ opt.icon }}</span>
                        {{ opt.label }}
                    </button>

                    <div class="relative ml-auto">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">calendar_month</span>
                        <input aria-label="Month"
                            v-model="localFilters.month"
                            @change="applyFilters"
                            type="month"
                            class="rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <!-- Table -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="data.length === 0" class="p-12">
                        <EmptyState
                            title="No payment records"
                            description="Create a new payment to get started."
                            icon="payments"
                        >
                            <template #action>
                                <button
                                    @click="showCreatePanel = true"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                >
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    New Payment
                                </button>
                            </template>
                        </EmptyState>
                    </div>

                    <div v-else class="max-h-[calc(100vh-440px)] min-h-[280px] overflow-auto">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10">
                                <tr>
                                    <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Employee</th>
                                    <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Description</th>
                                    <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Amount</th>
                                    <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                    <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Paid On</th>
                                    <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/30">
                                <tr
                                    v-for="payment in data"
                                    :key="payment.id"
                                    class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                                    @click="router.get(route('payments.show', payment.id))"
                                >
                                    <td class="px-4 py-3.5">
                                        <p class="text-[13px] font-bold text-on-surface leading-tight truncate max-w-[180px]">{{ payment.employee?.name ?? '—' }}</p>
                                        <p class="mt-0.5 text-[11px] font-mono text-on-surface-variant/60">{{ payment.employee?.employee_no }}</p>
                                    </td>
                                    <td class="px-4 py-3.5 text-[13px] text-on-surface-variant max-w-xs">
                                        <p class="truncate">{{ payment.description }}</p>
                                    </td>
                                    <td class="px-4 py-3.5 text-right">
                                        <span class="text-[14px] font-black text-on-surface font-mono tabular-nums">
                                            {{ formatCurrency(payment.amount, payment.currency) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <StatusBadge :status="payment.status" type="payment" />
                                    </td>
                                    <td class="px-4 py-3.5 text-[12px] text-on-surface-variant tabular-nums">
                                        {{ formatDate(payment.paid_at) }}
                                    </td>
                                    <td class="px-4 py-3.5" @click.stop>
                                        <div class="flex items-center justify-end gap-1">
                                            <Link
                                                :href="route('payments.show', payment.id)"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-secondary/10 hover:text-secondary hover:border-secondary/15 transition-all"
                                                title="View payment"
                                                aria-label="View payment"
                                            >
                                                <span class="material-symbols-outlined text-[17px]">visibility</span>
                                            </Link>
                                            <button
                                                v-if="payment.status === 'pending'"
                                                @click="markPaid(payment.id)"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-green-500/10 hover:text-green-600 hover:border-green-500/15 transition-all"
                                                title="Mark paid"
                                                aria-label="Mark paid"
                                            >
                                                <span class="material-symbols-outlined text-[17px]">check_circle</span>
                                            </button>
                                            <span class="material-symbols-outlined ml-0.5 text-[18px] text-on-surface-variant/30 opacity-0 -translate-x-1 transition-all duration-200 group-hover:opacity-100 group-hover:translate-x-0 group-hover:text-secondary/70" aria-hidden="true">chevron_right</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="payments?.links?.length > 3" class="border-t border-outline-variant/50 bg-surface-container-low/40 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <p class="flex items-center gap-1.5 text-[12px] text-on-surface-variant">
                                <span class="material-symbols-outlined text-[15px]" style="color:#1a237e;opacity:0.7">format_list_numbered</span>
                                Showing
                                <span class="font-bold text-on-surface tabular-nums">{{ payments.meta?.from }}</span>
                                –
                                <span class="font-bold text-on-surface tabular-nums">{{ payments.meta?.to }}</span>
                                of
                                <span class="font-bold text-on-surface tabular-nums">{{ payments.meta?.total }}</span>
                            </p>
                            <Pagination :links="payments.links" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create payment -->
            <SlidePanel :open="showCreatePanel" title="New Payment Record" size="lg" @close="showCreatePanel = false">
                <form @submit.prevent="submit" class="space-y-5 p-6">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee <span class="text-red-500">*</span></label>
                        <select aria-label="Employee"
                            v-model="form.employee_id"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.employee_id }"
                        >
                            <option value="" disabled>Select employee</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.name }} · {{ emp.employee_no }}
                            </option>
                        </select>
                        <p v-if="form.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.employee_id }}</p>
                    </div>

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description <span class="text-red-500">*</span></label>
                        <input aria-label="Description"
                            v-model="form.description"
                            type="text"
                            placeholder="e.g. Monthly Salary - May 2026"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.description }"
                        />
                        <p v-if="form.errors.description" class="mt-1 text-[11px] text-red-500">{{ form.errors.description }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Amount <span class="text-red-500">*</span></label>
                            <input aria-label="Amount"
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all font-mono"
                                :class="{ 'border-red-400': form.errors.amount }"
                            />
                            <p v-if="form.errors.amount" class="mt-1 text-[11px] text-red-500">{{ form.errors.amount }}</p>
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Currency</label>
                            <select aria-label="Currency"
                                v-model="form.currency"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            >
                                <option value="GHS">GHS (Ghana Cedi)</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="GBP">GBP</option>
                            </select>
                        </div>
                    </div>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="showCreatePanel = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submit"
                            :disabled="form.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            <span>Create Payment</span>
                        </button>
                    </div>
                </template>
            </SlidePanel>

            <!-- ── Ghana payslip generator ───────────────────────────────────── -->
            <SlidePanel :open="showPayslipPanel" title="Generate Ghana Payslip" size="xl" @close="showPayslipPanel = false">

                <div class="grid lg:grid-cols-2 gap-0 h-full">

                    <!-- LEFT: input form -->
                    <div class="p-6 lg:border-r border-outline-variant/40 overflow-y-auto canvas-scroll">
                        <!-- Employee + period -->
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="text-[11px] font-bold text-on-surface-variant mb-1.5 block uppercase tracking-wider">Employee <span class="text-red-500">*</span></label>
                                <select aria-label="Employee"
                                    v-model="payslipForm.employee_id"
                                    required
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10"
                                    :class="{ 'border-red-400': payslipForm.errors.employee_id }"
                                >
                                    <option value="" disabled>Select employee</option>
                                    <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }} · {{ emp.employee_no }}</option>
                                </select>
                                <p v-if="payslipForm.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ payslipForm.errors.employee_id }}</p>
                            </div>
                            <div>
                                <label class="text-[11px] font-bold text-on-surface-variant mb-1.5 block uppercase tracking-wider">Pay Period <span class="text-red-500">*</span></label>
                                <input aria-label="Pay Period"
                                    v-model="payslipForm.period"
                                    type="month"
                                    required
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10"
                                />
                            </div>
                        </div>

                        <!-- Basic salary -->
                        <div class="mb-5">
                            <label class="text-[11px] font-bold text-on-surface-variant mb-1.5 block uppercase tracking-wider">Basic Monthly Salary (GHS) <span class="text-red-500">*</span></label>
                            <input aria-label="Basic Monthly Salary (GHS)"
                                v-model="payslipForm.basic"
                                type="number"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] font-mono text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10"
                                :class="{ 'border-red-400': payslipForm.errors.basic }"
                            />
                            <p v-if="payslipForm.errors.basic" class="mt-1 text-[11px] text-red-500">{{ payslipForm.errors.basic }}</p>
                        </div>

                        <!-- Allowances -->
                        <div class="mb-5">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Cash Allowances</label>
                                <button
                                    type="button"
                                    @click="addAllowance"
                                    class="text-[11px] font-bold text-secondary hover:underline flex items-center gap-1"
                                >
                                    <span class="material-symbols-outlined text-[14px]">add</span>
                                    Add line
                                </button>
                            </div>
                            <p class="text-[10px] text-on-surface-variant/60 mb-2 italic">All cash allowances are fully taxable under Ghana income tax (Act 896).</p>

                            <div v-if="payslipForm.allowances.length === 0" class="rounded-lg border border-dashed border-outline-variant/60 p-3 text-center text-[11px] text-on-surface-variant/60">
                                No allowances added.
                            </div>

                            <div v-for="(row, i) in payslipForm.allowances" :key="`a-${i}`" class="flex gap-2 mb-2">
                                <input aria-label="Label"
                                    v-model="row.label"
                                    type="text"
                                    placeholder="e.g. Fuel Allowance"
                                    class="flex-1 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50"
                                />
                                <input aria-label="Amount"
                                    v-model="row.amount"
                                    type="number" step="0.01" min="0"
                                    placeholder="0.00"
                                    class="w-28 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-mono text-on-surface focus:outline-none focus:border-secondary/50"
                                />
                                <button
                                    type="button"
                                    @click="removeAllowance(i)"
                                    class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-red-500/10 hover:text-red-600 transition-colors"
                                    title="Remove"
                                >
                                    <span class="material-symbols-outlined text-[16px]">close</span>
                                </button>
                            </div>
                        </div>

                        <!-- Tier 3 (voluntary, tax-deductible) -->
                        <div class="mb-5 rounded-xl border border-blue-200/60 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20 p-3">
                            <label class="text-[11px] font-bold text-blue-800 dark:text-blue-300 mb-1.5 block uppercase tracking-wider flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">savings</span>
                                SSNIT Tier 3 (voluntary, tax-deductible)
                            </label>
                            <input aria-label="savings SSNIT Tier 3 (voluntary, tax-deductible)"
                                v-model="payslipForm.tier3_employee"
                                type="number" step="0.01" min="0"
                                placeholder="0.00"
                                class="w-full rounded-lg border border-blue-300 dark:border-blue-800/60 bg-white/60 dark:bg-black/20 px-3 py-2 text-[13px] font-mono text-on-surface focus:outline-none focus:border-blue-500/60"
                            />
                            <p class="mt-1.5 text-[10px] text-blue-700 dark:text-blue-400">
                                Deductible up to 16.5% of basic salary (cap: GHS {{ payslipPreview.tier3Cap.toFixed(2) }})
                            </p>
                        </div>

                        <!-- Voluntary deductions -->
                        <div class="mb-5">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Other Deductions</label>
                                <button
                                    type="button"
                                    @click="addDeduction"
                                    class="text-[11px] font-bold text-secondary hover:underline flex items-center gap-1"
                                >
                                    <span class="material-symbols-outlined text-[14px]">add</span>
                                    Add line
                                </button>
                            </div>
                            <p class="text-[10px] text-on-surface-variant/60 mb-2 italic">Loan repayments, union dues, welfare, salary advances, etc.</p>

                            <div v-if="payslipForm.voluntary_deductions.length === 0" class="rounded-lg border border-dashed border-outline-variant/60 p-3 text-center text-[11px] text-on-surface-variant/60">
                                No voluntary deductions added.
                            </div>

                            <div v-for="(row, i) in payslipForm.voluntary_deductions" :key="`d-${i}`" class="flex gap-2 mb-2">
                                <input aria-label="Label"
                                    v-model="row.label"
                                    type="text"
                                    placeholder="e.g. Loan repayment"
                                    class="flex-1 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50"
                                />
                                <input aria-label="Amount"
                                    v-model="row.amount"
                                    type="number" step="0.01" min="0"
                                    placeholder="0.00"
                                    class="w-28 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-mono text-on-surface focus:outline-none focus:border-secondary/50"
                                />
                                <button
                                    type="button"
                                    @click="removeDeduction(i)"
                                    class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-red-500/10 hover:text-red-600 transition-colors"
                                    title="Remove"
                                >
                                    <span class="material-symbols-outlined text-[16px]">close</span>
                                </button>
                            </div>
                        </div>

                        <!-- Mark paid checkbox -->
                        <label class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 cursor-pointer">
                            <input v-model="payslipForm.mark_paid" aria-label="Mark as paid immediately" type="checkbox" class="h-4 w-4 accent-secondary" />
                            <span class="text-[12px] font-semibold text-on-surface">Mark as paid immediately</span>
                        </label>
                    </div>

                    <!-- RIGHT: live payslip preview -->
                    <div class="p-6 overflow-y-auto canvas-scroll" style="background:linear-gradient(180deg,rgba(26, 35, 126,0.04),transparent)">
                        <div class="inline-flex items-center gap-1.5 rounded-full bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-900/40 px-2.5 py-0.5 mb-3">
                            <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                            <span class="text-[9px] font-black uppercase tracking-widest text-green-700 dark:text-green-400">Live preview · GRA 2025 rates</span>
                        </div>

                        <h3 class="text-[15px] font-bold text-on-surface mb-1">Payslip Preview</h3>
                        <p class="text-[11px] text-on-surface-variant mb-4">{{ payslipForm.period }}</p>

                        <!-- Earnings -->
                        <div class="rounded-xl bg-surface-container-lowest border border-outline-variant/50 p-4 mb-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] text-green-700 dark:text-green-400 mb-2">Earnings</p>
                            <div class="space-y-1.5 text-[12px]">
                                <div class="flex justify-between">
                                    <span class="text-on-surface">Basic Salary</span>
                                    <span class="font-mono text-on-surface">{{ formatCurrency(payslipPreview.basic) }}</span>
                                </div>
                                <div v-for="(a, i) in payslipPreview.allowances" :key="`pa-${i}`" v-show="a.amount > 0" class="flex justify-between">
                                    <span class="text-on-surface-variant">{{ a.label || '—' }}</span>
                                    <span class="font-mono text-on-surface">{{ formatCurrency(a.amount) }}</span>
                                </div>
                                <div class="flex justify-between border-t border-outline-variant/40 pt-1.5 mt-2 font-bold">
                                    <span>Gross Earnings</span>
                                    <span class="font-mono text-green-700 dark:text-green-400">{{ formatCurrency(payslipPreview.gross) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Statutory deductions -->
                        <div class="rounded-xl bg-surface-container-lowest border border-outline-variant/50 p-4 mb-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] text-red-700 dark:text-red-400 mb-2">Statutory Deductions</p>
                            <div class="space-y-1.5 text-[12px]">
                                <div class="flex justify-between">
                                    <span class="text-on-surface">SSNIT Tier 1 (5.5%)</span>
                                    <span class="font-mono text-red-700 dark:text-red-400">âˆ’{{ formatCurrency(payslipPreview.ssnitEmp) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-on-surface">PAYE (Income Tax)</span>
                                    <span class="font-mono text-red-700 dark:text-red-400">âˆ’{{ formatCurrency(payslipPreview.paye.total) }}</span>
                                </div>
                            </div>

                            <!-- PAYE band breakdown -->
                            <details v-if="payslipPreview.paye.bands.length" class="mt-3 group">
                                <summary class="text-[10px] font-bold text-secondary cursor-pointer flex items-center gap-1 hover:underline list-none">
                                    <span class="material-symbols-outlined text-[14px] transition-transform group-open:rotate-90">chevron_right</span>
                                    PAYE band breakdown
                                </summary>
                                <div class="mt-2 space-y-1 pl-4 border-l-2 border-secondary/30">
                                    <div v-for="(b, i) in payslipPreview.paye.bands" :key="`bd-${i}`" class="flex justify-between text-[10px]">
                                        <span class="text-on-surface-variant">
                                            {{ b.upper === null ? 'Above ' + b.lower.toFixed(2) : b.lower.toFixed(2) + '—' + b.upper.toFixed(2) }}
                                            <span class="font-bold text-on-surface">@ {{ (b.rate * 100).toFixed(1) }}%</span>
                                        </span>
                                        <span class="font-mono text-on-surface-variant">GHS {{ b.tax.toFixed(2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-[10px] font-bold pt-1 border-t border-outline-variant/30">
                                        <span class="text-on-surface">Chargeable income</span>
                                        <span class="font-mono">GHS {{ payslipPreview.chargeable.toFixed(2) }}</span>
                                    </div>
                                </div>
                            </details>
                        </div>

                        <!-- Voluntary -->
                        <div v-if="payslipPreview.voluntaryTotal > 0 || payslipPreview.tier3 > 0" class="rounded-xl bg-surface-container-lowest border border-outline-variant/50 p-4 mb-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] text-amber-700 dark:text-amber-400 mb-2">Voluntary Deductions</p>
                            <div class="space-y-1.5 text-[12px]">
                                <div v-if="payslipPreview.tier3 > 0" class="flex justify-between">
                                    <span class="text-on-surface">SSNIT Tier 3</span>
                                    <span class="font-mono text-amber-700 dark:text-amber-400">âˆ’{{ formatCurrency(payslipPreview.tier3) }}</span>
                                </div>
                                <div v-for="(d, i) in payslipPreview.voluntary" :key="`pv-${i}`" v-show="d.amount > 0" class="flex justify-between">
                                    <span class="text-on-surface-variant">{{ d.label || '—' }}</span>
                                    <span class="font-mono text-amber-700 dark:text-amber-400">âˆ’{{ formatCurrency(d.amount) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Net pay — single 5% gold accent moment on the payslip preview -->
                        <div class="relative rounded-2xl p-5 text-white shadow-glow overflow-hidden"
                             style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            <div class="pointer-events-none absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.6),transparent)"></div>
                            <div class="pointer-events-none absolute -top-8 -right-8 h-32 w-32 rounded-full" style="background:radial-gradient(circle,rgba(255,215,0,0.10),transparent 70%)"></div>
                            <p class="relative text-[10px] font-black uppercase tracking-[0.18em] opacity-80">Net Pay</p>
                            <p class="relative mt-1 text-[28px] font-black font-mono leading-none tabular-nums">{{ formatCurrency(payslipPreview.netPay) }}</p>
                            <p class="relative mt-2 text-[10px] opacity-70">Gross − SSNIT − PAYE − voluntary</p>
                        </div>

                        <!-- Employer cost -->
                        <div class="mt-3 rounded-xl border border-outline-variant/40 bg-surface-container-low p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] text-on-surface-variant/70 mb-2">Employer Cost</p>
                            <div class="space-y-1 text-[11px]">
                                <div class="flex justify-between">
                                    <span class="text-on-surface-variant">Gross paid</span>
                                    <span class="font-mono">{{ formatCurrency(payslipPreview.gross) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-on-surface-variant">SSNIT Tier 1 (13%)</span>
                                    <span class="font-mono">{{ formatCurrency(payslipPreview.ssnitEr1) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-on-surface-variant">SSNIT Tier 2 (5%)</span>
                                    <span class="font-mono">{{ formatCurrency(payslipPreview.ssnitEr2) }}</span>
                                </div>
                                <div class="flex justify-between border-t border-outline-variant/40 pt-1 mt-1 font-bold">
                                    <span>Total employer cost</span>
                                    <span class="font-mono">{{ formatCurrency(payslipPreview.employerCost) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="showPayslipPanel = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitPayslip"
                            :disabled="payslipForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white shadow-glow-sm disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="payslipForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            <span v-else class="material-symbols-outlined text-[16px]">receipt_long</span>
                            Generate Payslip
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>

<style scoped>
.canvas-scroll::-webkit-scrollbar { width: 8px; }
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
</style>
