<script setup>
import { reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import GlossaryText from '@/Components/GlossaryText.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    requests:         Object,
    stats:            { type: Object, default: () => ({}) },
    typeBreakdown:    { type: Object, default: () => ({}) },
    statusBreakdown:  { type: Object, default: () => ({}) },
    filters:          Object,
});

const requestRows = computed(() => props.requests?.data ?? []);

// ── Filters ──
const localFilters = reactive({
    status:       props.filters?.status       ?? '',
    request_type: props.filters?.request_type ?? props.filters?.type ?? '',
});

const applyFilters = () => router.get(route('privacy.admin.index'), {
    status:       localFilters.status       || undefined,
    request_type: localFilters.request_type || undefined,
}, { preserveState: true, replace: true });

const hasFilters = computed(() => localFilters.status || localFilters.request_type);
const clearFilters = () => { localFilters.status = ''; localFilters.request_type = ''; applyFilters(); };

// ── DSR type metadata (palette-keyed) ──
const TYPE_META = {
    access:        { label: 'Access',        accent: '#12d9e3', icon: 'visibility',     tile: 'icon-cyan',    desc: 'Get a copy of personal data' },
    rectification: { label: 'Rectification', accent: '#1a237e', icon: 'edit',           tile: 'icon-brand',   desc: 'Correct inaccurate data' },
    erasure:       { label: 'Erasure',       accent: '#d912e3', icon: 'delete_forever', tile: 'icon-magenta', desc: 'Right to be forgotten' },
    portability:   { label: 'Portability',   accent: '#7986cb', icon: 'download',       tile: 'icon-sky',     desc: 'Export to another controller' },
    objection:     { label: 'Objection',     accent: '#ffd700', icon: 'block',          tile: 'icon-gold',    desc: 'Object to processing' },
    information:   { label: 'Information',   accent: '#3949ab', icon: 'info',           tile: 'icon-brand',   desc: 'Information request' },
};
const typeMeta = (k) => TYPE_META[k] ?? { label: k ?? '—', accent: '#7986cb', icon: 'description', tile: 'icon-sky', desc: '' };

// ── Status metadata ──
const STATUS_META = {
    submitted:           { label: 'Submitted',      cls: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#ffd700', icon: 'inbox' },
    acknowledged:        { label: 'Acknowledged',   cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',     dot: '#12d9e3', icon: 'mark_email_read' },
    in_review:           { label: 'In review',      cls: 'bg-blue-50 text-blue-700 border-blue-200',     dot: '#1a237e', icon: 'manage_search' },
    overdue:             { label: 'Overdue',        cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#dc2626', icon: 'priority_high' },
    fulfilled:           { label: 'Fulfilled',      cls: 'bg-green-50 text-green-700 border-green-200',  dot: '#059669', icon: 'check_circle' },
    partially_fulfilled: { label: 'Partial',        cls: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#d97706', icon: 'incomplete_circle' },
    rejected:            { label: 'Rejected',       cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#d912e3', icon: 'cancel' },
    withdrawn:           { label: 'Withdrawn',      cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b', icon: 'undo' },
};
const statusMeta = (s) => STATUS_META[s] ?? { label: s ?? '—', cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b', icon: 'circle' };

// ── Type bars data ──
const typeBars = computed(() => {
    const entries = Object.entries(props.typeBreakdown ?? {});
    if (!entries.length) return [];
    const total = entries.reduce((s, [, v]) => s + v, 0);
    return Object.keys(TYPE_META).map(k => ({
        key: k,
        count: Number(props.typeBreakdown?.[k] ?? 0),
        pct: total > 0 ? Math.round((props.typeBreakdown?.[k] ?? 0) / total * 100) : 0,
        meta: typeMeta(k),
    })).filter(r => r.count > 0).sort((a, b) => b.count - a.count);
});

// ── SLA ring ──
const circumference = 2 * Math.PI * 42;
const slaDash = computed(() => ((props.stats?.within_sla_pct ?? 100) / 100) * circumference);
const slaColor = computed(() => {
    const p = props.stats?.within_sla_pct ?? 100;
    if (p >= 90) return '#12d9e3';
    if (p >= 70) return '#1a237e';
    if (p >= 40) return '#ffd700';
    return '#dc2626';
});

const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length-1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

const daysRemaining = (target) => {
    if (!target) return null;
    const d = Math.floor((new Date(target).getTime() - Date.now()) / 86400000);
    return d;
};

// ── Editorial-Sovereign masthead label ──
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
        date: d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});
</script>

<template>
    <Head title="DPA Requests — DPO Queue" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">shield_lock</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80"><GlossaryText text="DPO QUEUE · ACT 843" /></p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight"><GlossaryText text="DPA Requests — DPO Queue" /></h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Statutory subject requests — access, rectification, erasure, objection — adjudicated within 30 days per Act 843 §22.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="localFilters.status = 'overdue'; applyFilters()"
                                class="flex items-center gap-2 rounded-xl border border-outline-variant/50 bg-surface-container-lowest px-4 py-2.5 text-[13px] font-black text-primary shadow-card transition-all hover:-translate-y-px hover:shadow-card-hover">
                            <span class="material-symbols-outlined text-[17px]">priority_high</span>
                            Overdue
                        </button>
                        <button @click="localFilters.status = 'submitted'; applyFilters()"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">inbox</span>
                            Triage New
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- ── Overdue alert banner ── -->
                <div v-if="stats?.overdue > 0"
                     class="rounded-2xl border-2 border-rose-300 bg-gradient-to-r from-rose-50 to-amber-50/40 dark:from-rose-900/15 dark:to-amber-900/10 dark:border-rose-800/40 p-5 flex items-center justify-between gap-4 animate-reveal-up">
                    <div class="flex items-center gap-3">
                        <div class="icon-tile icon-danger flex-shrink-0">
                            <span class="material-symbols-outlined">priority_high</span>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-300"><GlossaryText text="SLA breach — Act 843 §22" /></p>
                            <p class="text-[13.5px] font-black text-rose-900 dark:text-rose-200 mt-0.5">
                                {{ stats.overdue }} request{{ stats.overdue === 1 ? '' : 's' }} past the 30-day statutory window — fulfil or formally reject with statutory basis.
                            </p>
                        </div>
                    </div>
                    <button @click="localFilters.status = 'overdue'; applyFilters()"
                            class="rounded-xl bg-white dark:bg-rose-900/30 border border-rose-300 dark:border-rose-700/50 px-4 py-2 text-[12px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/50 transition-colors flex-shrink-0">
                        Show overdue
                    </button>
                </div>

                <!-- ── Visual band: SLA ring + type composition ── -->
                <div class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                    <!-- SLA compliance ring -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary"><GlossaryText text="SLA compliance" /></h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">30-day window</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-4">% of open requests still within the statutory window.</p>

                        <div class="flex items-center justify-center relative my-2 flex-1">
                            <svg viewBox="0 0 100 100" width="180" height="180" class="-rotate-90">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(var(--ct-surface-low))" stroke-width="9"/>
                                <circle cx="50" cy="50" r="42" fill="none"
                                        :stroke="slaColor" stroke-width="9" stroke-linecap="round"
                                        :stroke-dasharray="`${slaDash} ${circumference}`"
                                        style="transition: stroke-dasharray 0.7s cubic-bezier(0.22,1,0.36,1), stroke 0.3s ease;"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70"><GlossaryText text="Within SLA" /></p>
                                <p class="text-3xl font-black tabular-nums text-primary leading-none">{{ stats?.within_sla_pct ?? 100 }}%</p>
                                <p class="mt-0.5 text-[9.5px] font-bold text-on-surface-variant/70">
                                    avg age {{ stats?.avg_age_days ?? 0 }}d
                                </p>
                            </div>
                        </div>

                        <!-- Legal footer -->
                        <div class="mt-3 pt-3 border-t border-outline-variant/40 text-center">
                            <p class="text-[10.5px] font-bold text-on-surface-variant flex items-center justify-center gap-1.5">
                                <span class="material-symbols-outlined text-[14px] text-cyan-600">gavel</span>
                                Act 843 §22 · 30-day response
                            </p>
                        </div>
                    </div>

                    <!-- Type breakdown (spans 2/3) -->
                    <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Requests by type</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Click to filter</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-5">Composition of all-time data-subject requests.</p>

                        <div v-if="typeBars.length" class="space-y-3 flex-1">
                            <button v-for="(row, i) in typeBars" :key="row.key"
                                    @click="localFilters.request_type = localFilters.request_type === row.key ? '' : row.key; applyFilters()"
                                    class="w-full group flex items-center gap-3 transition-all"
                                    :class="localFilters.request_type && localFilters.request_type !== row.key ? 'opacity-40' : ''"
                                    :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s`">
                                <span class="w-36 flex-shrink-0 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[15px]" :style="`color:${row.meta.accent}`">{{ row.meta.icon }}</span>
                                    <div class="min-w-0">
                                        <p class="text-[12px] font-bold text-on-surface leading-tight">{{ row.meta.label }}</p>
                                        <p class="text-[9.5px] text-on-surface-variant/70 leading-tight truncate">{{ row.meta.desc }}</p>
                                    </div>
                                </span>
                                <div class="flex-1 h-6 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                    <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-2.5"
                                         :style="`width:${row.pct}%;background:linear-gradient(90deg,${row.meta.accent}cc,${row.meta.accent})`">
                                        <span class="text-[10px] font-black text-white tabular-nums">{{ row.count }}</span>
                                    </div>
                                </div>
                                <span class="w-10 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ row.pct }}%</span>
                            </button>
                        </div>
                        <div v-else class="py-12 text-center text-[12px] font-medium text-on-surface-variant italic">No requests recorded yet.</div>
                    </div>
                </div>

                <!-- ── Request register ── -->
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
                        <select aria-label="Request type" v-model="localFilters.request_type" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All types</option>
                            <option v-for="(meta, k) in TYPE_META" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                        <button v-if="hasFilters" @click="clearFilters"
                                class="ml-auto rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">close</span>
                            Clear
                        </button>
                    </div>

                    <!-- Empty -->
                    <div v-if="!requestRows.length" class="px-6 py-16">
                        <EmptyState title="Queue clear"
                                    description="When subjects submit data-subject requests, they appear here for triage and fulfilment." />
                    </div>

                    <!-- Table -->
                    <div v-else class="canvas-scroll overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                <tr>
                                    <th class="px-6 py-3">Reference / subject</th>
                                    <th class="px-6 py-3">Type</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Submitted</th>
                                    <th class="px-6 py-3">Target</th>
                                    <th class="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/30">
                                <tr v-for="(r, idx) in requestRows" :key="r.id"
                                    class="hover:bg-surface-container-low/40 transition-colors"
                                    :class="r.is_overdue ? 'bg-rose-50/30 dark:bg-rose-900/10' : ''"
                                    :style="`animation:slideUpFade 0.35s ease both;animation-delay:${idx*0.015}s;border-left:3px solid ${typeMeta(r.request_type).accent};`">

                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="h-9 w-9 rounded-full bg-secondary/10 flex items-center justify-center text-[11px] font-black text-secondary flex-shrink-0">
                                                {{ initials(r.subject?.name) }}
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-mono text-[10.5px] font-bold text-on-surface-variant/70 uppercase tracking-wider">{{ r.reference }}</p>
                                                <p class="text-[12.5px] font-bold text-primary truncate max-w-[24ch]">{{ r.subject?.name ?? '—' }}</p>
                                                <p class="text-[10.5px] text-on-surface-variant/70 truncate max-w-[24ch]">{{ r.subject?.email ?? '' }}</p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-3">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                              :style="`background:${typeMeta(r.request_type).accent}1a;color:${typeMeta(r.request_type).accent};border-color:${typeMeta(r.request_type).accent}40`">
                                            <span class="material-symbols-outlined text-[12px]">{{ typeMeta(r.request_type).icon }}</span>
                                            {{ typeMeta(r.request_type).label }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-3">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                              :class="statusMeta(r.status).cls">
                                            <span class="material-symbols-outlined text-[11px]">{{ statusMeta(r.status).icon }}</span>
                                            {{ statusMeta(r.status).label }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-3 text-[12px] font-semibold text-on-surface-variant">
                                        {{ r.submitted_at ? new Date(r.submitted_at).toLocaleDateString('en-GH') : '—' }}
                                    </td>

                                    <td class="px-6 py-3">
                                        <div class="space-y-0.5">
                                            <p class="text-[12px] font-semibold tabular-nums"
                                               :class="r.is_overdue ? 'text-rose-700 font-black' : 'text-on-surface'">{{ r.target_completion_date }}</p>
                                            <p v-if="!r.status_is_terminal && r.target_completion_date" class="text-[10px] font-bold tabular-nums"
                                               :class="r.is_overdue
                                                  ? 'text-rose-600 dark:text-rose-400'
                                                  : (daysRemaining(r.target_completion_date) <= 5 ? 'text-amber-600 dark:text-amber-400' : 'text-on-surface-variant/70')">
                                                <span v-if="r.is_overdue">{{ Math.abs(daysRemaining(r.target_completion_date) ?? 0) }}d overdue</span>
                                                <span v-else>{{ daysRemaining(r.target_completion_date) }}d remaining</span>
                                            </p>
                                        </div>
                                    </td>

                                    <td class="px-6 py-3 text-right">
                                        <Link :href="route('privacy.admin.show', r.id)"
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
                    <div v-if="requests?.meta?.links?.length > 3" class="px-6 py-3 border-t border-outline-variant/40 flex items-center justify-between flex-wrap gap-2">
                        <p class="text-[11.5px] text-on-surface-variant">
                            Showing <span class="font-bold text-primary">{{ requests?.meta?.from ?? 0 }}</span>
                            – <span class="font-bold text-primary">{{ requests?.meta?.to ?? 0 }}</span>
                            of <span class="font-bold text-primary">{{ requests?.meta?.total ?? 0 }}</span>
                        </p>
                        <Pagination :links="requests?.meta?.links ?? []" />
                    </div>
                </div>

                <!-- ── Legal footer ── -->
                <div class="rounded-xl border border-outline-variant/40 bg-surface-container-low/40 px-5 py-3 flex items-center gap-3 text-[11.5px] text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] text-cyan-600 flex-shrink-0">privacy_tip</span>
                    <p class="leading-relaxed">
                        <span class="font-bold text-on-surface">Data Protection Act 2012 (Act 843).</span>
                        Every decision is audit-logged with your identity, the statutory basis (when applicable), and the decision summary that's surfaced back to the subject. Erasure fulfilments require dual-control with privileged authorisation.
                    </p>
                </div>
            </div>

    </div>
</template>
