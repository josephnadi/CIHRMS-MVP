<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Catch-all role overview for IT support, Marketing, Auditor — and any
 * future role we add. Adapts its tagline, accent, and quick-actions block
 * to the active user.role; everything else is derived from the same
 * `stats` + `activityFeed` payload the admin overview already gets.
 *
 * Intentionally smaller surface than the Finance/Manager/DeptHead views
 * because these roles don't (yet) have role-specific server queries.
 */
const props = defineProps({
    user:         { type: Object, required: true },
    stats:        { type: Object, default: () => ({}) },
    activityFeed: { type: Array,  default: () => [] },
    headcountByDept: { type: Array, default: () => [] },
});

const today = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));

const ROLE_PROFILES = {
    it_support: {
        label:   'IT Support',
        tagline: 'Service desk posture, asset register, system health',
        icon:    'memory',
        kpis: [
            { label: 'Open tickets',     statKey: 'openTickets' },
            { label: 'Active employees', statKey: 'employees' },
            { label: 'Pending leave',    statKey: 'pendingLeave' },
            { label: 'Open jobs',        statKey: 'openJobs' },
        ],
        quickActions: [
            { label: 'Tickets',  icon: 'support_agent', name: 'tickets.index' },
            { label: 'Assets',   icon: 'inventory_2',   name: 'assets.index' },
            { label: 'Audit',    icon: 'history',       name: 'audit.index' },
            { label: 'Settings', icon: 'settings',      name: 'profile.edit' },
        ],
    },
    marketing: {
        label:   'Marketing',
        tagline: 'Recruitment funnel, applicant pipeline, employer brand',
        icon:    'campaign',
        kpis: [
            { label: 'Open jobs',         statKey: 'openJobs' },
            { label: 'Active employees',  statKey: 'employees' },
            { label: 'Open tickets',      statKey: 'openTickets' },
            { label: 'Pending payments',  statKey: 'pendingPayments' },
        ],
        quickActions: [
            { label: 'Recruitment',    icon: 'person_search', name: 'recruitment.index' },
            { label: 'Announcements',  icon: 'campaign',      name: 'announcements.index' },
            { label: 'Documents',      icon: 'description',   name: 'documents.index' },
            { label: 'Profile',        icon: 'settings',      name: 'profile.edit' },
        ],
    },
    auditor: {
        label:   'Internal Audit',
        tagline: 'Audit chain integrity, DPA queue, compliance posture',
        icon:    'verified',
        kpis: [
            { label: 'Open complaints',  statKey: 'openComplaints' },
            { label: 'Active employees', statKey: 'employees' },
            { label: 'Open tickets',     statKey: 'openTickets' },
            { label: 'Pending payments', statKey: 'pendingPayments' },
        ],
        quickActions: [
            { label: 'Audit',       icon: 'history',         name: 'audit.index' },
            { label: 'Whistleblower',icon: 'shield_person',   name: 'whistleblower.investigator.index' },
            { label: 'DPA',         icon: 'policy',          name: 'dpa.queue' },
            { label: 'Profile',     icon: 'settings',        name: 'profile.edit' },
        ],
    },
    default: {
        label:   'Overview',
        tagline: 'Your institute at a glance.',
        icon:    'dashboard',
        kpis: [
            { label: 'Active employees', statKey: 'employees' },
            { label: 'Open tickets',     statKey: 'openTickets' },
            { label: 'Pending leave',    statKey: 'pendingLeave' },
            { label: 'Open jobs',        statKey: 'openJobs' },
        ],
        quickActions: [
            { label: 'Profile',     icon: 'account_circle', name: 'profile.edit' },
            { label: 'Documents',   icon: 'description',    name: 'documents.index' },
            { label: 'Leave',       icon: 'calendar_today', name: 'leave.index' },
            { label: 'Tickets',     icon: 'support_agent',  name: 'tickets.index' },
        ],
    },
};

const profile = computed(() => ROLE_PROFILES[props.user.role] ?? ROLE_PROFILES.default);

/**
 * Route lookup with graceful fallback: not every role has every route
 * registered (e.g. auditor might not have `dpa.queue`). When Ziggy can't
 * resolve a name, return '#' so the link is benign.
 */
function safeRoute(name) {
    try { return route(name); } catch (_) { return '#'; }
}

const topDept = computed(() => (props.headcountByDept ?? [])
    .slice()
    .sort((a, b) => (b.value ?? 0) - (a.value ?? 0))
    .slice(0, 5));
</script>

<template>
    <div class="space-y-8 animate-reveal-up">
        <!-- Header -->
        <header class="relative overflow-hidden rounded-3xl border border-outline-variant/60 p-8"
                style="background:linear-gradient(135deg,#0a1138 0%,#1a237e 60%,#283593 100%);">
            <div class="absolute inset-x-0 top-0 h-[2px]" style="background:linear-gradient(90deg,transparent,#ffd700 50%,transparent);"></div>
            <div class="pointer-events-none absolute -right-12 -top-12 opacity-[0.08]">
                <span class="material-symbols-outlined text-white" style="font-size:240px;font-variation-settings:'FILL' 1">{{ profile.icon }}</span>
            </div>

            <div class="relative">
                <p class="text-[10px] font-black uppercase tracking-[0.28em] text-amber-200/90">
                    {{ profile.label }} · {{ today }}
                </p>
                <h1 class="mt-2 text-[2.4rem] font-black leading-none tracking-tight text-white">
                    Good morning, {{ user.name?.split(' ')[0] ?? 'Welcome' }}.
                </h1>
                <p class="mt-3 max-w-xl text-[13px] font-medium text-white/70 leading-relaxed">
                    {{ profile.tagline }}
                </p>

                <!-- KPI strip -->
                <div class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="kpi in profile.kpis" :key="kpi.label"
                         class="rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-4 text-white backdrop-blur-sm">
                        <p class="text-[9.5px] font-black uppercase tracking-[0.22em] text-amber-200/80">{{ kpi.label }}</p>
                        <p class="mt-2 text-3xl font-black tabular-nums">{{ stats[kpi.statKey] ?? 0 }}</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Body -->
        <div class="grid gap-6 lg:grid-cols-12">

            <!-- Activity feed -->
            <section class="lg:col-span-8 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="px-7 py-5 border-b border-outline-variant/50">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Institute activity</p>
                    <h3 class="text-lg font-black text-primary mt-0.5">Recent events across the platform</h3>
                </div>
                <ul v-if="activityFeed.length" class="divide-y divide-outline-variant/30 max-h-[480px] overflow-y-auto">
                    <li v-for="(ev, i) in activityFeed" :key="i" class="px-7 py-4 flex items-start gap-3">
                        <span class="h-9 w-9 rounded-xl flex items-center justify-center flex-shrink-0"
                              :style="`background:${ev.color}1A;color:${ev.color}`">
                            <span class="material-symbols-outlined text-[18px]">{{ ev.icon }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-bold text-primary">{{ ev.text }}</p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mt-0.5">{{ ev.time }}</p>
                        </div>
                    </li>
                </ul>
                <div v-else class="px-7 py-14 text-center text-[13px] font-bold text-on-surface-variant">
                    No recent activity yet.
                </div>
            </section>

            <!-- Right rail -->
            <aside class="lg:col-span-4 space-y-6">
                <!-- Headcount by department -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-2">Workforce shape</p>
                    <h3 class="text-base font-black text-primary mb-5">Top departments</h3>
                    <ul v-if="topDept.length" class="space-y-3">
                        <li v-for="d in topDept" :key="d.label">
                            <div class="flex items-center justify-between text-[11px] font-bold">
                                <span class="text-on-surface truncate">{{ d.label }}</span>
                                <span class="text-primary tabular-nums">{{ d.value }}</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                                <div class="h-full rounded-full bg-cobalt-700 transition-all duration-700"
                                     :style="`width:${Math.min(100, (d.value / Math.max(1, topDept[0].value)) * 100)}%`"></div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-[12px] font-bold text-on-surface-variant">No headcount data yet.</p>
                </div>

                <!-- Quick actions -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-7">
                    <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80 mb-4">Quick actions</p>
                    <div class="grid grid-cols-2 gap-2.5">
                        <Link v-for="action in profile.quickActions" :key="action.label"
                              :href="safeRoute(action.name)"
                              class="group rounded-xl border border-outline-variant/40 bg-white px-3 py-3 hover:border-secondary/50 transition-all">
                            <span class="material-symbols-outlined text-[20px] text-secondary group-hover:scale-110 transition-transform">{{ action.icon }}</span>
                            <p class="mt-1.5 text-[11px] font-black text-primary">{{ action.label }}</p>
                        </Link>
                    </div>
                </div>

                <!-- Notice strip -->
                <div class="rounded-2xl p-6 text-white shadow-lg relative overflow-hidden"
                     style="background:linear-gradient(135deg,#0a1138,#1a237e);">
                    <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-200/90">For the record</p>
                    <h4 class="mt-2 text-base font-black leading-snug">Logged in as {{ profile.label }}</h4>
                    <p class="mt-3 text-[12px] font-medium text-white/75 leading-relaxed">
                        Your view is scoped to the modules your role is authorised to use.
                        Need broader access? Ask the HR admin to update your permissions.
                    </p>
                </div>
            </aside>
        </div>
    </div>
</template>
