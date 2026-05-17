<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    goals:        Object,
    cycles:       Object,
    employees:    Array,
    filters:      Object,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

// â”€â”€ Active segment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const activeSegment = ref('my');   // 'my' | 'team' | 'org'

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const localFilters = reactive({
    search:      props.filters?.search      ?? '',
    employee_id: props.filters?.employee_id ?? '',
    cycle_id:    props.filters?.cycle_id    ?? '',
    status:      props.filters?.status      ?? '',
});

const applyFilters = () => {
    router.get(route('performance.goals.index'), {
        search:      localFilters.search      || undefined,
        employee_id: localFilters.employee_id || undefined,
        cycle_id:    localFilters.cycle_id    || undefined,
        status:      localFilters.status      || undefined,
    }, { preserveState: true, replace: true });
};

let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const clearFilters = () => {
    localFilters.search = '';
    localFilters.employee_id = '';
    localFilters.cycle_id = '';
    localFilters.status = '';
    applyFilters();
};

const hasActiveFilters = computed(() =>
    !!(localFilters.search || localFilters.employee_id || localFilters.cycle_id || localFilters.status)
);

// â”€â”€ Data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const goalList  = computed(() => props.goals?.data ?? []);
const cycleList = computed(() => props.cycles?.data ?? props.cycles ?? []);

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const stats = computed(() => {
    const data = goalList.value;
    const active    = data.filter(g => g.status === 'active');
    const atRisk    = data.filter(g => g.status === 'at_risk');
    const completed = data.filter(g => g.status === 'completed');
    const avgPct    = active.length
        ? Math.round(active.reduce((s, g) => s + (g.progress_pct ?? 0), 0) / active.length)
        : 0;
    return {
        active:    active.length,
        atRisk:    atRisk.length,
        completed: completed.length,
        avgPct,
        total:     props.goals?.meta?.total ?? data.length,
    };
});

// â”€â”€ Hero stat card config â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Hero cards — disciplined palette. Avg Achievement is the institutional
// performance metric the page exists for, so it gets the 5% gold accent.
const heroCards = computed(() => [
    {
        label:   'Active Goals',
        value:   stats.value.active,
        icon:    'trending_up',
        rgb:     '26, 35, 126',     // cobalt (action)
        suffix:  '',
    },
    {
        label:   'At Risk',
        value:   stats.value.atRisk,
        icon:    'warning',
        rgb:     '220,38,38',     // red (alarm semantic)
        suffix:  '',
    },
    {
        label:   'Completed',
        value:   stats.value.completed,
        icon:    'check_circle',
        rgb:     '5,150,105',     // green (success semantic)
        suffix:  '',
    },
    {
        label:   'Avg Achievement',
        value:   stats.value.avgPct,
        icon:    'analytics',
        rgb:     '255,215,0',     // 5% gold — institutional achievement
        suffix:  '%',
    },
]);

// â”€â”€ New goal panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showAddPanel = ref(false);

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('new') === '1') {
        showAddPanel.value = true;
    }
});

const form = useForm({
    employee_id:   '',
    cycle_id:      '',
    title:         '',
    description:   '',
    cadence:       'quarterly',
    target_value:  '',
    current_value: '',
    unit:          '',
    weight:        '',
    starts_at:     '',
    due_at:        '',
});

const submitGoal = () => {
    form.post(route('performance.goals.store'), {
        onSuccess: () => {
            form.reset();
            showAddPanel.value = false;
        },
    });
};

// â”€â”€ Check-in panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showCheckinPanel = ref(false);
const checkinGoal      = ref(null);
const checkinForm = useForm({
    progress_pct:  '',
    current_value: '',
    narrative:     '',
    mood:          'green',
});

const openCheckin = (goal, ev) => {
    ev?.stopPropagation();
    checkinGoal.value  = goal;
    checkinForm.reset();
    checkinForm.current_value = goal.current_value ?? '';
    checkinForm.progress_pct  = goal.progress_pct  ?? '';
    showCheckinPanel.value = true;
};

const submitCheckin = () => {
    checkinForm.post(route('performance.goals.checkins.store', checkinGoal.value.id), {
        onSuccess: () => {
            checkinForm.reset();
            showCheckinPanel.value = false;
            checkinGoal.value      = null;
        },
    });
};

// â”€â”€ Delete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showDeleteDialog = ref(false);
const selectedId       = ref(null);

const confirmDelete = (id, ev) => {
    ev.stopPropagation();
    selectedId.value       = id;
    showDeleteDialog.value = true;
};

const doDelete = () => {
    router.delete(route('performance.goals.destroy', selectedId.value), {
        onFinish: () => {
            showDeleteDialog.value = false;
            selectedId.value       = null;
        },
    });
};

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const moodMeta = {
    green: { color: '#059669', icon: 'sentiment_very_satisfied', label: 'On track' },
    amber: { color: '#d97706', icon: 'sentiment_neutral',        label: 'At risk'  },
    red:   { color: '#dc2626', icon: 'sentiment_very_dissatisfied', label: 'Off track' },
};

const STATUS_TONE = {
    draft:      { bg: 'rgba(107,114,128,0.12)', color: '#6b7280' },
    active:     { bg: 'rgba(26, 35, 126,0.10)',    color: '#1a237e' },
    at_risk:    { bg: 'rgba(220,38,38,0.10)',   color: '#dc2626' },
    completed:  { bg: 'rgba(5,150,105,0.12)',   color: '#059669' },
    cancelled:  { bg: 'rgba(107,114,128,0.10)', color: '#9ca3af' },
};

const statusTone = (s) => STATUS_TONE[s] ?? STATUS_TONE.draft;

const formatDate = (d) =>
    d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'â€”';

const relativeDate = (iso) => {
    if (!iso) return null;
    const diff = Math.floor((Date.now() - new Date(iso)) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    if (diff < 7)   return `${diff} days ago`;
    if (diff < 30)  return `${Math.floor(diff / 7)}w ago`;
    return formatDate(iso);
};

const progressColor = (pct) => {
    if (pct >= 75) return '#059669';
    if (pct >= 40) return '#1a237e';
    if (pct > 0)   return '#d97706';
    return '#9ca3af';
};

const progressGradient = (pct) => {
    const c = progressColor(pct);
    return `linear-gradient(90deg, ${c}, ${c}cc)`;
};

const progressWidth = (pct) => `${Math.min(100, Math.max(0, pct ?? 0))}%`;

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return (p.length >= 2 ? p[0][0] + p[p.length - 1][0] : name.slice(0, 2)).toUpperCase();
};

// Avatar gradient pool — disciplined cool family (matches Employees/Leave/Tickets/Payments)
const GRADIENTS = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
const avatarGrad = (id) => GRADIENTS[(id ?? 0) % GRADIENTS.length];

// Mini sparkline: generate a tiny SVG path from an array of values 0-100
const sparkPath = (values) => {
    if (!values || values.length < 2) return '';
    const w = 56, h = 20;
    const step = w / (values.length - 1);
    const pts = values.map((v, i) => {
        const x = i * step;
        const y = h - (v / 100) * h;
        return `${x},${y}`;
    });
    return `M ${pts.join(' L ')}`;
};
</script>

<template>
    <Head title="Goals" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                        <Link :href="route('modules.performance')" class="hover:text-secondary">Performance</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Goals</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Goals &amp; Check-ins</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        OKR-style outcomes tracked through periodic check-ins.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('performance.reviews.index')"
                        class="flex items-center gap-2 rounded-xl border border-outline-variant/80 px-4 py-2.5 text-[13px] font-semibold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary hover:border-secondary/30 transition-all"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="color:#1a237e">rate_review</span>
                        Reviews
                    </Link>
                    <button
                        @click="showAddPanel = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">track_changes</span>
                        New Goal
                    </button>
                </div>
            </div>
        </template>

        <div class="p-6 space-y-6 animate-reveal-up">

            <!-- â”€â”€ Hero stat strip â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div
                    v-for="(card, i) in heroCards"
                    :key="card.label"
                    class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden p-5"
                    :style="`border-left: 3px solid rgba(${card.rgb},0.7); animation-delay: ${i * 0.06}s`"
                >
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-xl"
                            :style="`background:rgba(${card.rgb},0.12)`"
                        >
                            <span
                                class="material-symbols-outlined text-[20px]"
                                :style="`color:rgb(${card.rgb});font-variation-settings:'FILL' 1`"
                            >{{ card.icon }}</span>
                        </div>
                    </div>
                    <p class="text-[2rem] font-black leading-none tabular-nums" :style="`color:rgb(${card.rgb})`">
                        {{ card.value }}<span v-if="card.suffix" class="text-[1.2rem]">{{ card.suffix }}</span>
                    </p>
                    <p class="mt-1 text-[11px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">{{ card.label }}</p>
                </div>
            </div>

            <!-- â”€â”€ Segment control + Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- Segment control: My / Team / Org -->
                <div class="flex items-center rounded-xl border border-outline-variant/70 bg-surface-container-low p-0.5 gap-0.5">
                    <button
                        v-for="seg in [{ key:'my', label:'My Goals', icon:'person' }, { key:'team', label:'Team', icon:'group' }, { key:'org', label:'Org', icon:'corporate_fare' }]"
                        :key="seg.key"
                        @click="activeSegment = seg.key"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-bold transition-all"
                        :class="activeSegment === seg.key
                            ? 'bg-secondary text-white shadow-sm'
                            : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'"
                    >
                        <span class="material-symbols-outlined text-[15px]">{{ seg.icon }}</span>
                        {{ seg.label }}
                    </button>
                </div>

                <div class="flex-1 min-w-[180px] max-w-xs">
                    <SearchInput v-model="localFilters.search" placeholder="Search goalsâ€¦" />
                </div>

                <select
                    v-model="localFilters.cycle_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Cycles</option>
                    <option v-for="c in cycleList" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <select
                    v-if="canManage"
                    v-model="localFilters.employee_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Employees</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.label }}</option>
                </select>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="at_risk">At Risk</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <button
                    v-if="hasActiveFilters"
                    @click="clearFilters"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- â”€â”€ Empty state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="goalList.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No goals found"
                    description="Set a stretch goal for someone on your team to start tracking outcomes."
                    icon="track_changes"
                >
                    <template #action>
                        <button
                            @click="showAddPanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            New Goal
                        </button>
                    </template>
                </EmptyState>
            </div>

            <!-- â”€â”€ Goal cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-else class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="(goal, i) in goalList"
                    :key="goal.id"
                    class="group relative rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden transition-all hover:shadow-lifted hover:-translate-y-0.5 cursor-pointer"
                    :style="`animation-delay: ${i * 0.04}s`"
                    @click="openCheckin(goal, $event)"
                >
                    <!-- Coloured top accent bar keyed to status -->
                    <div
                        class="h-1 w-full"
                        :style="`background:linear-gradient(90deg, ${statusTone(goal.status).color}, ${statusTone(goal.status).color}80)`"
                    ></div>

                    <div class="p-5">
                        <!-- Header row -->
                        <div class="flex items-start justify-between gap-3 mb-1">
                            <div class="flex items-center gap-2 min-w-0 flex-1">
                                <!-- Employee avatar -->
                                <div
                                    class="h-8 w-8 flex-shrink-0 rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm flex items-center justify-center text-[10px] font-black text-white transition-transform group-hover:scale-105"
                                    :style="`background:${avatarGrad(goal.employee_id)}`"
                                >{{ initials(goal.employee?.name) }}</div>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold text-on-surface-variant/70 truncate">
                                        {{ goal.employee?.name ?? 'â€”' }}
                                        <span v-if="goal.cycle?.name" class="text-on-surface-variant/40"> Â· {{ goal.cycle.name }}</span>
                                    </p>
                                </div>
                            </div>
                            <!-- Status pill -->
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap flex-shrink-0"
                                :style="`background:${statusTone(goal.status).bg};color:${statusTone(goal.status).color};border:1px solid ${statusTone(goal.status).color}33`"
                            >{{ goal.status_label }}</span>
                        </div>

                        <!-- Title + cadence -->
                        <div class="flex items-start gap-2 mb-3 mt-2">
                            <h3 class="text-[15px] font-black text-on-surface leading-snug line-clamp-2 flex-1">{{ goal.title }}</h3>
                            <span
                                class="mt-0.5 flex-shrink-0 inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                                style="background:rgba(26, 35, 126,0.08);color:#1a237e"
                            >{{ goal.cadence_label }}</span>
                        </div>

                        <!-- Description snippet -->
                        <p v-if="goal.description" class="mb-3 text-[12px] text-on-surface-variant/70 line-clamp-1 leading-relaxed">
                            {{ goal.description }}
                        </p>

                        <!-- Progress track â”€â”€â”€â”€ -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Progress</span>
                                <div class="flex items-center gap-2">
                                    <!-- Sparkline (last few check-ins represented as a trend line) -->
                                    <svg v-if="goal.last_checkin" width="56" height="20" class="opacity-60">
                                        <path
                                            :d="sparkPath([0, Math.min(100, goal.last_checkin.progress_pct ?? 0), Math.min(100, goal.progress_pct ?? 0)])"
                                            fill="none"
                                            :stroke="progressColor(goal.progress_pct ?? 0)"
                                            stroke-width="1.5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                    <span
                                        class="text-[13px] font-black font-mono tabular-nums"
                                        :style="`color:${progressColor(goal.progress_pct ?? 0)}`"
                                    >{{ Math.round(goal.progress_pct ?? 0) }}%</span>
                                </div>
                            </div>
                            <!-- Progress bar with milestone marker at 50% -->
                            <div class="relative h-2.5 rounded-full bg-surface-container overflow-visible">
                                <div
                                    class="absolute inset-y-0 left-0 rounded-full transition-all duration-700"
                                    :style="`width:${progressWidth(goal.progress_pct)};background:${progressGradient(goal.progress_pct ?? 0)}`"
                                ></div>
                                <!-- 50% milestone marker -->
                                <div class="absolute top-0 bottom-0 w-px bg-outline-variant/60" style="left:50%"></div>
                            </div>
                            <!-- Target vs current row -->
                            <div class="mt-1.5 flex items-center justify-between text-[10px] text-on-surface-variant/60 font-semibold">
                                <span>0 {{ goal.unit }}</span>
                                <span v-if="goal.target_value" class="tabular-nums">Target: {{ goal.target_value }} {{ goal.unit }}</span>
                                <span>Current: <span class="text-on-surface font-mono font-bold tabular-nums">{{ goal.current_value ?? 0 }}</span></span>
                            </div>
                        </div>

                        <!-- Last check-in snippet â”€â”€â”€â”€ -->
                        <div
                            v-if="goal.last_checkin"
                            class="flex items-center gap-2 rounded-xl border border-outline-variant/40 bg-surface-container/40 px-3 py-2 mb-4"
                        >
                            <span
                                class="material-symbols-outlined text-[17px] flex-shrink-0"
                                :style="`color:${moodMeta[goal.last_checkin.mood ?? 'green'].color};font-variation-settings:'FILL' 1`"
                            >{{ moodMeta[goal.last_checkin.mood ?? 'green'].icon }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] text-on-surface-variant/80 leading-snug">
                                    <span class="font-bold text-on-surface">{{ moodMeta[goal.last_checkin.mood ?? 'green'].label }}</span>
                                    <span class="mx-1 text-on-surface-variant/40">Â·</span>
                                    Last update {{ relativeDate(goal.last_checkin.recorded_at) }}
                                </p>
                            </div>
                            <span class="flex-shrink-0 font-mono text-[11px] font-bold tabular-nums" :style="`color:${progressColor(goal.last_checkin.progress_pct)}`">
                                {{ Math.round(goal.last_checkin.progress_pct ?? 0) }}%
                            </span>
                        </div>
                        <div
                            v-else
                            class="flex items-center gap-2 rounded-xl border border-dashed border-outline-variant/40 px-3 py-2 mb-4 text-[11px] text-on-surface-variant/40 italic"
                        >
                            <span class="material-symbols-outlined text-[16px]">history_toggle_off</span>
                            No check-ins yet â€” click to record the first one
                        </div>

                        <!-- Due date strip -->
                        <div class="flex items-center justify-between border-t border-outline-variant/40 pt-3" @click.stop>
                            <div class="flex items-center gap-1.5 text-[11px] text-on-surface-variant/60">
                                <span class="material-symbols-outlined text-[14px]">calendar_today</span>
                                <span>Due <span class="font-semibold text-on-surface">{{ formatDate(goal.due_at) }}</span></span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button
                                    @click="openCheckin(goal, $event)"
                                    class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-[11px] font-bold text-secondary hover:bg-secondary/10 transition-colors"
                                >
                                    <span class="material-symbols-outlined text-[15px]">add_task</span>
                                    Check-in
                                </button>
                                <button
                                    v-if="canManage"
                                    @click="confirmDelete(goal.id, $event)"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/60 hover:bg-red-500/10 hover:text-red-600 hover:border-red-500/15 transition-all"
                                    title="Delete goal"
                                    aria-label="Delete goal"
                                >
                                    <span class="material-symbols-outlined text-[16px]">delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â”€â”€ Pagination â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="goals?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing <span class="font-semibold text-on-surface">{{ goals.meta?.from }}</span> â€“ <span class="font-semibold text-on-surface">{{ goals.meta?.to }}</span>
                    of <span class="font-semibold text-on-surface">{{ goals.meta?.total }}</span>
                </p>
                <Pagination :links="goals.links" />
            </div>
        </div>

        <!-- â”€â”€ New Goal SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel :open="showAddPanel" title="New Goal" size="lg" @close="showAddPanel = false">
            <form @submit.prevent="submitGoal" class="space-y-5 p-6">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.employee_id"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.employee_id }"
                        >
                            <option value="" disabled>Select employee</option>
                            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.label }}</option>
                        </select>
                        <p v-if="form.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.employee_id }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Review Cycle</label>
                        <select
                            v-model="form.cycle_id"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="">No cycle</option>
                            <option v-for="c in cycleList" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Title <span class="text-red-500">*</span></label>
                    <input
                        v-model="form.title"
                        type="text"
                        placeholder="e.g. Reduce ticket resolution time to under 4 hours"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.title }"
                    />
                    <p v-if="form.errors.title" class="mt-1 text-[11px] text-red-500">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        placeholder="What does success look like? Why does it matter?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Cadence <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.cadence"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="annual">Annual</option>
                            <option value="half_year">Half-yearly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="monthly">Monthly</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Weight (0â€“100)</label>
                        <input v-model="form.weight" type="number" min="0" max="100" placeholder="20"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Unit</label>
                        <input v-model="form.unit" type="text" placeholder="hours, %, count" maxlength="20"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Target Value</label>
                        <input v-model="form.target_value" type="number" step="0.01" min="0" placeholder="4"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Current Value</label>
                        <input v-model="form.current_value" type="number" step="0.01" min="0" placeholder="0"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Starts On</label>
                        <input v-model="form.starts_at" type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Due On</label>
                        <input v-model="form.due_at" type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.due_at }" />
                        <p v-if="form.errors.due_at" class="mt-1 text-[11px] text-red-500">{{ form.errors.due_at }}</p>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showAddPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">Cancel</button>
                    <button @click="submitGoal" :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Save Goal
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- â”€â”€ Check-in SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showCheckinPanel"
            :title="checkinGoal ? `Check-in Â· ${checkinGoal.title}` : 'Check-in'"
            size="md"
            @close="showCheckinPanel = false"
        >
            <form v-if="checkinGoal" @submit.prevent="submitCheckin" class="space-y-5 p-6">

                <!-- Goal context card -->
                <div class="rounded-xl border border-outline-variant/60 bg-surface-container/40 p-4">
                    <div class="flex items-start gap-3">
                        <div
                            class="h-8 w-8 rounded-full flex items-center justify-center text-[10px] font-black text-white flex-shrink-0"
                            :style="`background:${avatarGrad(checkinGoal.employee_id)}`"
                        >{{ initials(checkinGoal.employee?.name) }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-bold text-on-surface leading-snug">{{ checkinGoal.title }}</p>
                            <p class="text-[11px] text-on-surface-variant/70 mt-0.5">{{ checkinGoal.employee?.name }} Â· target {{ checkinGoal.target_value ?? 'â€”' }} {{ checkinGoal.unit }}</p>
                        </div>
                    </div>
                    <!-- Inline progress bar in the context card -->
                    <div class="mt-3 h-1.5 rounded-full bg-surface-container overflow-hidden">
                        <div
                            class="h-full rounded-full"
                            :style="`width:${progressWidth(checkinGoal.progress_pct)};background:${progressGradient(checkinGoal.progress_pct ?? 0)}`"
                        ></div>
                    </div>
                    <p class="mt-1 text-[11px] font-semibold text-right" :style="`color:${progressColor(checkinGoal.progress_pct ?? 0)}`">
                        {{ Math.round(checkinGoal.progress_pct ?? 0) }}% complete
                    </p>
                </div>

                <!-- Traffic-light mood selector -->
                <div>
                    <label class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-2 block">Status Signal</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="(meta, key) in moodMeta" :key="key"
                            type="button"
                            @click="checkinForm.mood = key"
                            class="flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3.5 transition-all"
                            :style="checkinForm.mood === key
                                ? `border-color:${meta.color};background:${meta.color}18`
                                : 'border-color:transparent;background:rgba(var(--color-surface-container)/0.5)'"
                        >
                            <span
                                class="material-symbols-outlined text-[28px]"
                                :style="`color:${meta.color};font-variation-settings:'FILL' 1`"
                            >{{ meta.icon }}</span>
                            <span class="text-[11px] font-black" :style="`color:${meta.color}`">{{ meta.label }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Current Value</label>
                        <input v-model="checkinForm.current_value" type="number" step="0.01" min="0"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Progress %</label>
                        <input v-model="checkinForm.progress_pct" type="number" step="1" min="0" max="100"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Narrative</label>
                    <textarea v-model="checkinForm.narrative" rows="4"
                        placeholder="What happened since last check-in? What's blocking progress?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showCheckinPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">Cancel</button>
                    <button @click="submitCheckin" :disabled="checkinForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span v-if="checkinForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Record Check-in
                    </button>
                </div>
            </template>
        </SlidePanel>

        <ConfirmDialog
            :open="showDeleteDialog"
            title="Delete Goal"
            message="This will remove the goal and its check-in history. This action cannot be undone."
            :danger="true"
            @confirm="doDelete"
            @cancel="showDeleteDialog = false"
        />

    </AuthenticatedLayout>
</template>
