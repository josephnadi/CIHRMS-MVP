<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabBar from '@/Components/TabBar.vue';
import GlossaryText from '@/Components/GlossaryText.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    loan:         Object, // LoanAccountResource (possibly wrapped in { data: … })
    repayments:   Object, // LoanRepaymentResource collection
    activeModule: String,
});

// ── Unwrap resource wrappers ──────────────────────────────────────────────────
const L = computed(() => props.loan?.data ?? props.loan ?? {});
const repayList = computed(() => props.repayments?.data ?? props.repayments ?? []);

// ── Helpers ───────────────────────────────────────────────────────────────────
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct  = (v) => `${(Number(v || 0) * 100).toFixed(1)}%`;

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatPeriod = (p) => {
    if (!p) return '—';
    const [yr, mo] = String(p).split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[parseInt(mo, 10) - 1]} ${yr}`;
};

const currentPeriod = (() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
})();

// ── Tabs ──────────────────────────────────────────────────────────────────────
const activeTab = ref('schedule');
const tabs = [
    { label: 'Schedule',    value: 'schedule'   },
    { label: 'Repayments',  value: 'repayments' },
    { label: 'Audit',       value: 'audit'      },
];

// ── Stats ─────────────────────────────────────────────────────────────────────
const monthsRemaining = computed(() => {
    const unpaid = repayList.value.filter(r => r.status !== 'paid').length;
    return unpaid;
});

const repayPct = computed(() => {
    if (!L.value.total_repayable || L.value.total_repayable === 0) return 0;
    const paid = L.value.total_repayable - L.value.outstanding_balance;
    return Math.min(100, Math.round((paid / L.value.total_repayable) * 100));
});

// ── Approve / Reject ──────────────────────────────────────────────────────────
const decideForm  = useForm({ decision: 'approve', reason: '' });
const showReject  = ref(false);

const approve = () => {
    decideForm.decision = 'approve'; // reset in case a prior reject left it 'reject'
    decideForm.post(route('loans.decide', L.value.id), { preserveScroll: true });
};
const reject  = () => {
    decideForm.decision = 'reject';
    decideForm.post(route('loans.decide', L.value.id), {
        preserveScroll: true,
        onSuccess: () => { showReject.value = false; decideForm.reset('reason'); },
    });
};

// ── Disburse ──────────────────────────────────────────────────────────────────
const disburseForm = useForm({ first_repayment_period: '' });
const disburse     = () => disburseForm.post(route('loans.disburse', L.value.id), { preserveScroll: true });

// ── Repayment status row styling ──────────────────────────────────────────────
const rowClass = (r) => {
    if (r.status === 'paid')        return 'bg-emerald-50/30 dark:bg-emerald-950/10';
    if (r.due_period === currentPeriod) return 'bg-blue-50/40 dark:bg-blue-950/15';
    return 'hover:bg-surface-container/40';
};
</script>

<template>
    <Head :title="`Loan ${L.reference ?? ''}`" />
    <div data-page-root="true">
            <!-- ── Header ─────────────────────────────────────────────────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('loans.index')"
                            aria-label="Back to loans"
                            class="flex h-9 w-9 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                        </Link>
                        <div>
                            <h2 class="text-[1.5rem] font-black tracking-tight text-on-surface leading-tight">Loan Detail</h2>
                            <p class="mt-0.5 text-[13px] text-on-surface-variant">
                                <span class="font-mono">{{ L.reference }}</span>
                                <span class="mx-1.5 text-on-surface-variant/40">·</span>
                                {{ L.employee?.name ?? '—' }}
                            </p>
                        </div>
                    </div>
                    <StatusBadge :status="L.status" :label="L.status_label" />
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- ── Hero card ───────────────────────────────────────────────────── -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <div class="flex flex-wrap items-start gap-6">

                        <!-- Loan identity -->
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-3 mb-2">
                                <div class="h-11 w-11 rounded-2xl bg-secondary/10 flex items-center justify-center flex-shrink-0">
                                    <span class="material-symbols-outlined text-[22px] text-secondary">account_balance</span>
                                </div>
                                <div>
                                    <p class="font-mono text-[18px] font-black text-on-surface tracking-tight">{{ L.reference }}</p>
                                    <p class="text-[13px] text-on-surface-variant">
                                        {{ L.product?.data?.name ?? L.product?.name ?? '—' }}
                                        <span v-if="L.booked_interest_rate" class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black tracking-wider"
                                              style="background:rgba(26, 35, 126,0.1);color:#1a237e">
                                            {{ (L.booked_interest_rate * 100).toFixed(1) }}% p.a.
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary/10 px-3 py-1 text-[12px] font-semibold text-secondary">
                                    <span class="material-symbols-outlined text-[14px]">person</span>
                                    {{ L.employee?.name ?? '—' }}
                                </span>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-container-low px-3 py-1 text-[12px] font-semibold text-on-surface-variant border border-outline-variant/60">
                                    <span class="material-symbols-outlined text-[14px]">badge</span>
                                    {{ L.employee?.employee_no ?? '—' }}
                                </span>
                            </div>
                        </div>

                        <!-- Quick amounts -->
                        <div class="flex items-center gap-6 flex-shrink-0">
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Principal</p>
                                <p class="font-mono text-[16px] font-black text-on-surface tabular-nums">{{ cedi(L.principal) }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/50"></div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Monthly EMI</p>
                                <p class="font-mono text-[16px] font-black text-on-surface tabular-nums">{{ cedi(L.monthly_installment) }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/50"></div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Outstanding</p>
                                <p class="font-mono text-[16px] font-black text-on-surface tabular-nums">{{ cedi(L.outstanding_balance) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Repayment progress (when disbursed) -->
                    <div v-if="['disbursed','repaying'].includes(L.status)" class="mt-5 space-y-1.5">
                        <div class="flex items-center justify-between text-[12px]">
                            <span class="font-semibold text-on-surface-variant">Repayment progress</span>
                            <span class="font-black text-on-surface">{{ repayPct }}% of {{ cedi(L.total_repayable) }}</span>
                        </div>
                        <div class="h-2.5 w-full rounded-full bg-surface-container overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all"
                                style="background:linear-gradient(90deg,#0d1452,#1a237e)"
                                :style="`width:${repayPct}%`"
                            ></div>
                        </div>
                        <p class="text-[11px] text-on-surface-variant">{{ L.installments_paid }} installments paid · {{ monthsRemaining }} remaining</p>
                    </div>
                </div>

                <!-- ── 4 Stat cards ────────────────────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(26, 35, 126,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Principal</p>
                        <p class="font-mono text-[18px] font-black text-on-surface tabular-nums">{{ cedi(L.principal) }}</p>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(5,150,105,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Monthly EMI</p>
                        <p class="font-mono text-[18px] font-black text-on-surface tabular-nums">{{ cedi(L.monthly_installment) }}</p>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(217,119,6,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Outstanding</p>
                        <p class="font-mono text-[18px] font-black text-on-surface tabular-nums">{{ cedi(L.outstanding_balance) }}</p>
                    </div>
                    <!-- Months Remaining — magenta (people-side time accounting), was violet-rgb 124,92,255 (off-palette) -->
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(217,18,227,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Months Remaining</p>
                        <p class="text-[18px] font-black text-on-surface tabular-nums">{{ monthsRemaining }}</p>
                    </div>
                </div>

                <!-- ── HR / Finance action bar ────────────────────────────────────── -->
                <div v-if="L.can?.approve || L.can?.disburse" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-5 shadow-card space-y-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70"><GlossaryText text="HR / Finance Actions" /></p>

                    <div class="flex flex-wrap gap-3">
                        <!-- Approve -->
                        <template v-if="L.status === 'pending_approval' && L.can?.approve">
                            <button
                                @click="approve"
                                :disabled="decideForm.processing"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm disabled:opacity-60"
                                style="background:linear-gradient(135deg,#059669,#34d399)"
                            >
                                <span class="material-symbols-outlined text-[17px]">check_circle</span>
                                Approve Loan
                                <span class="text-[10px] opacity-75 font-normal">(2FA required)</span>
                            </button>
                            <button
                                @click="showReject = !showReject"
                                class="flex items-center gap-2 rounded-xl border border-red-300/60 bg-red-50 dark:bg-red-950/20 px-4 py-2.5 text-[13px] font-bold text-red-600 hover:bg-red-100 transition-colors"
                            >
                                <span class="material-symbols-outlined text-[17px]">cancel</span>
                                Reject
                            </button>
                        </template>

                        <!-- Disburse -->
                        <button
                            v-if="L.status === 'approved' && L.can?.disburse"
                            @click="disburse"
                            :disabled="disburseForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[17px]">payments</span>
                            Disburse Loan
                            <span class="text-[10px] opacity-75 font-normal">(2FA required)</span>
                        </button>
                    </div>

                    <!-- Reject inline form -->
                    <div v-if="showReject" class="rounded-xl border border-red-300/50 bg-red-50/30 dark:bg-red-950/20 p-4 space-y-3">
                        <p class="text-[12px] font-bold text-red-700">Rejection reason <span class="text-red-500">*</span></p>
                        <textarea aria-label="Reason"
                            v-model="decideForm.reason"
                            rows="3"
                            placeholder="Provide a clear reason for rejection…"
                            class="w-full rounded-xl border border-red-300/60 bg-white dark:bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-red-400 focus:ring-2 focus:ring-red-400/20 transition-all resize-none"
                        ></textarea>
                        <div class="flex items-center gap-3">
                            <button
                                @click="reject"
                                :disabled="!decideForm.reason || decideForm.processing"
                                class="flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-red-700 disabled:opacity-50 transition-colors"
                            >
                                <span v-if="decideForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                Confirm Rejection
                            </button>
                            <button @click="showReject = false" class="text-[13px] font-semibold text-on-surface-variant hover:text-on-surface transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>

                    <!-- First repayment period for disburse (shown when status=approved) -->
                    <div v-if="L.status === 'approved' && L.can?.disburse" class="flex items-center gap-3">
                        <label class="text-[12px] font-semibold text-on-surface-variant whitespace-nowrap">First repayment period</label>
                        <input aria-label="First repayment period"
                            v-model="disburseForm.first_repayment_period"
                            type="month"
                            class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <!-- ── Tabs ──────────────────────────────────────────────────────────── -->
                <TabBar :tabs="tabs" v-model="activeTab" />

                <!-- ── SCHEDULE TAB ─────────────────────────────────────────────────── -->
                <div v-show="activeTab === 'schedule'" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="repayList.length === 0" class="p-12 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">schedule</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">Amortization schedule is generated at disbursement.</p>
                    </div>
                    <div v-else class="overflow-auto max-h-[600px]">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10">
                                <tr>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">#</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Period</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">EMI</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Principal</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Interest</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Balance After</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/40">
                                <tr
                                    v-for="r in repayList"
                                    :key="r.id"
                                    :class="['border-b border-outline-variant/40 transition-colors', rowClass(r)]"
                                >
                                    <td class="px-4 py-2.5 font-mono text-[12px] text-on-surface-variant">{{ r.installment_no }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="text-[13px] font-semibold text-on-surface">{{ formatPeriod(r.due_period) }}</span>
                                        <span v-if="r.due_period === currentPeriod" class="ml-2 inline-flex items-center rounded-full text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5"
                                              style="background:rgba(26, 35, 126,0.15);color:#1a237e">Current</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-[13px] font-bold text-on-surface tabular-nums">{{ cedi(r.scheduled_amount) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono text-[12px] text-on-surface-variant tabular-nums">{{ cedi(r.principal_portion) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono text-[12px] text-on-surface-variant/70 tabular-nums">{{ cedi(r.interest_portion) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono text-[13px] text-on-surface tabular-nums">{{ cedi(r.balance_after) }}</td>
                                    <td class="px-4 py-2.5">
                                        <StatusBadge :status="r.status" :label="r.status_label" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ── REPAYMENTS TAB ──────────────────────────────────────────────── -->
                <div v-show="activeTab === 'repayments'" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="repayList.filter(r => r.paid_amount > 0 || r.status === 'paid').length === 0" class="p-12 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">payments</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No payments recorded yet.</p>
                    </div>
                    <div v-else class="overflow-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">#</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Period</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Scheduled</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Paid</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Posted At</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/40">
                                <tr
                                    v-for="r in repayList"
                                    :key="r.id"
                                    class="border-b border-outline-variant/40 hover:bg-surface-container/40 transition-colors"
                                >
                                    <td class="px-4 py-2.5 font-mono text-[12px] text-on-surface-variant">{{ r.installment_no }}</td>
                                    <td class="px-4 py-2.5 text-[13px] font-semibold text-on-surface">{{ formatPeriod(r.due_period) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono text-[12px] text-on-surface-variant tabular-nums">{{ cedi(r.scheduled_amount) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono text-[13px] font-bold text-on-surface tabular-nums">{{ cedi(r.paid_amount) }}</td>
                                    <td class="px-4 py-2.5 text-[12px] text-on-surface-variant">{{ formatDate(r.posted_at) }}</td>
                                    <td class="px-4 py-2.5">
                                        <StatusBadge :status="r.status" :label="r.status_label" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ── AUDIT TAB ────────────────────────────────────────────────────── -->
                <div v-show="activeTab === 'audit'" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <!-- Metadata card -->
                    <div class="grid sm:grid-cols-2 gap-5">
                        <div class="space-y-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Application</p>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Applied by</span>
                                <span class="font-semibold text-on-surface">{{ L.applicant?.name ?? '—' }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Applied at</span>
                                <span class="font-semibold text-on-surface">{{ formatDate(L.applied_at) }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Purpose</span>
                                <span class="font-semibold text-on-surface text-right max-w-[200px]">{{ L.purpose || '—' }}</span>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Approval / Disbursement</p>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Approved by</span>
                                <span class="font-semibold text-on-surface">{{ L.approver?.name ?? '—' }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Approved at</span>
                                <span class="font-semibold text-on-surface">{{ formatDate(L.approved_at) }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Disbursed at</span>
                                <span class="font-semibold text-on-surface">{{ formatDate(L.disbursed_at) }}</span>
                            </div>
                        </div>
                        <div v-if="L.rejection_reason" class="sm:col-span-2 rounded-xl bg-red-50/50 dark:bg-red-950/20 border border-red-300/50 p-4">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-red-600 mb-1">Rejection Reason</p>
                            <p class="text-[13px] text-red-700">{{ L.rejection_reason }}</p>
                        </div>
                    </div>

                    <!-- Loan parameters -->
                    <div class="mt-6 pt-5 border-t border-outline-variant/50 grid sm:grid-cols-4 gap-4">
                        <div class="rounded-xl bg-surface-container-low p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Interest Rate</p>
                            <p class="font-mono font-black text-on-surface">{{ pct(L.booked_interest_rate) }}</p>
                            <p class="text-[10px] text-on-surface-variant/50">annual</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-low p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Term</p>
                            <p class="font-black text-on-surface">{{ L.term_months }} months</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-low p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Total Interest</p>
                            <p class="font-mono font-black text-on-surface tabular-nums">{{ cedi(L.total_interest) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-low p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Total Repayable</p>
                            <p class="font-mono font-black text-on-surface tabular-nums">{{ cedi(L.total_repayable) }}</p>
                        </div>
                    </div>
                </div>

            </div>
    </div>
</template>
