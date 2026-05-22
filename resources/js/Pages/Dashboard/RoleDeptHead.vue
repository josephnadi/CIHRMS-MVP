<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Department Head overview. A dept_head is a wider role than a single
 * line manager — they own the whole department's health: headcount,
 * leave coverage today, open tickets, complaints.
 *
 * Composition: navy hero like the other role dashboards, then a
 * department-coverage strip + the most-recent leave activity feed.
 */
const props = defineProps({
    user:           { type: Object, required: true },
    deptSnapshot:   { type: Object, default: () => null },
    teamSnapshot:   { type: Object, default: () => null },   // re-uses manager snapshot if user is also a line manager
});

const today = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));

const dept = computed(() => props.deptSnapshot ?? {
    dept: null, headcount: 0, active: 0, on_leave_today: 0,
    open_tickets: 0, open_complaints: 0, recent_leave: [],
});

const coverage = computed(() => {
    const total = Math.max(1, dept.value.headcount);
    const present = total - dept.value.on_leave_today;
    return Math.round((present / total) * 100);
});

const leaveTone = (s) => ({
    pending:  'bg-amber-50 text-amber-800',
    approved: 'bg-emerald-50 text-emerald-800',
    rejected: 'bg-rose-50 text-rose-800',
    cancelled:'bg-slate-50 text-slate-600',
}[s] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <div class="space-y-8 animate-reveal-up">
        <!-- Header -->
        <header class="relative overflow-hidden rounded-3xl border border-outline-variant/60 p-8"
                style="background:linear-gradient(135deg,#0a1138 0%,#1a237e 60%,#3949ab 100%);">
            <div class="absolute inset-x-0 top-0 h-[2px]" style="background:linear-gradient(90deg,transparent,#ffd700 50%,transparent);"></div>
            <div class="pointer-events-none absolute -right-12 -top-12 opacity-[0.08]">
                <span class="material-symbols-outlined text-white" style="font-size:240px;font-variation-settings:'FILL' 1">corporate_fare</span>
            </div>

            <div class="relative flex flex-wrap items-end justify-between gap-6">
                <div class="text-white">
                    <p class="text-[10px] font-black uppercase tracking-[0.28em] text-amber-200/90">
                        Department Briefing · {{ today }}
                    </p>
                    <h1 class="mt-2 text-[2.4rem] font-black leading-none tracking-tight">
                        {{ dept.dept?.name ?? 'Department' }} — {{ user.name?.split(' ')[0] ?? 'Director' }}
                    </h1>
                    <p class="mt-3 max-w-xl text-[13px] font-medium text-white/70 leading-relaxed">
                        How your department is holding today. Headcount, leave coverage, open service load.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/15 bg-white/[0.06] px-6 py-4 text-white backdrop-blur-sm text-center">
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-200/80">Coverage today</p>
                    <p class="mt-1 text-4xl font-black tabular-nums">{{ coverage }}%</p>
                    <p class="mt-0.5 text-[11px] font-medium text-white/55">
                        {{ Math.max(0, dept.headcount - dept.on_leave_today) }}/{{ dept.headcount }} at post
                    </p>
                </div>
            </div>

            <!-- KPI strip -->
            <div class="relative mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="kpi in [
                    { label: 'Headcount',       value: dept.headcount,        sub: dept.active + ' active' },
                    { label: 'On leave today',  value: dept.on_leave_today,   sub: 'Approved & in-window' },
                    { label: 'Open tickets',    value: dept.open_tickets,     sub: 'Department-scoped' },
                    { label: 'Open complaints', value: dept.open_complaints,  sub: 'Awaiting resolution' },
                ]" :key="kpi.label"
                     class="rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-4 text-white backdrop-blur-sm">
                    <p class="text-[9.5px] font-black uppercase tracking-[0.22em] text-amber-200/80">{{ kpi.label }}</p>
                    <p class="mt-2 text-2xl font-black tabular-nums">{{ kpi.value }}</p>
                    <p class="mt-0.5 text-[11px] font-medium text-white/55">{{ kpi.sub }}</p>
                </div>
            </div>
        </header>

        <!-- Body grid -->
        <div class="grid gap-6 lg:grid-cols-12">

            <section class="lg:col-span-8 space-y-6">

                <!-- Recent leave feed -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="px-7 py-5 border-b border-outline-variant/50 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Department activity</p>
                            <h3 class="text-lg font-black text-primary mt-0.5">Recent leave decisions</h3>
                        </div>
                        <Link :href="route('leave.index')" class="text-[11px] font-black text-secondary hover:underline">
                            Open leave register →
                        </Link>
                    </div>

                    <ul v-if="dept.recent_leave?.length" class="divide-y divide-outline-variant/30">
                        <li v-for="(row, i) in dept.recent_leave" :key="i"
                            class="px-7 py-4 flex items-center justify-between gap-4 hover:bg-surface-container-low transition-colors">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="h-9 w-9 rounded-xl bg-cobalt-50 flex items-center justify-center text-cobalt-800 flex-shrink-0">
                                    <span class="material-symbols-outlined text-[16px]">calendar_today</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[13px] font-black text-primary truncate">{{ row.employee }}</p>
                                    <p class="text-[11px] font-medium text-on-surface-variant">{{ row.start }} → {{ row.end }}</p>
                                </div>
                            </div>
                            <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest', leaveTone(row.status)]">
                                {{ row.status }}
                            </span>
                        </li>
                    </ul>
                    <div v-else class="px-7 py-14 text-center text-[13px] font-bold text-on-surface-variant">
                        No leave activity in this department recently.
                    </div>
                </div>
            </section>

            <aside class="lg:col-span-4 space-y-6">
                <!-- Coverage ring -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7 text-center">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-4">Coverage gauge</p>
                    <div class="relative mx-auto h-32 w-32">
                        <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="#e2e8f0" stroke-width="9"/>
                            <circle cx="50" cy="50" r="42" fill="none"
                                    :stroke="coverage >= 85 ? '#059669' : coverage >= 70 ? '#d97706' : '#e11d48'"
                                    stroke-width="9"
                                    :stroke-dasharray="2 * Math.PI * 42"
                                    :stroke-dashoffset="2 * Math.PI * 42 * (1 - coverage / 100)"
                                    stroke-linecap="round"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <p class="text-3xl font-black text-primary tabular-nums">{{ coverage }}%</p>
                            <p class="text-[9px] font-bold text-on-surface-variant uppercase tracking-widest">at post</p>
                        </div>
                    </div>
                    <p class="mt-4 text-[11px] font-bold text-on-surface-variant">
                        {{ dept.on_leave_today }} of {{ dept.headcount }} on approved leave today
                    </p>
                </div>

                <!-- Quick links -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-4">Department tools</p>
                    <div class="grid grid-cols-2 gap-2.5">
                        <Link v-for="action in [
                            { label: 'Employees',  icon: 'badge',         href: route('employees.index') },
                            { label: 'Leave',      icon: 'calendar_today',href: route('leave.index') },
                            { label: 'Tickets',    icon: 'support_agent', href: route('tickets.index') },
                            { label: 'Complaints', icon: 'flag',          href: route('complaints.index') },
                        ]" :key="action.label"
                            :href="action.href"
                            class="group rounded-xl border border-outline-variant/40 bg-white px-3 py-3 hover:border-secondary/50 transition-all">
                            <span class="material-symbols-outlined text-[20px] text-secondary group-hover:scale-110 transition-transform">{{ action.icon }}</span>
                            <p class="mt-1.5 text-[11px] font-black text-primary">{{ action.label }}</p>
                        </Link>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
