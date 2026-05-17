<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    pips:         Object, // paginated: { data: [], links: [], meta: {} }
    stats:        Object, // { open_total, succeeded_ytd, terminated_ytd }
    filters:      Object, // { status }
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const localFilters = reactive({
    status: props.filters?.status ?? '',
});

const applyFilters = () => {
    router.get(route('performance.pips.index'), {
        status: localFilters.status || undefined,
    }, { preserveState: true, replace: true });
};

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const pipList = computed(() => props.pips?.data ?? []);

const inProgress = computed(() => pipList.value.filter(p => p.status === 'in_progress').length);

// Stat cards — Succeeded (YTD) gets gold (institutional improvement success metric)
const statCards = computed(() => [
    { label: 'Open PIPs',             value: props.stats?.open_total     ?? 0, icon: 'warning',         rgb: '217,119,6'  },
    { label: 'In Progress',           value: inProgress.value,                 icon: 'hourglass_top',   rgb: '26, 35, 126'  },
    { label: 'Succeeded (YTD)',       value: props.stats?.succeeded_ytd  ?? 0, icon: 'check_circle',    rgb: '255,215,0'  },
    { label: 'Terminated (YTD)',      value: props.stats?.terminated_ytd ?? 0, icon: 'person_off',      rgb: '220,38,38'  },
]);

// â”€â”€ New PIP panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showAddPanel = ref(false);

const form = useForm({
    employee_id:   '',
    mentor_id:     '',
    duration_days: '90',
    target_metrics: '',
});

const submitPip = () => {
    const payload = {
        employee_id:    form.employee_id,
        mentor_id:      form.mentor_id || undefined,
        duration_days:  parseInt(form.duration_days) || 90,
        target_metrics: (() => {
            try { return form.target_metrics ? JSON.parse(form.target_metrics) : []; }
            catch { return []; }
        })(),
    };
    router.post(route('performance.pips.store'), payload, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showAddPanel.value = false;
        },
    });
};

// â”€â”€ Status config â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Status config — borders aligned with brand palette where appropriate.
// in_progress = cobalt (action). extended = magenta (people-side process).
const statusConfig = {
    // Borders use the brand palette. Pills use semantic tailwind families
    // that already pair with brand hex (gold/cyan/magenta/sky).
    open:             { pill: 'bg-amber-50 text-amber-700 border-amber-200',     border: '#ffd700', label: 'Open'                  },
    in_progress:      { pill: 'bg-blue-50 text-blue-700 border-blue-200',        border: '#1a237e', label: 'In Progress'           },
    extended:         { pill: 'bg-rose-50 text-rose-700 border-rose-200',        border: '#d912e3', label: 'Extended'              },
    succeeded:        { pill: 'bg-emerald-50 text-emerald-700 border-emerald-200', border: '#059669', label: 'Succeeded'           },
    failed_demoted:   { pill: 'bg-amber-50 text-amber-700 border-amber-200',     border: '#d97706', label: 'Failed – Demoted'      },
    failed_terminated:{ pill: 'bg-rose-50 text-rose-700 border-rose-200',        border: '#dc2626', label: 'Failed – Terminated'   },
    cancelled:        { pill: 'bg-slate-100 text-slate-600 border-slate-200',    border: '#64748b', label: 'Cancelled'             },
};

const getStatusCfg = (status) => statusConfig[status] ?? { pill: 'bg-surface-container text-on-surface-variant', border: '#9ca3af', label: status };

// â”€â”€ Progress helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const daysElapsed = (openedOn, targetEnd) => {
    if (!openedOn) return 0;
    const start = new Date(openedOn).getTime();
    const now   = Date.now();
    return Math.max(0, Math.floor((now - start) / 86400000));
};

const totalDays = (openedOn, targetEnd) => {
    if (!openedOn || !targetEnd) return 90;
    const start = new Date(openedOn).getTime();
    const end   = new Date(targetEnd).getTime();
    return Math.max(1, Math.floor((end - start) / 86400000));
};

const progressPct = (pip) => {
    const elapsed = daysElapsed(pip.opened_on, pip.target_end_date);
    const total   = totalDays(pip.opened_on, pip.target_end_date);
    return Math.min(100, Math.round((elapsed / total) * 100));
};

const progressColor = (pip) => {
    const pct = progressPct(pip);
    const isPastDue = pip.target_end_date && new Date(pip.target_end_date) < new Date();
    if (isPastDue) return '#dc2626';
    if (pct >= 75) return '#d97706';
    return '#1a237e';
};

// â”€â”€ Avatar helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Avatar gradient pool — disciplined cool family
const gradients = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
const avatarGradient = (id) => gradients[(id ?? 0) % gradients.length];
const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

const formatDate = (d) => {
    if (!d) return 'â€”';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const isPastDue = (pip) => pip.target_end_date && new Date(pip.target_end_date) < new Date() && !['succeeded', 'failed_demoted', 'failed_terminated', 'cancelled'].includes(pip.status);

// ── Editorial Sovereign · masthead helpers ──────────────────────────
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

// ── Editorial sub-metrics derived from the loaded page ──────────────
const closingThisMonth = computed(() => {
    const now = new Date();
    const m = now.getMonth();
    const y = now.getFullYear();
    return pipList.value.filter(p => {
        if (!p.target_end_date) return false;
        const d = new Date(p.target_end_date);
        return d.getMonth() === m && d.getFullYear() === y
            && !['succeeded', 'failed_demoted', 'failed_terminated', 'cancelled'].includes(p.status);
    }).length;
});

const extensionsUsed = computed(() =>
    pipList.value.reduce((sum, p) => sum + (p.extensions_used ?? 0), 0)
);

const outcomeMix = computed(() => {
    const succ = props.stats?.succeeded_ytd ?? 0;
    const term = props.stats?.terminated_ytd ?? 0;
    const total = succ + term;
    if (!total) return '—';
    const pct = Math.round((succ / total) * 100);
    return `${pct}%`;
});
</script>

<template>
    <Head title="Performance Improvement Plans" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- ── Editorial Sovereign header ───────────────────────────── -->
        <template #header>
            <section class="space-y-8">

                <!-- Masthead strip -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">PERFORMANCE — PIP REGISTER</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Register · Live
                    </span>
                </div>

                <!-- Broadsheet hero -->
                <div class="es-broadsheet rounded-none">
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Workforce remediation</p>
                        <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                            Improvement plans,
                            <span class="es-display-italic block">on the record.</span>
                        </h2>
                        <p class="es-display-sub">
                            Formal 60–90 day plans opened under Labour Act §63 — tracked from
                            opening conversation through mentored check-ins, extensions, and
                            outcome closure. Every step is dated, signed, and admissible.
                        </p>

                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <Link :href="route('modules.performance')" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">arrow_back</span>
                                Performance
                            </Link>
                            <span class="es-chip-divider">·</span>
                            <button
                                v-if="canManage"
                                @click="showAddPanel = true"
                                class="es-chip"
                            >
                                <span class="material-symbols-outlined text-[15px]">add</span>
                                Open PIP
                            </button>
                        </div>
                    </div>

                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Active register</p>
                            <p class="es-stat-hero-value">{{ props.stats?.open_total ?? 0 }}</p>
                            <p class="es-stat-hero-caption">
                                Plans open · {{ inProgress }} in progress
                            </p>
                            <span
                                class="es-stat-hero-delta"
                                :class="{ 'is-down': (props.stats?.terminated_ytd ?? 0) > (props.stats?.succeeded_ytd ?? 0) }"
                            >
                                <span class="material-symbols-outlined text-[13px]">
                                    {{ (props.stats?.succeeded_ytd ?? 0) >= (props.stats?.terminated_ytd ?? 0) ? 'trending_up' : 'trending_down' }}
                                </span>
                                {{ outcomeMix }} succeed-rate · YTD
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Sub-metric strip -->
                <div class="es-stat-strip rounded-none">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Active PIPs</p>
                        <p class="es-stat-cell-value">{{ props.stats?.open_total ?? 0 }}</p>
                        <p class="es-stat-cell-caption">On the register</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Extensions used</p>
                        <p class="es-stat-cell-value">{{ extensionsUsed }}</p>
                        <p class="es-stat-cell-caption">Across visible cases</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Closing this month</p>
                        <p class="es-stat-cell-value">{{ closingThisMonth }}</p>
                        <p class="es-stat-cell-caption">Target end nearing</p>
                    </div>
                    <div class="es-stat-cell" :class="{ 'es-stat-cell--down': (props.stats?.terminated_ytd ?? 0) > (props.stats?.succeeded_ytd ?? 0) }">
                        <p class="es-stat-cell-label">Outcome · YTD</p>
                        <p class="es-stat-cell-value">
                            {{ props.stats?.succeeded_ytd ?? 0 }}<span class="es-stat-unit">/{{ (props.stats?.succeeded_ytd ?? 0) + (props.stats?.terminated_ytd ?? 0) }}</span>
                        </p>
                        <p class="es-stat-cell-caption">Succeeded · terminated</p>
                    </div>
                </div>
            </section>
        </template>

        <div class="space-y-6">

            <!-- â”€â”€ Stat cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div
                    v-for="(card, i) in statCards"
                    :key="card.label"
                    class="group relative rounded-2xl border bg-surface-container-lowest p-4 shadow-card card-lift overflow-hidden"
                    :style="`border-color:rgba(${card.rgb},0.20);animation-delay:${i * 0.06}s`"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">{{ card.label }}</p>
                            <p class="mt-1.5 text-[1.6rem] font-black leading-none tracking-tight text-on-surface">{{ card.value }}</p>
                        </div>
                        <span
                            class="material-symbols-outlined flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-[20px]"
                            :style="`color:rgb(${card.rgb});background:rgba(${card.rgb},0.10);font-variation-settings:'FILL' 1`"
                        >{{ card.icon }}</span>
                    </div>
                    <div
                        class="absolute inset-x-0 bottom-0 h-[3px] rounded-b-2xl opacity-40"
                        :style="`background:rgb(${card.rgb})`"
                    ></div>
                </div>
            </div>

            <!-- â”€â”€ Filter strip â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="flex flex-wrap items-center gap-3">
                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="extended">Extended</option>
                    <option value="succeeded">Succeeded</option>
                    <option value="failed_demoted">Failed â€” Demoted</option>
                    <option value="failed_terminated">Failed â€” Terminated</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <button
                    v-if="localFilters.status"
                    @click="() => { localFilters.status = ''; applyFilters(); }"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- â”€â”€ PIP cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="pipList.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No PIPs found"
                    description="Performance Improvement Plans will appear here once HR opens them for underperforming employees."
                    icon="warning"
                >
                    <template #action>
                        <button
                            v-if="canManage"
                            @click="showAddPanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Open PIP
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="pip in pipList"
                    :key="pip.id"
                    class="group relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden transition-all hover:shadow-lifted hover:-translate-y-0.5"
                >
                    <!-- Severity left border -->
                    <div
                        class="absolute inset-y-0 left-0 w-1 rounded-l-2xl"
                        :style="`background:${getStatusCfg(pip.status).border}`"
                    ></div>

                    <div class="pl-5 pr-5 pt-5 pb-4">
                        <!-- Employee + status -->
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="h-9 w-9 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                    :style="`background:${avatarGradient(pip.employee?.id)}`"
                                >{{ initials(pip.employee?.name) }}</div>
                                <div class="min-w-0">
                                    <p class="text-[14px] font-bold text-on-surface leading-tight truncate">{{ pip.employee?.name ?? 'â€”' }}</p>
                                    <p class="text-[11px] text-on-surface-variant/60 leading-tight">
                                        {{ pip.employee?.employee_no }}
                                        <span v-if="pip.employee?.department" class="ml-1">Â· {{ pip.employee.department }}</span>
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
                                :class="getStatusCfg(pip.status).pill"
                            >{{ pip.status_label ?? pip.status }}</span>
                        </div>

                        <!-- Date range + overdue indicator -->
                        <div class="flex items-center gap-2 text-[11px] text-on-surface-variant mb-3">
                            <span class="material-symbols-outlined text-[14px]">date_range</span>
                            <span>{{ formatDate(pip.opened_on) }}</span>
                            <span class="text-on-surface-variant/40">â†’</span>
                            <span :class="isPastDue(pip) ? 'font-bold text-rose-600' : ''">{{ formatDate(pip.target_end_date) }}</span>
                            <span v-if="isPastDue(pip)" class="inline-flex items-center gap-0.5 rounded-md bg-rose-500/10 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider text-rose-600">
                                <span class="material-symbols-outlined text-[11px]">schedule</span>
                                Overdue
                            </span>
                        </div>

                        <!-- Progress bar -->
                        <div class="mb-3">
                            <div class="flex items-center justify-between text-[10px] font-semibold text-on-surface-variant/60 mb-1">
                                <span>Days elapsed</span>
                                <span class="font-mono text-on-surface">
                                    {{ daysElapsed(pip.opened_on, pip.target_end_date) }}
                                    / {{ totalDays(pip.opened_on, pip.target_end_date) }} days
                                </span>
                            </div>
                            <div class="h-2 rounded-full bg-surface-container overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-700"
                                    :style="`width:${progressPct(pip)}%;background:${progressColor(pip)}`"
                                ></div>
                            </div>
                        </div>

                        <!-- Mentor + metrics row -->
                        <div class="flex items-center gap-4 mb-4">
                            <div v-if="pip.mentor?.name" class="flex items-center gap-1.5">
                                <div
                                    class="h-5 w-5 flex-shrink-0 rounded-full flex items-center justify-center text-[8px] font-black text-white"
                                    :style="`background:${avatarGradient(pip.mentor?.id)}`"
                                >{{ initials(pip.mentor.name) }}</div>
                                <span class="text-[11px] text-on-surface-variant">Mentor: <span class="font-semibold text-on-surface">{{ pip.mentor.name }}</span></span>
                            </div>
                            <div v-if="pip.extensions_used > 0" class="flex items-center gap-1 text-[11px]" style="color:#d912e3">
                                <span class="material-symbols-outlined text-[13px]">add_circle</span>
                                {{ pip.extensions_used }} extension{{ pip.extensions_used !== 1 ? 's' : '' }}
                            </div>
                        </div>

                        <!-- Metrics count + action -->
                        <div class="flex items-center justify-between border-t border-outline-variant/40 pt-3">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center gap-1 rounded-lg bg-surface-container px-2 py-0.5 text-[11px] font-semibold text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[13px]">checklist</span>
                                    {{ pip.target_metrics?.length ?? 0 }} metrics
                                </span>
                                <span
                                    v-if="(pip.checkins?.length ?? 0) > 0"
                                    class="inline-flex items-center gap-1 rounded-lg bg-secondary/10 px-2 py-0.5 text-[11px] font-semibold text-secondary"
                                >
                                    <span class="material-symbols-outlined text-[13px]">check_box</span>
                                    {{ pip.checkins.length }} check-ins
                                </span>
                            </div>
                            <Link
                                :href="route('performance.pips.show', pip.id)"
                                class="flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] font-bold text-primary hover:bg-surface-container-low transition-colors"
                            >
                                <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                                Open
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="pips?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing
                    <span class="font-semibold text-on-surface">{{ pips.meta?.from }}</span>
                    â€“
                    <span class="font-semibold text-on-surface">{{ pips.meta?.to }}</span>
                    of
                    <span class="font-semibold text-on-surface">{{ pips.meta?.total }}</span>
                </p>
                <Pagination :links="pips.links" />
            </div>
        </div>

        <!-- â”€â”€ Open PIP SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showAddPanel"
            title="Open Performance Improvement Plan"
            size="lg"
            @close="showAddPanel = false"
        >
            <form @submit.prevent="submitPip" class="space-y-5 p-6">

                <div class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-4">
                    <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.18em] text-rose-700 mb-1">
                        <span class="material-symbols-outlined text-[15px]">warning</span>
                        HR Action â€” Sensitive
                    </div>
                    <p class="text-[12px] text-on-surface-variant/70">
                        Opening a PIP is a formal process under Labour Act Â§63. Ensure manager conversation has occurred and documentation is complete.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee ID <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.employee_id"
                            type="number"
                            placeholder="Employee database ID"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Mentor / HR Partner ID</label>
                        <input
                            v-model="form.mentor_id"
                            type="number"
                            placeholder="Optional â€” mentor employee ID"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Duration (days) <span class="text-red-500">*</span></label>
                    <div class="flex items-center gap-3">
                        <button
                            v-for="d in ['60', '90']"
                            :key="d"
                            type="button"
                            @click="form.duration_days = d"
                            class="flex-1 rounded-xl border-2 py-2.5 text-[13px] font-bold transition-all"
                            :class="form.duration_days === d
                                ? 'border-secondary/50 bg-secondary/10 text-secondary'
                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-container'"
                        >{{ d }} days</button>
                        <input
                            v-model="form.duration_days"
                            type="number"
                            min="30"
                            max="180"
                            placeholder="Custom"
                            class="w-24 rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-center text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Target Metrics <span class="text-on-surface-variant/50 font-normal">(JSON array)</span>
                    </label>
                    <textarea
                        v-model="form.target_metrics"
                        rows="5"
                        placeholder='[{"metric":"Attendance","target":"95%"},{"metric":"Report quality","target":"No rewrites"}]'
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[12px] font-mono text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showAddPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >Cancel</button>
                    <button
                        @click="submitPip"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[16px]">assignment</span>
                        Open PIP
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
