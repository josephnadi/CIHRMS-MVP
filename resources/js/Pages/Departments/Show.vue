<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    slug:         { type: String, required: true },   // 'it' | 'hr' | 'marketing' | 'finance'
    department:   { type: Object, default: null },    // optional real Department record
    activeModule: { type: String, default: '' },
});

const { comingSoon } = useToast();

// ── Per-portal branding + KPI templates ──────────────────────────────
const PORTALS = {
    it: {
        title:  'IT &amp; Technology',
        tagline:'Infrastructure, security, and service operations.',
        icon:   'computer',
        accent: '#316bf3',
        rgb:    '49,107,243',
        kpis: [
            { label: 'Servers Online', val: '23 / 24',  sub: 'capacity',     color: '#316bf3', icon: 'dns' },
            { label: 'Open Tickets',   val: 18,         sub: '3 critical',   color: '#dc2626', icon: 'bug_report' },
            { label: 'Security Alerts',val: 4,          sub: 'low severity', color: '#d97706', icon: 'security' },
            { label: 'Uptime SLA',     val: '99.97%',   sub: 'target 99.9%', color: '#059669', icon: 'electric_bolt' },
        ],
        sections: [
            { icon: 'dns',           title: 'Infrastructure', body: 'Production cluster running 99.97% uptime over the last 30 days. Active workloads spread across 3 availability zones.' },
            { icon: 'security',      title: 'Security Posture', body: 'No active incidents. Last penetration test passed Apr 2026. EDR deployed across 1,212 endpoints.' },
            { icon: 'integration_instructions', title: 'Integrations', body: 'Slack, Microsoft 365, Zoho CRM, WhatsApp Business — all green.' },
        ],
    },
    hr: {
        title:  'Human Resources',
        tagline:'People operations, recruitment and culture.',
        icon:   'people',
        accent: '#0891b2',
        rgb:    '8,145,178',
        kpis: [
            { label: 'Headcount',       val: '1,284', sub: '+2 this week',  color: '#059669', icon: 'badge' },
            { label: 'Turnover Rate',   val: '2.8%',  sub: 'vs 5% target',  color: '#0051d5', icon: 'person_remove' },
            { label: 'Open Positions',  val: 14,      sub: '6 in pipeline', color: '#d97706', icon: 'work_outline' },
            { label: 'Training Compl.', val: '83%',   sub: 'annual goal',   color: '#7c5cff', icon: 'school' },
        ],
        sections: [
            { icon: 'group_add',  title: 'Recruitment Pipeline', body: '14 active roles, 187 applicants in review. Average time-to-hire 18 days.' },
            { icon: 'school',     title: 'Learning &amp; Growth', body: '83% of staff have completed mandatory annual training. 47 enrolled in leadership development.' },
            { icon: 'event',      title: 'Engagement', body: 'Q2 pulse survey: 4.6/5 institutional NPS. Town hall scheduled for next month.' },
        ],
    },
    marketing: {
        title:  'Marketing',
        tagline:'Brand, communications and campaign delivery.',
        icon:   'campaign',
        accent: '#7c3aed',
        rgb:    '124,58,237',
        kpis: [
            { label: 'Campaign ROI',   val: '320%',  sub: 'YTD',           color: '#7c3aed', icon: 'trending_up' },
            { label: 'Budget Used',    val: '74%',   sub: 'of GHS 2.4M',   color: '#0051d5', icon: 'pie_chart' },
            { label: 'Leads Generated',val: '2,847', sub: 'this quarter',  color: '#059669', icon: 'how_to_vote' },
            { label: 'Conversion',     val: '5.2%',  sub: 'lead → meeting',color: '#d97706', icon: 'percent' },
        ],
        sections: [
            { icon: 'rocket_launch', title: 'Active Campaigns', body: '3 institutional campaigns running. Top performer: "Charter Anniversary Series" — 1.2M impressions.' },
            { icon: 'palette',       title: 'Brand Assets',     body: 'Updated logo system rolled out to 6 product surfaces. Asset library: 412 files, 22 contributors.' },
            { icon: 'forum',         title: 'External Channels',body: 'LinkedIn 18.4K followers (+340 this month). Press mentions: 7 institutional features.' },
        ],
    },
    finance: {
        title:  'Finance',
        tagline:'Treasury, payroll and statutory compliance.',
        icon:   'account_balance_wallet',
        accent: '#059669',
        rgb:    '5,150,105',
        kpis: [
            { label: 'Monthly Revenue',val: 'GHS 8.7M', sub: '+4.2% MoM',    color: '#059669', icon: 'attach_money' },
            { label: 'Budget Variance',val: '-2.1%',    sub: 'under budget', color: '#0051d5', icon: 'analytics' },
            { label: 'Pending Invoices',val: 142,       sub: 'GHS 1.2M',     color: '#d97706', icon: 'receipt_long' },
            { label: 'Fund Efficiency',val: '94%',      sub: 'utilization',  color: '#7c3aed', icon: 'savings' },
        ],
        sections: [
            { icon: 'payments',      title: 'Payroll Cycle',     body: 'May 2026 cycle 85% processed. SSNIT and PAYE remittances on schedule.' },
            { icon: 'fact_check',    title: 'Audit Status',      body: 'Q1 internal audit completed. No material findings. External audit window opens June.' },
            { icon: 'receipt_long',  title: 'Statutory Reports', body: 'GRA filing complete for Apr. SSNIT contributions remitted. PAYE returns submitted on time.' },
        ],
    },
};

const portal = computed(() => PORTALS[props.slug] ?? PORTALS.it);
</script>

<template>
    <Head :title="`${portal.title} — CIHRMS`" />

    <AuthenticatedLayout :activeModule="activeModule || 'dept-' + slug">

        <!-- Hero strip -->
        <div class="mb-6 overflow-hidden rounded-3xl text-white"
             style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06);">
            <div class="relative px-7 py-7">
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl"
                     :style="`background:radial-gradient(circle,rgba(${portal.rgb},0.25),transparent 70%)`"></div>
                <div class="relative flex flex-wrap items-center justify-between gap-5">
                    <div class="flex items-center gap-5">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl flex-shrink-0"
                             :style="`background:rgba(${portal.rgb},0.2);border:1px solid rgba(${portal.rgb},0.3)`">
                            <span class="material-symbols-outlined text-3xl"
                                  :style="`color:${portal.accent};font-variation-settings:'FILL' 1`">{{ portal.icon }}</span>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/45 mb-1">Department Portal</p>
                            <h1 class="text-[24px] font-black tracking-tight" v-html="portal.title"></h1>
                            <p class="text-[12.5px] text-white/55">{{ portal.tagline }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5"
                              :style="`background:rgba(${portal.rgb},0.15);border:1px solid rgba(${portal.rgb},0.3)`">
                            <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${portal.accent}`"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest" :style="`color:${portal.accent}`">Active</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI strip -->
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="(k, i) in portal.kpis" :key="i"
                class="card-lift relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl"
                         :style="`background:${k.color}18;border:1px solid ${k.color}30`">
                        <span class="material-symbols-outlined text-[18px]"
                              :style="`color:${k.color};font-variation-settings:'FILL' 1`">{{ k.icon }}</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/40">{{ k.sub }}</span>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ k.label }}</p>
                <p class="mt-0.5 text-[24px] font-black tracking-tight text-on-surface tabular-nums">{{ k.val }}</p>
            </div>
        </div>

        <!-- Sections + quick links -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div v-for="s in portal.sections" :key="s.title"
                 class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl"
                     :style="`background:${portal.accent}15;border:1px solid ${portal.accent}30`">
                    <span class="material-symbols-outlined text-[20px]" :style="`color:${portal.accent}`">{{ s.icon }}</span>
                </div>
                <h3 class="text-[14px] font-bold text-on-surface" v-html="s.title"></h3>
                <p class="mt-1.5 text-[12.5px] leading-relaxed text-on-surface-variant" v-html="s.body"></p>
            </div>
        </div>

        <!-- Cross-links -->
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Link :href="route('employees.index')" class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary/10 text-secondary">
                    <span class="material-symbols-outlined text-[19px]">badge</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Department Roster</span>
            </Link>
            <Link :href="route('tickets.index')" class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600">
                    <span class="material-symbols-outlined text-[19px]">support_agent</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Service Desk</span>
            </Link>
            <Link :href="route('reports.index')" class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600">
                    <span class="material-symbols-outlined text-[19px]">assessment</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Reports</span>
            </Link>
            <button @click="comingSoon(portal.title.replace('&amp;','&') + ' settings')" type="button"
                    class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-500/10 text-slate-600">
                    <span class="material-symbols-outlined text-[19px]">tune</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Configure</span>
            </button>
        </div>
    </AuthenticatedLayout>
</template>
