<script setup>
import { reactive, computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusPill from '@/Components/StatusPill.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    reports:           Object,
    stats:             { type: Object, default: () => ({}) },
    severityBreakdown: { type: Object, default: () => ({}) },
    categoryBreakdown: { type: Object, default: () => ({}) },
    statusBreakdown:   { type: Object, default: () => ({}) },
    filters:           Object,
    activeModule:      String,
});

const reportRows = computed(() => props.reports?.data ?? []);

// ── Filters ──
const localFilters = reactive({
    status:   props.filters?.status   ?? '',
    severity: props.filters?.severity ?? '',
    category: props.filters?.category ?? '',
});

const applyFilters = () => router.get(route('whistleblower.admin.index'), {
    status:   localFilters.status   || undefined,
    severity: localFilters.severity || undefined,
    category: localFilters.category || undefined,
}, { preserveState: true, replace: true });

const hasFilters = computed(() => localFilters.status || localFilters.severity || localFilters.category);
const clearFilters = () => { localFilters.status = ''; localFilters.severity = ''; localFilters.category = ''; applyFilters(); };

// ── Severity meta (semantic colors — universal danger signals) ──
const SEVERITY_META = {
    critical: { label: 'Critical', cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#d912e3', accent: '#dc2626' },
    high:     { label: 'High',     cls: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#ffd700', accent: '#d97706' },
    medium:   { label: 'Medium',   cls: 'bg-blue-50 text-blue-700 border-blue-200',     dot: '#1a237e', accent: '#1a237e' },
    low:      { label: 'Low',      cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#7986cb', accent: '#7986cb' },
};
const sevMeta = (s) => SEVERITY_META[s] ?? { label: '—', cls: 'bg-slate-100 text-slate-500 border-slate-200', dot: '#94a3b8', accent: '#94a3b8' };

// ── Status meta ──
// Pill rendering is delegated to <StatusPill /> which sources colour, label,
// dot and icon from the shared registry. We keep a slim STATUS_META here
// purely so the filter dropdown can iterate { label } pairs.
const STATUS_META = {
    submitted:              { label: 'Submitted' },
    triaged:                { label: 'Triaged' },
    investigating:          { label: 'Investigating' },
    evidence_gathering:     { label: 'Evidence' },
    closed_substantiated:   { label: 'Substantiated' },
    closed_unsubstantiated: { label: 'Unsubstantiated' },
    closed_referred:        { label: 'Referred' },
    withdrawn:              { label: 'Withdrawn' },
};

// ── Category meta (palette-keyed) ──
const CATEGORY_META = {
    fraud:                { label: 'Fraud',                  accent: '#d912e3', icon: 'gavel' },
    corruption:           { label: 'Corruption',             accent: '#dc2626', icon: 'paid' },
    harassment:           { label: 'Harassment',             accent: '#d912e3', icon: 'shield_person' },
    discrimination:       { label: 'Discrimination',         accent: '#d912e3', icon: 'diversity_3' },
    safety:               { label: 'Safety',                 accent: '#d97706', icon: 'health_and_safety' },
    health_and_safety:    { label: 'Health & Safety',        accent: '#d97706', icon: 'health_and_safety' },
    misuse_of_funds:      { label: 'Misuse of funds',        accent: '#ffd700', icon: 'savings' },
    abuse_of_authority:   { label: 'Abuse of authority',     accent: '#dc2626', icon: 'gavel' },
    data_breach:          { label: 'Data breach',            accent: '#12d9e3', icon: 'security' },
    procurement:          { label: 'Procurement',            accent: '#1a237e', icon: 'shopping_bag' },
    conflict_of_interest: { label: 'Conflict of interest',   accent: '#7986cb', icon: 'sync_problem' },
    other:                { label: 'Other',                  accent: '#7986cb', icon: 'help' },
};
const catMeta = (k) => CATEGORY_META[k] ?? { label: (k ?? '—').replace(/_/g, ' '), accent: '#7986cb', icon: 'description' };

// ── Severity bars ──
const severityBars = computed(() => {
    const order = ['critical', 'high', 'medium', 'low'];
    const total = order.reduce((s, k) => s + Number(props.severityBreakdown?.[k] ?? 0), 0);
    return order.map(k => ({
        key: k,
        count: Number(props.severityBreakdown?.[k] ?? 0),
        pct: total > 0 ? Math.round((props.severityBreakdown?.[k] ?? 0) / total * 100) : 0,
        meta: sevMeta(k),
    }));
});

// ── Top categories (sorted desc, top 6) ──
const topCategories = computed(() => {
    const entries = Object.entries(props.categoryBreakdown ?? {});
    if (!entries.length) return [];
    const sorted = entries.sort((a, b) => b[1] - a[1]).slice(0, 6);
    const max = sorted[0]?.[1] || 1;
    return sorted.map(([key, count]) => ({
        key, count,
        pct: Math.round((count / max) * 100),
        meta: catMeta(key),
    }));
});

const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const ageDays = (d) => {
    if (!d) return null;
    return Math.floor((Date.now() - new Date(d).getTime()) / 86400000);
};

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length-1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

// ── Editorial Sovereign masthead label ──
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
</script>

<template>
    <Head title="Whistleblower — Investigator Dashboard" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-rose-600" style="font-variation-settings:'FILL' 1">shield</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-rose-700 dark:text-rose-400">Segregated investigation · Act 720</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Whistleblower Office</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Independent investigator register · confidentiality enforced by RBAC
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 rounded-full bg-rose-50 border border-rose-200 px-3 py-1.5 dark:bg-rose-900/20 dark:border-rose-800/40">
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-600 live-dot"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-300">{{ stats?.open_total ?? 0 }} open</span>
                        </div>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- Stat tiles -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(c, i) in [
                        { label: 'Open Register',   val: stats?.open_total ?? 0,      sub: 'Disclosures in investigation', cls: 'icon-brand',   icon: 'inventory' },
                        { label: 'Awaiting Triage', val: stats?.awaiting_triage ?? 0, sub: 'Inbox to classify',            cls: 'icon-gold',    icon: 'inbox' },
                        { label: 'Critical Open',   val: stats?.critical_open ?? 0,   sub: 'Immediate review',             cls: 'icon-danger',  icon: 'priority_high' },
                        { label: 'Closed YTD',      val: stats?.closed_ytd ?? 0,      sub: 'Concluded this year',          cls: 'icon-cyan',    icon: 'check_circle' },
                    ]" :key="c.label"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                        <div class="icon-tile" :class="c.cls">
                            <span class="material-symbols-outlined">{{ c.icon }}</span>
                        </div>
                        <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ c.label }}</p>
                        <p class="mt-1 text-2xl font-black text-primary">{{ Number(c.val).toLocaleString() }}</p>
                        <p class="mt-0.5 text-[11px] font-semibold text-on-surface-variant/70">{{ c.sub }}</p>
                    </div>
                </div>

                <!-- ── Confidentiality + SLA banner ── -->
                <div v-if="stats?.overdue_triage > 0 || stats?.critical_open > 0"
                     class="rounded-2xl border-2 px-5 py-4 flex items-center justify-between gap-4 animate-reveal-up"
                     :class="stats?.critical_open > 0
                        ? 'border-rose-300 bg-gradient-to-r from-rose-50 to-amber-50/40 dark:from-rose-900/15 dark:to-amber-900/10 dark:border-rose-800/40'
                        : 'border-amber-300 bg-gradient-to-r from-amber-50 to-amber-50/60 dark:from-amber-900/15 dark:to-amber-900/5 dark:border-amber-800/40'">
                    <div class="flex items-center gap-3">
                        <div class="icon-tile flex-shrink-0"
                             :class="stats?.critical_open > 0 ? 'icon-danger' : 'icon-gold'">
                            <span class="material-symbols-outlined">{{ stats?.critical_open > 0 ? 'priority_high' : 'pending_actions' }}</span>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest"
                               :class="stats?.critical_open > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-amber-700 dark:text-amber-300'">
                                {{ stats?.critical_open > 0 ? 'Urgent — critical severity' : 'SLA breach — triage overdue' }}
                            </p>
                            <p class="text-[13.5px] font-black mt-0.5"
                               :class="stats?.critical_open > 0 ? 'text-rose-900 dark:text-rose-200' : 'text-amber-900 dark:text-amber-200'">
                                <template v-if="stats?.critical_open > 0">
                                    {{ stats.critical_open }} critical case{{ stats.critical_open === 1 ? '' : 's' }} require immediate review.
                                </template>
                                <template v-else>
                                    {{ stats.overdue_triage }} submission{{ stats.overdue_triage === 1 ? '' : 's' }} have been awaiting triage for more than 3 days.
                                </template>
                            </p>
                        </div>
                    </div>
                    <button v-if="stats?.critical_open > 0" @click="localFilters.severity = 'critical'; applyFilters()"
                            class="rounded-xl bg-white dark:bg-rose-900/30 border border-rose-300 dark:border-rose-700/50 px-4 py-2 text-[12px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/50 transition-colors flex-shrink-0">
                        Show critical
                    </button>
                    <button v-else @click="localFilters.status = 'submitted'; applyFilters()"
                            class="rounded-xl bg-white dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700/50 px-4 py-2 text-[12px] font-black uppercase tracking-widest text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition-colors flex-shrink-0">
                        Show triage queue
                    </button>
                </div>

                <!-- ── Visual band: severity composition + top categories ── -->
                <div class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                    <!-- Severity composition -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Severity composition</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">All-time</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-5">Click a band to filter the register.</p>

                        <div class="space-y-3">
                            <button v-for="(row, i) in severityBars" :key="row.key"
                                    @click="localFilters.severity = localFilters.severity === row.key ? '' : row.key; applyFilters()"
                                    class="w-full flex items-center gap-3 transition-all"
                                    :class="localFilters.severity && localFilters.severity !== row.key ? 'opacity-40' : ''"
                                    :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s`">
                                <span class="w-20 flex-shrink-0 text-[11px] font-black uppercase tracking-wider" :style="`color:${row.meta.accent}`">{{ row.meta.label }}</span>
                                <div class="flex-1 h-6 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                    <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-2.5"
                                         :style="`width:${row.pct}%;background:linear-gradient(90deg,${row.meta.accent}cc,${row.meta.accent})`">
                                        <span class="text-[10px] font-black text-white tabular-nums">{{ row.count }}</span>
                                    </div>
                                </div>
                                <span class="w-10 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ row.pct }}%</span>
                            </button>
                        </div>

                        <!-- Anonymous % ribbon -->
                        <div class="mt-5 pt-4 border-t border-outline-variant/40 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px] text-cyan-600">visibility_off</span>
                                <span class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Anonymous filings</span>
                            </div>
                            <span class="text-[14px] font-black tabular-nums text-primary">{{ stats?.anonymous_pct ?? 0 }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700"
                                 :style="`width:${stats?.anonymous_pct ?? 0}%;background:linear-gradient(90deg,#1a237e,#12d9e3)`"></div>
                        </div>
                    </div>

                    <!-- Top categories (spans 2/3) -->
                    <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Top categories</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Most reported</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-5">Click a category to filter the register.</p>

                        <div v-if="topCategories.length" class="grid grid-cols-2 gap-3 flex-1">
                            <button v-for="(row, i) in topCategories" :key="row.key"
                                    @click="localFilters.category = localFilters.category === row.key ? '' : row.key; applyFilters()"
                                    class="rounded-xl border bg-surface-container-low/40 p-3 transition-all hover:-translate-y-0.5 hover:shadow-sm text-left"
                                    :class="localFilters.category && localFilters.category !== row.key
                                        ? 'opacity-40 border-outline-variant/40'
                                        : 'border-outline-variant/60 hover:border-secondary/40'"
                                    :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s;border-left:3px solid ${row.meta.accent}`">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="material-symbols-outlined text-[16px]" :style="`color:${row.meta.accent}`">{{ row.meta.icon }}</span>
                                    <span class="text-[11.5px] font-black text-primary truncate">{{ row.meta.label }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex-1 h-1.5 rounded-full bg-surface-container overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-700"
                                             :style="`width:${row.pct}%;background:${row.meta.accent}`"></div>
                                    </div>
                                    <span class="ml-3 text-[14px] font-black tabular-nums text-primary">{{ row.count }}</span>
                                </div>
                            </button>
                        </div>
                        <div v-else class="py-10 text-center text-[12px] font-medium text-on-surface-variant italic">No categorised reports yet.</div>
                    </div>
                </div>

                <!-- ── Cases register ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                    <!-- Filter row -->
                    <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                        </div>
                        <select aria-label="Status" v-model="localFilters.status" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All statuses</option>
                            <option v-for="(meta, k) in STATUS_META" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                        <select aria-label="Severity" v-model="localFilters.severity" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All severities</option>
                            <option v-for="(meta, k) in SEVERITY_META" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                        <select aria-label="Category" v-model="localFilters.category" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All categories</option>
                            <option v-for="(meta, k) in CATEGORY_META" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                        <button v-if="hasFilters" @click="clearFilters"
                                class="ml-auto rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">close</span>
                            Clear
                        </button>
                    </div>

                    <!-- Empty state -->
                    <div v-if="!reportRows.length" class="px-6 py-16">
                        <EmptyState title="No cases in your queue"
                                    description="When a new disclosure is filed by an employee or whistleblower, it will appear here for triage and assignment." />
                    </div>

                    <!-- Table -->
                    <div v-else class="canvas-scroll overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                <tr>
                                    <th class="px-6 py-3">Case</th>
                                    <th class="px-6 py-3">Category</th>
                                    <th class="px-6 py-3">Severity</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Received</th>
                                    <th class="px-6 py-3">Investigator</th>
                                    <th class="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/30">
                                <tr v-for="(r, idx) in reportRows" :key="r.id"
                                    class="hover:bg-surface-container-low/40 transition-colors"
                                    :style="`animation:slideUpFade 0.35s ease both;animation-delay:${idx*0.015}s;border-left:3px solid ${sevMeta(r.severity).accent};`">

                                    <td class="px-6 py-3">
                                        <div class="flex items-start gap-3">
                                            <div class="icon-tile flex-shrink-0" style="width:32px;height:32px;border-radius:8px"
                                                 :class="r.is_anonymous ? 'icon-navy' : 'icon-brand'">
                                                <span class="material-symbols-outlined text-[16px]">{{ r.is_anonymous ? 'visibility_off' : 'badge' }}</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-mono text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider">{{ r.case_number }}</p>
                                                <p class="text-[12.5px] font-bold text-primary truncate max-w-[28ch]" :title="r.subject_summary">{{ r.subject_summary }}</p>
                                                <p v-if="r.is_anonymous" class="text-[10px] text-cyan-700 dark:text-cyan-300 font-bold mt-0.5 flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[11px]">visibility_off</span>
                                                    Anonymous filing
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="inline-flex items-center gap-1.5 text-[12px] font-bold text-on-surface">
                                            <span class="material-symbols-outlined text-[14px]" :style="`color:${catMeta(r.category).accent}`">{{ catMeta(r.category).icon }}</span>
                                            {{ catMeta(r.category).label }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <span v-if="r.severity"
                                              class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                              :class="sevMeta(r.severity).cls">
                                            <span class="h-1.5 w-1.5 rounded-full" :style="`background:${sevMeta(r.severity).accent}`"></span>
                                            {{ sevMeta(r.severity).label }}
                                        </span>
                                        <span v-else class="text-[11px] text-on-surface-variant/40 italic">untriaged</span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <StatusPill :status="r.status" />
                                    </td>
                                    <td class="px-6 py-3">
                                        <p class="text-[12px] font-semibold text-on-surface">{{ formatDate(r.received_at) }}</p>
                                        <p v-if="ageDays(r.received_at) !== null" class="text-[10px] text-on-surface-variant/70 tabular-nums">
                                            {{ ageDays(r.received_at) }}d ago
                                        </p>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div v-if="r.investigator" class="flex items-center gap-2">
                                            <div class="h-7 w-7 rounded-full bg-secondary/10 flex items-center justify-center text-[10px] font-black text-secondary flex-shrink-0">
                                                {{ initials(r.investigator.name) }}
                                            </div>
                                            <span class="text-[12px] font-semibold text-on-surface truncate max-w-[14ch]">{{ r.investigator.name }}</span>
                                        </div>
                                        <span v-else class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 dark:text-amber-300">
                                            <span class="material-symbols-outlined text-[12px]">person_off</span>
                                            Unassigned
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-right">
                                        <Link :href="route('whistleblower.admin.show', r.id)"
                                              class="inline-flex items-center gap-1 text-[11.5px] font-black text-secondary hover:text-secondary-container transition-colors">
                                            Open
                                            <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="reports?.meta?.links?.length > 3" class="px-6 py-3 border-t border-outline-variant/40 flex items-center justify-between flex-wrap gap-2">
                        <p class="text-[11.5px] text-on-surface-variant">
                            Showing <span class="font-bold text-primary">{{ reports?.meta?.from ?? 0 }}</span>
                            – <span class="font-bold text-primary">{{ reports?.meta?.to ?? 0 }}</span>
                            of <span class="font-bold text-primary">{{ reports?.meta?.total ?? 0 }}</span>
                        </p>
                        <Pagination :links="reports?.meta?.links ?? []" />
                    </div>
                </div>

                <!-- ── Confidentiality footer ── -->
                <div class="rounded-xl border border-outline-variant/40 bg-surface-container-low/40 px-5 py-3 flex items-center gap-3 text-[11.5px] text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] text-rose-600 flex-shrink-0">shield_lock</span>
                    <p class="leading-relaxed">
                        <span class="font-bold text-on-surface">Confidential.</span>
                        Every action you take on a case is audit-logged with your identity and timestamp under the
                        <span class="font-bold">Whistleblower Protection Act 2006 (Act 720)</span>. Reporter identity
                        must never be disclosed outside the investigator chain.
                    </p>
                </div>
            </div>

    </div>
</template>
