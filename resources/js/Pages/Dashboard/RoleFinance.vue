<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Finance Officer overview — real money posture, driven by live data
 * from DashboardService::getFinanceSnapshot(). Visual language follows
 * "Sovereign Precision": obsidian + cobalt + occasional gold accent.
 *
 * The hero treats the dashboard like a treasury bulletin — month-stamped,
 * roman-numeralled, with a typographic hierarchy that says "this is a
 * serious instrument of state, not a startup admin panel".
 */
const props = defineProps({
    user:     { type: Object, required: true },
    snapshot: { type: Object, default: () => null },
    stats:    { type: Object, default: () => ({}) },
});

const ghs = (n) => 'GHS ' + Number(n ?? 0).toLocaleString('en-GH', { maximumFractionDigits: 2 });
const compact = (n) => {
    const v = Number(n ?? 0);
    if (v >= 1_000_000) return (v / 1_000_000).toFixed(2) + 'M';
    if (v >= 1_000)     return (v / 1_000).toFixed(1) + 'K';
    return v.toFixed(0);
};

const today = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));

const payroll = computed(() => props.snapshot?.payroll ?? { draft: { count: 0, net: 0 }, calculated: { count: 0, net: 0 }, approved: { count: 0, net: 0 }, paid_ytd: { count: 0, net: 0 } });
const disburse = computed(() => props.snapshot?.disbursement ?? { pending: { count: 0, amount: 0 }, sent: { count: 0, amount: 0 }, settled: { count: 0, amount: 0 }, failed: { count: 0, amount: 0 } });
const payments = computed(() => props.snapshot?.payments ?? { pending_count: 0, pending_amount: 0, paid_30d: 0 });
const statutory = computed(() => props.snapshot?.statutory ?? { generated: 0, submitted: 0, overdue: 0 });
const recentRuns = computed(() => props.snapshot?.recent_runs ?? []);

// Treasury posture — turn the disbursement counts into a single colour-coded score.
const treasuryPosture = computed(() => {
    const failed = disburse.value.failed.count;
    const pending = disburse.value.pending.count;
    if (failed > 5)            return { label: 'Attention',  tone: 'rose',  note: `${failed} disbursements failed — needs review` };
    if (pending > 20)          return { label: 'Backed up',  tone: 'amber', note: `${pending} pending instructions to dispatch` };
    if (statutory.value.overdue > 0) return { label: 'Compliance risk', tone: 'amber', note: `${statutory.value.overdue} statutory returns overdue` };
    return { label: 'On track', tone: 'emerald', note: 'No critical signals' };
});

const postureTone = (t) => ({
    rose:    'border-rose-200 bg-rose-50 text-rose-900',
    amber:   'border-amber-200 bg-amber-50 text-amber-900',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-900',
}[t] ?? 'border-slate-200 bg-slate-50 text-slate-900');

const statusTone = (s) => ({
    draft:        'bg-slate-100 text-slate-700',
    calculating:  'bg-amber-50 text-amber-800',
    calculated:   'bg-amber-50 text-amber-900',
    approved:     'bg-cobalt-50 text-cobalt-800',
    paid:         'bg-emerald-50 text-emerald-800',
    reversed:     'bg-rose-50 text-rose-800',
}[s] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <div class="space-y-8 animate-reveal-up">
        <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
        <!-- Treasury bulletin header — broadsheet-style, role-grounded        -->
        <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
        <header class="relative overflow-hidden rounded-3xl border border-outline-variant/60 p-8"
                style="background:linear-gradient(135deg,#0a1138 0%,#1a237e 55%,#283593 100%);">
            <!-- Decorative gold rule -->
            <div class="absolute inset-x-0 top-0 h-[2px]" style="background:linear-gradient(90deg,transparent 0%,#ffd700 50%,transparent 100%);"></div>

            <!-- Engraved ornament -->
            <div class="pointer-events-none absolute -right-12 -top-12 opacity-[0.08]">
                <span class="material-symbols-outlined text-white" style="font-size:240px;font-variation-settings:'FILL' 1">account_balance</span>
            </div>

            <div class="relative flex flex-wrap items-end justify-between gap-6">
                <div class="text-white">
                    <p class="text-[10px] font-black uppercase tracking-[0.28em] text-amber-200/90">
                        Treasury Bulletin · {{ today }}
                    </p>
                    <h1 class="mt-2 text-[2.4rem] font-black leading-none tracking-tight">
                        Good morning, {{ user.name?.split(' ')[0] ?? 'Officer' }}.
                    </h1>
                    <p class="mt-3 max-w-xl text-[13px] font-medium text-white/70 leading-relaxed">
                        Statutory payroll, disbursement, and approvals queue — your daily ledger.
                    </p>
                </div>

                <!-- Posture pill -->
                <div class="rounded-2xl border px-5 py-4 text-[12px] font-bold shadow-sm" :class="postureTone(treasuryPosture.tone)">
                    <p class="text-[10px] font-black uppercase tracking-widest opacity-70">Treasury Posture</p>
                    <p class="mt-1 text-base font-black">{{ treasuryPosture.label }}</p>
                    <p class="mt-0.5 text-[11px] font-medium opacity-80">{{ treasuryPosture.note }}</p>
                </div>
            </div>

            <!-- Inline hero KPI strip -->
            <div class="relative mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="kpi in [
                    { label: 'Paid year-to-date', value: ghs(payroll.paid_ytd.net),   sub: payroll.paid_ytd.count + ' runs settled' },
                    { label: 'Awaiting approval', value: payroll.calculated.count,     sub: ghs(payroll.calculated.net) + ' net' },
                    { label: 'Pending disburse',  value: disburse.pending.count,       sub: ghs(disburse.pending.amount) },
                    { label: 'Settled (30d)',     value: ghs(payments.paid_30d),       sub: payments.pending_count + ' payments pending' },
                ]" :key="kpi.label"
                     class="rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-4 text-white backdrop-blur-sm">
                    <p class="text-[9.5px] font-black uppercase tracking-[0.22em] text-amber-200/80">{{ kpi.label }}</p>
                    <p class="mt-2 text-2xl font-black tabular-nums">{{ kpi.value }}</p>
                    <p class="mt-0.5 text-[11px] font-medium text-white/55">{{ kpi.sub }}</p>
                </div>
            </div>
        </header>

        <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
        <!-- Main grid                                                          -->
        <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
        <div class="grid gap-6 lg:grid-cols-12">

            <!-- Payroll pipeline column ------------------------------------- -->
            <section class="lg:col-span-8 space-y-6">

                <!-- Pipeline waterfall -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Payroll pipeline</p>
                            <h3 class="text-lg font-black text-primary mt-0.5">Where the money is right now</h3>
                        </div>
                        <Link :href="route('payroll-runs.index')" class="text-[11px] font-black text-secondary hover:underline">
                            Open payroll runs →
                        </Link>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <template v-for="(stage, i) in [
                            { key: 'draft',      label: 'Draft',      icon: 'edit_note',   tone: '#94a3b8' },
                            { key: 'calculated', label: 'Calculated', icon: 'calculate',   tone: '#d97706' },
                            { key: 'approved',   label: 'Approved',   icon: 'task_alt',    tone: '#1a237e' },
                            { key: 'paid_ytd',   label: 'Paid YTD',   icon: 'payments',    tone: '#059669' },
                        ]" :key="stage.key">
                            <div class="relative rounded-2xl border border-outline-variant/40 bg-white p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md">
                                <!-- Connector arrow on desktop -->
                                <span v-if="i < 3" class="hidden md:block absolute -right-3 top-1/2 -translate-y-1/2 z-10 text-outline-variant">
                                    <span class="material-symbols-outlined text-base">chevron_right</span>
                                </span>
                                <div class="flex items-center gap-2">
                                    <span class="h-9 w-9 rounded-xl flex items-center justify-center"
                                          :style="`background:${stage.tone}1A;color:${stage.tone}`">
                                        <span class="material-symbols-outlined text-[18px]">{{ stage.icon }}</span>
                                    </span>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">{{ stage.label }}</p>
                                </div>
                                <p class="mt-3 text-2xl font-black text-primary tabular-nums">{{ payroll[stage.key].count }}</p>
                                <p class="text-[11px] font-bold text-on-surface-variant truncate">{{ ghs(payroll[stage.key].net) }}</p>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Recent payroll runs table -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="px-7 py-5 border-b border-outline-variant/50 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Recent runs</p>
                            <h3 class="text-lg font-black text-primary mt-0.5">Last 5 payroll cycles</h3>
                        </div>
                        <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Most recent first</span>
                    </div>
                    <table v-if="recentRuns.length" class="w-full">
                        <thead class="bg-surface-container-low/40">
                            <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant">
                                <th class="px-7 py-3">Reference</th>
                                <th class="px-7 py-3">Period</th>
                                <th class="px-7 py-3">Net total</th>
                                <th class="px-7 py-3">Status</th>
                                <th class="px-7 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in recentRuns" :key="run.reference" class="border-t border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                                <td class="px-7 py-3 font-mono text-[12px] font-bold text-primary">{{ run.reference }}</td>
                                <td class="px-7 py-3 text-[13px] font-bold text-on-surface">{{ run.period }}</td>
                                <td class="px-7 py-3 tabular-nums font-bold text-primary">{{ ghs(run.net_total) }}</td>
                                <td class="px-7 py-3">
                                    <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest', statusTone(run.status)]">
                                        {{ run.status }}
                                    </span>
                                </td>
                                <td class="px-7 py-3 text-right">
                                    <Link :href="route('payroll-runs.index')" class="text-[12px] font-black text-secondary hover:underline">Open</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="px-7 py-14 text-center text-[13px] text-on-surface-variant">
                        No payroll runs have been started yet.
                    </div>
                </div>

                <!-- Disbursement health -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Disbursement channel</p>
                            <h3 class="text-lg font-black text-primary mt-0.5">GhIPSS &amp; MoMo settlement health</h3>
                        </div>
                        <Link :href="route('disbursements.index')" class="text-[11px] font-black text-secondary hover:underline">
                            Open disbursement queue →
                        </Link>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div v-for="phase in [
                            { key: 'pending',  label: 'Queued',   dot: '#94a3b8' },
                            { key: 'sent',     label: 'In flight',dot: '#d97706' },
                            { key: 'settled',  label: 'Settled',  dot: '#059669' },
                            { key: 'failed',   label: 'Failed',   dot: '#e11d48' },
                        ]" :key="phase.key"
                             class="rounded-xl border border-outline-variant/40 bg-white px-4 py-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest text-on-surface-variant">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${phase.dot}`"></span>
                                    {{ phase.label }}
                                </span>
                            </div>
                            <p class="text-2xl font-black text-primary tabular-nums">{{ disburse[phase.key].count }}</p>
                            <p class="text-[11px] font-bold text-on-surface-variant">{{ ghs(disburse[phase.key].amount) }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Right rail ---------------------------------------------------- -->
            <aside class="lg:col-span-4 space-y-6">

                <!-- Statutory returns posture -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Statutory returns</p>
                            <h3 class="text-base font-black text-primary mt-0.5">PAYE · SSNIT · NHIA · Tier-2</h3>
                        </div>
                    </div>

                    <ul class="space-y-3">
                        <li v-for="row in [
                            { label: 'Generated',       value: statutory.generated, dot: '#1a237e' },
                            { label: 'Submitted',       value: statutory.submitted, dot: '#059669' },
                            { label: 'Overdue',         value: statutory.overdue,   dot: '#e11d48' },
                        ]" :key="row.label"
                            class="flex items-center justify-between border-b border-outline-variant/30 pb-3 last:border-none last:pb-0">
                            <span class="flex items-center gap-2 text-[12px] font-bold text-on-surface">
                                <span class="h-1.5 w-1.5 rounded-full" :style="`background:${row.dot}`"></span>
                                {{ row.label }}
                            </span>
                            <span class="text-xl font-black tabular-nums" :class="row.label === 'Overdue' && row.value > 0 ? 'text-rose-600' : 'text-primary'">
                                {{ row.value }}
                            </span>
                        </li>
                    </ul>

                    <p v-if="statutory.overdue > 0"
                       class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-[11px] font-bold text-rose-900 leading-relaxed">
                        <span class="material-symbols-outlined align-middle text-[14px] mr-1">priority_high</span>
                        {{ statutory.overdue }} return{{ statutory.overdue === 1 ? '' : 's' }} are past their statutory deadline. Penalties accrue daily.
                    </p>
                </div>

                <!-- Quick links -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-4">Quick actions</p>
                    <div class="grid grid-cols-2 gap-2.5">
                        <Link v-for="action in [
                            { label: 'Payroll runs',   icon: 'receipt_long',  href: route('payroll-runs.index') },
                            { label: 'Payments',       icon: 'payments',      href: route('payments.index') },
                            { label: 'Disbursement',   icon: 'send_money',    href: route('disbursements.index') },
                            { label: 'Statutory',      icon: 'gavel',         href: route('payroll-runs.index') },
                        ]" :key="action.label"
                            :href="action.href"
                            class="group rounded-xl border border-outline-variant/40 bg-white px-3 py-3 hover:border-secondary/50 hover:bg-surface-container-low transition-all">
                            <span class="material-symbols-outlined text-[20px] text-secondary group-hover:scale-110 transition-transform">{{ action.icon }}</span>
                            <p class="mt-1.5 text-[11px] font-black text-primary">{{ action.label }}</p>
                        </Link>
                    </div>
                </div>

                <!-- Compliance reminder card -->
                <div class="rounded-2xl p-6 text-white shadow-lg relative overflow-hidden"
                     style="background:linear-gradient(135deg,#0a1138 0%,#1a237e 100%);">
                    <span class="absolute -right-4 -top-4 opacity-[0.12]">
                        <span class="material-symbols-outlined text-[120px]" style="font-variation-settings:'FILL' 1">verified</span>
                    </span>
                    <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-200/90">Compliance</p>
                    <h4 class="mt-2 text-base font-black leading-snug">Statutory deadlines this month</h4>
                    <ul class="mt-4 space-y-2 text-[11px] font-bold text-white/80">
                        <li class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                            <span>PAYE return — 15th of next month (GRA)</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                            <span>SSNIT Tier-1 — 14th of next month</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                            <span>NHIA contributions — 14th of next month</span>
                        </li>
                    </ul>
                </div>

            </aside>
        </div>
    </div>
</template>
