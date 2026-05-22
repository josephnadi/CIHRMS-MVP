<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Manager overview — "what does my team need from me today?"
 * Scoped to direct reports via Employee.manager_id. Visual register stays
 * in the Sovereign Precision family but warmer at the top — managers are
 * meant to read this and act on it.
 */
const props = defineProps({
    user:     { type: Object, required: true },
    snapshot: { type: Object, default: () => ({ team_size: 0, team_active: 0,
        pending_leave_count: 0, open_ticket_count: 0,
        pending_leave_list: [], open_tickets_list: [] }) },
});

const today = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));

const priorityTone = (p) => ({
    urgent: 'border-rose-200 bg-rose-50 text-rose-900',
    high:   'border-amber-200 bg-amber-50 text-amber-900',
    normal: 'border-slate-200 bg-slate-50 text-slate-900',
    low:    'border-slate-200 bg-slate-50 text-slate-700',
}[String(p ?? 'normal').toLowerCase()] ?? 'border-slate-200 bg-slate-50 text-slate-700');

const inboxCount = computed(() => props.snapshot.pending_leave_count + props.snapshot.open_ticket_count);
</script>

<template>
    <div class="space-y-8 animate-reveal-up">
        <!-- Header -->
        <header class="relative overflow-hidden rounded-3xl border border-outline-variant/60 p-8"
                style="background:linear-gradient(135deg,#0a1138 0%,#1a237e 60%,#3949ab 100%);">
            <div class="absolute inset-x-0 top-0 h-[2px]" style="background:linear-gradient(90deg,transparent,#ffd700 50%,transparent);"></div>
            <div class="pointer-events-none absolute -right-12 -top-12 opacity-[0.08]">
                <span class="material-symbols-outlined text-white" style="font-size:240px;font-variation-settings:'FILL' 1">groups</span>
            </div>

            <div class="relative flex flex-wrap items-end justify-between gap-6">
                <div class="text-white">
                    <p class="text-[10px] font-black uppercase tracking-[0.28em] text-amber-200/90">
                        Manager Briefing · {{ today }}
                    </p>
                    <h1 class="mt-2 text-[2.4rem] font-black leading-none tracking-tight">
                        Good morning, {{ user.name?.split(' ')[0] ?? 'Manager' }}.
                    </h1>
                    <p class="mt-3 max-w-xl text-[13px] font-medium text-white/70 leading-relaxed">
                        Your direct reports, the decisions waiting on you, and where your team stands today.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/15 bg-white/[0.06] px-6 py-4 text-white backdrop-blur-sm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-200/80">Awaiting your decision</p>
                    <p class="mt-1 text-4xl font-black tabular-nums">{{ inboxCount }}</p>
                    <p class="mt-0.5 text-[11px] font-medium text-white/55">{{ snapshot.pending_leave_count }} leave · {{ snapshot.open_ticket_count }} tickets</p>
                </div>
            </div>

            <!-- KPI strip -->
            <div class="relative mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="kpi in [
                    { label: 'Direct reports',     value: snapshot.team_size,           sub: snapshot.team_active + ' active' },
                    { label: 'Pending leave',      value: snapshot.pending_leave_count, sub: 'Awaiting approval' },
                    { label: 'Open tickets',       value: snapshot.open_ticket_count,   sub: 'In your team' },
                    { label: 'Team availability',  value: (snapshot.team_size - snapshot.pending_leave_count) + '/' + Math.max(1, snapshot.team_size), sub: 'Today' },
                ]" :key="kpi.label"
                     class="rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-4 text-white backdrop-blur-sm">
                    <p class="text-[9.5px] font-black uppercase tracking-[0.22em] text-amber-200/80">{{ kpi.label }}</p>
                    <p class="mt-2 text-2xl font-black tabular-nums">{{ kpi.value }}</p>
                    <p class="mt-0.5 text-[11px] font-medium text-white/55">{{ kpi.sub }}</p>
                </div>
            </div>
        </header>

        <!-- Main grid -->
        <div class="grid gap-6 lg:grid-cols-12">

            <!-- Decisions queue ---------------------------------------------- -->
            <section class="lg:col-span-8 space-y-6">

                <!-- Leave approvals -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="px-7 py-5 border-b border-outline-variant/50 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Decisions queue</p>
                            <h3 class="text-lg font-black text-primary mt-0.5">Leave awaiting your approval</h3>
                        </div>
                        <Link :href="route('leave.index', { status: 'pending' })" class="text-[11px] font-black text-secondary hover:underline">
                            Open leave queue →
                        </Link>
                    </div>

                    <ul v-if="snapshot.pending_leave_list?.length" class="divide-y divide-outline-variant/30">
                        <li v-for="leave in snapshot.pending_leave_list" :key="leave.id"
                            class="px-7 py-4 flex items-center justify-between gap-4 hover:bg-surface-container-low transition-colors">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="h-10 w-10 rounded-xl bg-cobalt-50 flex items-center justify-center text-cobalt-800 flex-shrink-0">
                                    <span class="material-symbols-outlined text-[18px]">person</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[13px] font-black text-primary truncate">{{ leave.employee }}</p>
                                    <p class="text-[11px] font-medium text-on-surface-variant">
                                        {{ leave.position ?? leave.employee_no }} · {{ leave.leave_type ?? 'leave' }}
                                    </p>
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-[12px] font-bold text-primary">{{ leave.start_date }} → {{ leave.end_date }}</p>
                                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">
                                    {{ leave.days }} day{{ leave.days === 1 ? '' : 's' }}
                                </p>
                            </div>
                        </li>
                    </ul>
                    <div v-else class="px-7 py-14 text-center">
                        <span class="material-symbols-outlined text-3xl text-emerald-500" style="font-variation-settings:'FILL' 1">task_alt</span>
                        <p class="mt-2 text-[13px] font-bold text-on-surface-variant">No leave decisions waiting — you're clear.</p>
                    </div>
                </div>

                <!-- Team tickets -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="px-7 py-5 border-b border-outline-variant/50 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Service desk</p>
                            <h3 class="text-lg font-black text-primary mt-0.5">Open tickets in your team</h3>
                        </div>
                        <Link :href="route('tickets.index')" class="text-[11px] font-black text-secondary hover:underline">
                            Open all tickets →
                        </Link>
                    </div>

                    <ul v-if="snapshot.open_tickets_list?.length" class="divide-y divide-outline-variant/30">
                        <li v-for="t in snapshot.open_tickets_list" :key="t.reference"
                            class="px-7 py-4 flex items-center justify-between gap-4 hover:bg-surface-container-low transition-colors">
                            <div class="min-w-0">
                                <p class="font-mono text-[11px] font-black text-on-surface-variant">{{ t.reference }}</p>
                                <p class="text-[13px] font-black text-primary truncate mt-0.5">{{ t.subject }}</p>
                            </div>
                            <div class="flex-shrink-0 flex items-center gap-2">
                                <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full border text-[10px] font-black uppercase tracking-widest', priorityTone(t.priority)]">
                                    {{ t.priority ?? 'normal' }}
                                </span>
                                <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">{{ t.age_days }}d old</span>
                            </div>
                        </li>
                    </ul>
                    <div v-else class="px-7 py-14 text-center">
                        <span class="material-symbols-outlined text-3xl text-emerald-500" style="font-variation-settings:'FILL' 1">task_alt</span>
                        <p class="mt-2 text-[13px] font-bold text-on-surface-variant">No open tickets in your team.</p>
                    </div>
                </div>
            </section>

            <!-- Right rail --------------------------------------------------- -->
            <aside class="lg:col-span-4 space-y-6">

                <!-- Team composition -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-2">Team composition</p>
                    <h3 class="text-base font-black text-primary mb-5">Headcount snapshot</h3>

                    <div class="flex items-center gap-5">
                        <div class="relative h-24 w-24">
                            <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="#e2e8f0" stroke-width="8"/>
                                <circle cx="50" cy="50" r="42" fill="none" stroke="#1a237e" stroke-width="8"
                                        :stroke-dasharray="2 * Math.PI * 42"
                                        :stroke-dashoffset="2 * Math.PI * 42 * (1 - (snapshot.team_active / Math.max(1, snapshot.team_size)))"
                                        stroke-linecap="round" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <p class="text-2xl font-black text-primary tabular-nums">{{ snapshot.team_active }}</p>
                                <p class="text-[9px] font-bold text-on-surface-variant uppercase tracking-widest">active</p>
                            </div>
                        </div>
                        <div class="space-y-1.5 text-[11px] font-bold text-on-surface-variant">
                            <p class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-cobalt-700"></span>{{ snapshot.team_active }} active</p>
                            <p class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>{{ snapshot.team_size - snapshot.team_active }} other status</p>
                            <p class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>{{ snapshot.pending_leave_count }} pending leave</p>
                        </div>
                    </div>
                </div>

                <!-- Manager quick actions -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-4">Manager actions</p>
                    <div class="grid grid-cols-2 gap-2.5">
                        <Link v-for="action in [
                            { label: 'Approvals',  icon: 'fact_check',       href: route('leave.index', { status: 'pending' }) },
                            { label: 'Team',       icon: 'groups',           href: route('employees.index') },
                            { label: 'Performance',icon: 'monitoring',       href: route('employees.index') },
                            { label: 'Tickets',    icon: 'support_agent',    href: route('tickets.index') },
                        ]" :key="action.label"
                            :href="action.href"
                            class="group rounded-xl border border-outline-variant/40 bg-white px-3 py-3 hover:border-secondary/50 transition-all">
                            <span class="material-symbols-outlined text-[20px] text-secondary group-hover:scale-110 transition-transform">{{ action.icon }}</span>
                            <p class="mt-1.5 text-[11px] font-black text-primary">{{ action.label }}</p>
                        </Link>
                    </div>
                </div>

                <!-- Coaching prompt -->
                <div class="rounded-2xl p-6 text-white shadow-lg relative overflow-hidden"
                     style="background:linear-gradient(135deg,#0a1138,#1a237e);">
                    <span class="absolute -right-4 -top-4 opacity-[0.12]">
                        <span class="material-symbols-outlined text-[120px]" style="font-variation-settings:'FILL' 1">lightbulb</span>
                    </span>
                    <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-200/90">Coaching prompt</p>
                    <h4 class="mt-2 text-base font-black leading-snug">One conversation worth having today</h4>
                    <p class="mt-3 text-[12px] font-medium text-white/75 leading-relaxed">
                        Set aside 15 minutes for a check-in with a report you haven't spoken with this week.
                        Use the AI assistant to pull a quick briefing on their recent activity.
                    </p>
                </div>
            </aside>
        </div>
    </div>
</template>
