<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    cashPosition:        { type: Number, default: 0 },
    bankAccounts:        { type: Array,  default: () => [] },
    nextPayroll:         { type: [Object, null], default: null },
    outstandingLoans:    { type: Object, default: () => ({ count: 0, total_balance: 0 }) },
    pendingApprovals:    { type: Object, default: () => ({ payroll_runs: 0, loans: 0 }) },
    statutoryCompliance: { type: Array,  default: () => [] },
});

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
});

const cediShort = (v) => {
    const n = Number(v) || 0;
    if (n >= 1_000_000) return 'GHS ' + (n / 1_000_000).toFixed(2) + 'M';
    if (n >= 1_000)     return 'GHS ' + (n / 1_000).toFixed(1) + 'k';
    return 'GHS ' + n.toFixed(2);
};

const totalPendingCount = computed(() => props.pendingApprovals.payroll_runs + props.pendingApprovals.loans);

const statusBadge = (status) => {
    const s = (status || '').toLowerCase();
    if (s.includes('filed') || s === 'submitted' || s === 'accepted') {
        return 'text-green-600 bg-green-50 border-green-100';
    }
    if (s === 'pending' || s === 'draft') {
        return 'text-amber-600 bg-amber-50 border-amber-100';
    }
    return 'text-blue-600 bg-blue-50 border-blue-100';
};
</script>

<template>
    <Head title="Finance Hub" />

    <div class="space-y-8 animate-reveal-up">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary"
                          style="font-variation-settings:'FILL' 1">account_balance</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE HUB</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Treasury &amp; Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Real-time view of the institute's cash position, payroll cycle, statutory compliance and pending approvals.
                </p>
            </div>
            <div class="flex gap-2">
                <Link :href="route('finance.accounts.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">account_tree</span>
                    Chart of Accounts
                </Link>
                <Link :href="route('finance.bank-accounts.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">account_balance_wallet</span>
                    Bank Accounts
                </Link>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Cash Position</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ cediShort(cashPosition) }}</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">Across {{ bankAccounts.length }} active accounts</p>
            </div>

            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Outstanding Loans</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ cediShort(outstandingLoans.total_balance) }}</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">{{ outstandingLoans.count }} active loans</p>
            </div>

            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Pending Approvals</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ totalPendingCount }}</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">
                    {{ pendingApprovals.payroll_runs }} payroll · {{ pendingApprovals.loans }} loans
                </p>
            </div>

            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Next Payroll Run</p>
                <p v-if="nextPayroll" class="mt-2 text-2xl font-black text-primary">
                    {{ cediShort(nextPayroll.projected_net) }}
                </p>
                <p v-else class="mt-2 text-2xl font-black text-primary">—</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">
                    <span v-if="nextPayroll">{{ nextPayroll.reference }} · {{ nextPayroll.participant_count }} staff</span>
                    <span v-else>No upcoming run</span>
                </p>
            </div>
        </div>

        <!-- Two-column body -->
        <div class="grid gap-6 lg:grid-cols-12">
            <!-- Left: Bank Accounts + Compliance -->
            <div class="lg:col-span-7 space-y-6">
                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Organisational Bank Accounts</h4>
                    <div v-if="bankAccounts.length" class="space-y-2.5">
                        <div v-for="b in bankAccounts" :key="b.id"
                             class="flex items-center justify-between rounded-xl border border-outline-variant/50 p-3">
                            <div>
                                <p class="text-[12px] font-bold text-primary">{{ b.bank_name }} · {{ b.account_name }}</p>
                                <p class="text-[10px] font-medium text-on-surface-variant">{{ b.purpose }} · GL {{ b.gl_code }}</p>
                            </div>
                            <p class="text-[13px] font-black text-primary">{{ cedi(b.opening_balance) }}</p>
                        </div>
                    </div>
                    <p v-else class="text-[12px] text-on-surface-variant">No active bank accounts.</p>
                </section>

                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Statutory Compliance</h4>
                    <div v-if="statutoryCompliance.length" class="space-y-3">
                        <div v-for="s in statutoryCompliance" :key="s.kind"
                             class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5 flex-1 min-w-0 mr-3">
                                <span class="material-symbols-outlined text-[16px] flex-shrink-0">verified</span>
                                <p class="text-[11.5px] font-bold text-on-surface-variant truncate">
                                    {{ s.kind }} · {{ s.period_end || '—' }}
                                </p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border flex-shrink-0"
                                  :class="statusBadge(s.status)">{{ s.status }}</span>
                        </div>
                    </div>
                    <p v-else class="text-[12px] text-on-surface-variant">No statutory returns recorded yet.</p>
                </section>
            </div>

            <!-- Right: Next Payroll + Outstanding Loans -->
            <div class="lg:col-span-5 space-y-6">
                <section v-if="nextPayroll" class="rounded-2xl p-6 text-white relative overflow-hidden"
                         style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
                    <div class="absolute -right-4 -top-4 opacity-10">
                        <span class="material-symbols-outlined text-9xl">payments</span>
                    </div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-2"
                       style="color:rgba(255,255,255,0.35)">Next Payroll Run</p>
                    <p class="text-3xl font-black mb-1">{{ cedi(nextPayroll.projected_net) }}</p>
                    <p class="text-[10px] mb-5" style="color:rgba(255,255,255,0.45)">
                        {{ nextPayroll.reference }} · {{ nextPayroll.period_start }} → {{ nextPayroll.period_end }}
                    </p>
                    <p class="text-[11px]" style="color:rgba(255,255,255,0.65)">
                        {{ nextPayroll.participant_count }} staff · status {{ nextPayroll.status }}
                    </p>
                </section>

                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Outstanding Loans</h4>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-medium text-on-surface-variant">Total balance</p>
                            <p class="text-2xl font-black text-primary">{{ cedi(outstandingLoans.total_balance) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-medium text-on-surface-variant">Active loans</p>
                            <p class="text-2xl font-black text-primary">{{ outstandingLoans.count }}</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
