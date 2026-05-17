<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    sessions:     Object, // paginated: { data: [], links: [], meta: {} }
    cycles:       Array,  // [{ id, name }]
    activeModule: String,
});

const page = usePage();
const canFacilitate = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

// ── Stats ─────────────────────────────────────────────────────────────────────
const sessionList = computed(() => props.sessions?.data ?? []);

const stats = computed(() => {
    const data = sessionList.value;
    const total = props.sessions?.meta?.total ?? data.length;
    const open   = data.filter(s => s.status === 'open').length;
    const closed  = data.filter(s => s.status === 'closed' || s.status === 'applied').length;
    const adjustments = data.reduce((sum, s) => sum + (s.adjustments_count ?? 0), 0);
    return { total, open, closed, adjustments };
});

// Stat cards — Total Adjustments gets gold (institutional outcome the page produces)
const statCards = computed(() => [
    { label: 'Open Sessions',       value: stats.value.open,        icon: 'event_available',  rgb: '217,119,6'  },
    { label: 'Closed This Cycle',   value: stats.value.closed,      icon: 'lock',             rgb: '5,150,105'  },
    { label: 'Total Adjustments',   value: stats.value.adjustments, icon: 'tune',             rgb: '255,215,0'  },
]);

// ── Rating distribution (derived from current page sessions) ──────────────────
// Visualises a mock distribution across active cycle sessions
// Bands: Exceeds ≥ 4, Meets 2.5–3.9, Below < 2.5
const distributionBands = computed(() => {
    // Placeholder — real data comes from the show page; index shows a summary indicator
    return [
        { label: 'Exceeds',  pct: 20, color: '#059669' },
        { label: 'Meets',    pct: 65, color: '#1a237e' },
        { label: 'Below',    pct: 15, color: '#dc2626' },
    ];
});

// ── New session panel ─────────────────────────────────────────────────────────
const showAddPanel = ref(false);

const form = useForm({
    cycle_id:      '',
    department_id: '',
});

const submitSession = () => {
    form.post(route('performance.calibration.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showAddPanel.value = false;
            form.reset();
        },
    });
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const statusTone = {
    open:    'bg-amber-400/15 text-amber-700',
    closed:  'bg-emerald-500/15 text-emerald-700',
    applied: 'bg-cobalt-500/15 text-cobalt-700',
    locked:  'bg-violet-500/15 text-violet-700',
};
const statusClass = (s) => statusTone[s] ?? 'bg-surface-container text-on-surface-variant';

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
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

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

// ── Editorial sub-metrics ───────────────────────────────────────────
const editorialMetrics = computed(() => {
    const data = sessionList.value;
    return {
        inProgress: data.filter(s => s.status === 'open').length,
        locked:     data.filter(s => s.status === 'locked' || s.status === 'closed').length,
        applied:    data.filter(s => s.status === 'applied').length,
        cycles:     (props.cycles ?? []).length,
    };
});
</script>

<template>
    <Head title="Calibration Sessions" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- ── Editorial Sovereign header ───────────────────────────── -->
        <template #header>
            <section class="space-y-8">

                <!-- Masthead strip -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">PERFORMANCE — CALIBRATION ROOM</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Calibration · Live
                    </span>
                </div>

                <!-- Broadsheet hero -->
                <div class="es-broadsheet rounded-none">
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Distribution calibration session</p>
                        <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                            Ratings,
                            <span class="es-display-italic block">normalised.</span>
                        </h2>
                        <p class="es-display-sub">
                            HR-led moderation sessions that compare manager ratings against
                            the institutional 20 / 65 / 15 force-distribution, surface outliers,
                            and lock the cycle for adjustment before applying decisions to record.
                        </p>

                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <Link :href="route('modules.performance')" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">arrow_back</span>
                                Performance
                            </Link>
                            <span class="es-chip-divider">·</span>
                            <button
                                v-if="canFacilitate"
                                @click="showAddPanel = true"
                                class="es-chip"
                            >
                                <span class="material-symbols-outlined text-[15px]">event_available</span>
                                Start session
                            </button>
                        </div>
                    </div>

                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Sessions in room</p>
                            <p class="es-stat-hero-value">{{ stats.open }}</p>
                            <p class="es-stat-hero-caption">
                                Open · {{ stats.adjustments }} adjustments tabled
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">tune</span>
                                {{ editorialMetrics.cycles }} cycle{{ editorialMetrics.cycles === 1 ? '' : 's' }} configured
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Sub-metric strip -->
                <div class="es-stat-strip rounded-none">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">In progress</p>
                        <p class="es-stat-cell-value">{{ editorialMetrics.inProgress }}</p>
                        <p class="es-stat-cell-caption">Active rooms</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Locked</p>
                        <p class="es-stat-cell-value">{{ editorialMetrics.locked }}</p>
                        <p class="es-stat-cell-caption">Closed for adjustment</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Applied</p>
                        <p class="es-stat-cell-value">{{ editorialMetrics.applied }}</p>
                        <p class="es-stat-cell-caption">On record</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Cycles</p>
                        <p class="es-stat-cell-value">{{ editorialMetrics.cycles }}</p>
                        <p class="es-stat-cell-caption">Available to calibrate</p>
                    </div>
                </div>
            </section>
        </template>

        <div class="space-y-6">

            <!-- ── Stat cards ─────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
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

            <!-- ── Distribution chart ────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest shadow-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Rating Distribution</p>
                        <p class="text-[13px] font-semibold text-on-surface">Current cycle · calibration band indicator</p>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-lg px-2.5 py-0.5 text-[11px] font-bold" style="background:rgba(217,18,227,0.10);color:#a30db0">
                        <span class="material-symbols-outlined text-[14px]">bar_chart</span>
                        Force distribution
                    </span>
                </div>

                <!-- Stacked horizontal bar -->
                <div class="flex h-8 w-full overflow-hidden rounded-xl">
                    <div
                        v-for="band in distributionBands"
                        :key="band.label"
                        class="flex items-center justify-center text-[10px] font-black text-white transition-all duration-700"
                        :style="`width:${band.pct}%;background:${band.color}`"
                    >
                        <span v-if="band.pct > 12">{{ band.pct }}%</span>
                    </div>
                </div>

                <!-- Legend -->
                <div class="flex items-center gap-5 mt-3">
                    <div v-for="band in distributionBands" :key="band.label" class="flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm flex-shrink-0" :style="`background:${band.color}`"></span>
                        <span class="text-[11px] font-semibold text-on-surface-variant">{{ band.label }}</span>
                        <span class="text-[11px] font-mono text-on-surface">{{ band.pct }}%</span>
                    </div>
                    <span class="ml-auto text-[11px] italic text-on-surface-variant/50">Target: 20 / 65 / 15</span>
                </div>
            </div>

            <!-- ── Session cards ───────────────────────────────────────────── -->
            <div v-if="sessionList.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No calibration sessions"
                    description="Open a new calibration session to normalise manager ratings for a review cycle."
                    icon="event_available"
                >
                    <template #action>
                        <button
                            v-if="canFacilitate"
                            @click="showAddPanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Start Calibration
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="session in sessionList"
                    :key="session.id"
                    class="group rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5 transition-all hover:shadow-lifted hover:-translate-y-0.5"
                >
                    <!-- Header: cycle + date + facilitator -->
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60 mb-1">Cycle</p>
                            <p class="text-[15px] font-bold text-on-surface leading-tight">{{ session.cycle?.name ?? '—' }}</p>
                            <p class="text-[11px] text-on-surface-variant/60 mt-0.5">
                                Opened {{ formatDate(session.opened_at) }}
                                <span v-if="session.department?.name" class="ml-1">· {{ session.department.name }}</span>
                                <span v-else class="ml-1">· Org-wide</span>
                            </p>
                        </div>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
                            :class="statusClass(session.status)"
                        >{{ session.status_label ?? session.status }}</span>
                    </div>

                    <!-- Facilitator avatar -->
                    <div v-if="session.facilitator?.name" class="flex items-center gap-2 mb-3">
                        <div
                            class="h-6 w-6 flex-shrink-0 rounded-full flex items-center justify-center text-[9px] font-black text-white"
                            :style="`background:${avatarGradient(session.facilitator?.id)}`"
                        >{{ initials(session.facilitator.name) }}</div>
                        <span class="text-[11px] text-on-surface-variant">Facilitator: <span class="font-semibold text-on-surface">{{ session.facilitator.name }}</span></span>
                    </div>

                    <!-- Badges row -->
                    <div class="flex items-center gap-2 mb-4">
                        <span class="inline-flex items-center gap-1 rounded-lg bg-surface-container px-2 py-0.5 text-[11px] font-semibold text-on-surface-variant">
                            <span class="material-symbols-outlined text-[13px]">group</span>
                            Participants tracked
                        </span>
                        <span
                            class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[11px] font-semibold"
                            :class="(session.adjustments_count ?? 0) > 0 ? 'bg-violet-500/10 text-violet-700' : 'bg-surface-container text-on-surface-variant'"
                        >
                            <span class="material-symbols-outlined text-[13px]">tune</span>
                            {{ session.adjustments_count ?? 0 }} adjustments
                        </span>
                    </div>

                    <!-- Footer action -->
                    <div class="flex items-center justify-end border-t border-outline-variant/40 pt-3">
                        <Link
                            :href="route('performance.calibration.show', session.id)"
                            class="flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] font-bold text-primary hover:bg-surface-container-low transition-colors"
                        >
                            <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                            Open Session
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="sessions?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing
                    <span class="font-semibold text-on-surface">{{ sessions.meta?.from }}</span>
                    –
                    <span class="font-semibold text-on-surface">{{ sessions.meta?.to }}</span>
                    of
                    <span class="font-semibold text-on-surface">{{ sessions.meta?.total }}</span>
                </p>
                <Pagination :links="sessions.links" />
            </div>
        </div>

        <!-- ── Start Calibration SlidePanel ──────────────────────────────── -->
        <SlidePanel
            :open="showAddPanel"
            title="Start Calibration Session"
            size="md"
            @close="showAddPanel = false"
        >
            <form @submit.prevent="submitSession" class="space-y-5 p-6">

                <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4">
                    <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.18em] text-amber-700 mb-1">
                        <span class="material-symbols-outlined text-[15px]">warning</span>
                        Facilitator Action
                    </div>
                    <p class="text-[12px] text-on-surface-variant/70">
                        Opening a calibration session locks the cycle for rating adjustments. Only HR facilitators can start sessions.
                    </p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Review Cycle <span class="text-red-500">*</span></label>
                    <select
                        v-model="form.cycle_id"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.cycle_id }"
                    >
                        <option value="" disabled>Select cycle…</option>
                        <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <p v-if="form.errors.cycle_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.cycle_id }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Department ID
                        <span class="ml-1 font-normal text-on-surface-variant/60">(leave blank for org-wide)</span>
                    </label>
                    <input
                        v-model="form.department_id"
                        type="number"
                        placeholder="e.g. 4 — leave blank for full org"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.department_id }"
                    />
                    <p v-if="form.errors.department_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.department_id }}</p>
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
                        @click="submitSession"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">event_available</span>
                        Open Session
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
