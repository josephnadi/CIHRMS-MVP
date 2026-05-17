<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SearchInput from '@/Components/SearchInput.vue';

const props = defineProps({
    reviews:      Object,
    cycles:       Object,
    activeCycle:  Object,
    filters:      Object,
    activeModule: String,
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);
const canManage   = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

// â”€â”€ View tab â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const activeTab = ref('reviewee');  // 'reviewee' | 'reviewer' | 'all'

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const localFilters = reactive({
    cycle_id: props.filters?.cycle_id ?? '',
    type:     props.filters?.type     ?? '',
    status:   props.filters?.status   ?? '',
    search:   '',
});

const applyFilters = () => {
    router.get(route('performance.reviews.index'), {
        cycle_id: localFilters.cycle_id || undefined,
        type:     localFilters.type     || undefined,
        status:   localFilters.status   || undefined,
    }, { preserveState: true, replace: true });
};

// â”€â”€ Data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const reviewList = computed(() => props.reviews?.data ?? []);
const cycleList  = computed(() => props.cycles?.data ?? props.cycles ?? []);

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const stats = computed(() => {
    const data = reviewList.value;
    return {
        total:        props.reviews?.meta?.total ?? data.length,
        draft:        data.filter(r => r.status === 'draft').length,
        submitted:    data.filter(r => r.status === 'submitted').length,
        acknowledged: data.filter(r => r.status === 'acknowledged').length,
    };
});

// Hero cards — disciplined palette. Pending Acknowledgement swapped from
// violet to magenta (people-side action color) to align with the broader
// Sovereign Precision system.
const heroCards = computed(() => [
    { label: 'In Progress',             value: stats.value.draft,        icon: 'edit_note',         rgb: '26, 35, 126'  },
    { label: 'Awaiting Feedback',       value: stats.value.submitted,    icon: 'hourglass_empty',   rgb: '217,119,6'  },
    { label: 'Pending Acknowledgement', value: stats.value.submitted,    icon: 'mark_email_unread', rgb: '217,18,227' },
    { label: 'Completed',               value: stats.value.acknowledged, icon: 'task_alt',          rgb: '5,150,105'  },
]);

// â”€â”€ Rating distribution for HR view â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const ratingDist = computed(() => {
    const bins = { '1': 0, '2': 0, '3': 0, '4': 0, '5': 0 };
    reviewList.value.forEach(r => {
        if (r.overall_rating != null) {
            const k = String(Math.round(r.overall_rating));
            if (bins[k] !== undefined) bins[k]++;
        }
    });
    const max = Math.max(...Object.values(bins), 1);
    return Object.entries(bins).map(([score, count]) => ({
        score,
        count,
        pct: Math.round((count / max) * 100),
    }));
});

// â”€â”€ New review panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showAddPanel = ref(false);
const form = useForm({
    cycle_id:           props.activeCycle?.id ?? '',
    employee_id:        '',
    reviewer_id:        '',
    type:               'self',
    overall_rating:     '',
    performance_rating: '',
    potential_rating:   '',
    strengths:          '',
    opportunities:      '',
    comments:           '',
});

watch(() => form.type, (val) => {
    if (val === 'self' && currentUser.value?.id) {
        form.reviewer_id = currentUser.value.id;
    }
});

const submitReview = () => {
    form.post(route('performance.reviews.store'), {
        onSuccess: () => {
            form.reset();
            form.cycle_id = props.activeCycle?.id ?? '';
            showAddPanel.value = false;
        },
    });
};

// â”€â”€ New cycle panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showCyclePanel = ref(false);
const cycleForm = useForm({
    name:               '',
    cadence:            'quarterly',
    starts_at:          '',
    ends_at:            '',
    self_review_due:    '',
    peer_review_due:    '',
    manager_review_due: '',
});

const submitCycle = () => {
    cycleForm.post(route('performance.cycles.store'), {
        onSuccess: () => {
            cycleForm.reset();
            showCyclePanel.value = false;
        },
    });
};

// â”€â”€ Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const submitDraft = (review) => {
    router.patch(route('performance.reviews.submit', review.id), {}, { preserveScroll: true });
};

const ackReview = (review) => {
    router.patch(route('performance.reviews.ack', review.id), {}, { preserveScroll: true });
};

const closeCycle = (cycle) => {
    if (!confirm(`Close cycle "${cycle.name}"? This will lock further review submissions for this cycle.`)) return;
    router.patch(route('performance.cycles.close', cycle.id), {}, { preserveScroll: true });
};

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const TYPE_META = {
    self:       { label: 'Self',        rgb: '13, 20, 82',   icon: 'person'             },  // navy
    manager:    { label: 'Manager',     rgb: '26, 35, 126',  icon: 'manage_accounts'    },  // cobalt
    peer:       { label: 'Peer',        rgb: '18,217,227', icon: 'people'             },  // cyan (proper brand cyan)
    skip_level: { label: 'Skip-level',  rgb: '217,119,6',  icon: 'supervisor_account' },  // amber
    upward:     { label: 'Upward',      rgb: '5,150,105',  icon: 'arrow_upward'       },  // green
};

const STATUS_META = {
    draft:        { label: 'Draft',        rgb: '107,114,128' },
    submitted:    { label: 'Submitted',    rgb: '26, 35, 126'    },
    acknowledged: { label: 'Acknowledged', rgb: '5,150,105'   },
};

const typeMeta = (t) => TYPE_META[t] ?? TYPE_META.self;
const statusMeta = (s) => STATUS_META[s] ?? STATUS_META.draft;

// Workflow steps for the progress arrow strip
const WORKFLOW_STEPS = [
    { key: 'draft',        label: 'Self',          icon: 'person'      },
    { key: 'submitted',    label: 'Manager',       icon: 'manage_accounts' },
    { key: 'calibration',  label: 'Calibration',   icon: 'tune'        },
    { key: 'acknowledged', label: 'Acknowledged',  icon: 'task_alt'    },
];

const workflowStep = (status) => {
    if (status === 'draft')        return 0;
    if (status === 'submitted')    return 1;
    if (status === 'acknowledged') return 3;
    return 2;
};

const actionLabel = (r) => {
    if (canSubmit(r))              return { text: 'Submit Review',     icon: 'send',     cls: 'text-secondary hover:bg-secondary/10' };
    if (canAck(r))                 return { text: 'Acknowledge',       icon: 'task_alt', cls: 'text-emerald-600 hover:bg-emerald-500/10' };
    return null;
};

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

const renderRating = (v) => v == null ? 'â€”' : Number(v).toFixed(1);

const isMine     = (r) => r.reviewer?.id === currentUser.value?.id || r.reviewer_id === currentUser.value?.id;
const canSubmit  = (r) => r.status === 'draft'      && (canManage.value || isMine(r));
const canAck     = (r) => r.status === 'submitted';

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return (p.length >= 2 ? p[0][0] + p[p.length - 1][0] : name.slice(0, 2)).toUpperCase();
};

// Avatar gradient pool — disciplined cool family (matches all other modules)
const GRADIENTS = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
const avatarGrad = (id) => GRADIENTS[(id ?? 0) % GRADIENTS.length];
</script>

<template>
    <Head title="Reviews" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                        <Link :href="route('modules.performance')" class="hover:text-secondary">Performance</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Reviews</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Performance Reviews</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Self, manager, peer and 360Â° reviews â€” tracked across cycles.
                        <span v-if="activeCycle" class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            Active: {{ activeCycle.name }}
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('performance.goals.index')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">track_changes</span>
                        Goals
                    </Link>
                    <Link
                        :href="route('performance.nine-box')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">grid_view</span>
                        9-Box
                    </Link>
                    <button
                        v-if="canManage"
                        @click="showCyclePanel = true"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">event_repeat</span>
                        New Cycle
                    </button>
                    <button
                        @click="showAddPanel = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Review
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
                    <div class="mb-3">
                        <div
                            class="inline-flex h-9 w-9 items-center justify-center rounded-xl"
                            :style="`background:rgba(${card.rgb},0.12)`"
                        >
                            <span
                                class="material-symbols-outlined text-[20px]"
                                :style="`color:rgb(${card.rgb});font-variation-settings:'FILL' 1`"
                            >{{ card.icon }}</span>
                        </div>
                    </div>
                    <p class="text-[2rem] font-black leading-none tabular-nums" :style="`color:rgb(${card.rgb})`">{{ card.value }}</p>
                    <p class="mt-1 text-[11px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">{{ card.label }}</p>
                </div>
            </div>

            <!-- â”€â”€ Rating distribution bar (HR view) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="canManage && reviewList.length > 0" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Rating Distribution (Overall)</p>
                <div class="flex items-end gap-2 h-14">
                    <div v-for="bin in ratingDist" :key="bin.score" class="flex flex-1 flex-col items-center gap-1">
                        <span class="text-[10px] font-mono text-on-surface-variant/60">{{ bin.count }}</span>
                        <div class="w-full rounded-t-md transition-all duration-500" :style="`height:${Math.max(4, bin.pct * 0.36)}rem;background:linear-gradient(180deg,rgba(26, 35, 126,0.7),rgba(26, 35, 126,0.3))`"></div>
                        <span class="text-[10px] font-bold text-on-surface-variant/70">{{ bin.score }}</span>
                    </div>
                </div>
            </div>

            <!-- â”€â”€ Cycles strip â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="cycleList.length" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Review Cycles</p>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="c in cycleList.slice(0, 4)"
                        :key="c.id"
                        class="relative rounded-xl border overflow-hidden"
                        :style="`border-color:${c.status === 'active' ? 'rgba(5,150,105,0.3)' : 'rgba(var(--color-outline-variant)/0.6)'}`"
                    >
                        <!-- Active cycle accent bar -->
                        <div
                            v-if="c.status === 'active'"
                            class="h-0.5 w-full"
                            style="background:linear-gradient(90deg,#059669,#34d399)"
                        ></div>
                        <div class="p-3.5">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="min-w-0">
                                    <p class="text-[13px] font-bold text-on-surface truncate">{{ c.name }}</p>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mt-0.5">{{ c.cadence }}</p>
                                </div>
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
                                    :style="`background:${c.status === 'active' ? 'rgba(5,150,105,0.12)' : 'rgba(107,114,128,0.12)'};color:${c.status === 'active' ? '#059669' : '#6b7280'}`"
                                >{{ c.status }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-[11px] text-on-surface-variant">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">rate_review</span>
                                    {{ c.reviews_count ?? 0 }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">track_changes</span>
                                    {{ c.goals_count ?? 0 }}
                                </span>
                                <span class="ml-auto text-[10px] text-on-surface-variant/50">
                                    {{ formatDate(c.starts_at) }} â€“ {{ formatDate(c.ends_at) }}
                                </span>
                            </div>
                            <button
                                v-if="canManage && c.status === 'active'"
                                @click="closeCycle(c)"
                                class="mt-2.5 text-[10px] font-bold uppercase tracking-wider text-red-600 hover:text-red-700 flex items-center gap-1 transition-colors"
                            >
                                <span class="material-symbols-outlined text-[13px]">lock</span>
                                Close cycle
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â”€â”€ Tab nav + filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- Tab control -->
                <div class="flex items-center rounded-xl border border-outline-variant/70 bg-surface-container-low p-0.5 gap-0.5">
                    <button
                        v-for="tab in [{ key:'reviewee', label:'As Reviewee', icon:'person' }, { key:'reviewer', label:'As Reviewer', icon:'manage_accounts' }, ...(canManage ? [{ key:'all', label:'All (HR)', icon:'admin_panel_settings' }] : [])]"
                        :key="tab.key"
                        @click="activeTab = tab.key"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-bold transition-all"
                        :class="activeTab === tab.key
                            ? 'bg-secondary text-white shadow-sm'
                            : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'"
                    >
                        <span class="material-symbols-outlined text-[15px]">{{ tab.icon }}</span>
                        {{ tab.label }}
                    </button>
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
                    v-model="localFilters.type"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Types</option>
                    <option value="self">Self</option>
                    <option value="manager">Manager</option>
                    <option value="peer">Peer</option>
                    <option value="skip_level">Skip-level</option>
                    <option value="upward">Upward</option>
                </select>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="acknowledged">Acknowledged</option>
                </select>
            </div>

            <!-- â”€â”€ Review cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="reviewList.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No reviews yet"
                    description="Reviews are tied to a cycle. Create a cycle first, then start drafting reviews."
                    icon="rate_review"
                >
                    <template #action>
                        <button
                            @click="showAddPanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            New Review
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="(r, i) in reviewList"
                    :key="r.id"
                    class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden"
                    :style="`animation-delay: ${i * 0.04}s`"
                >
                    <!-- Card header: cycle name + type badge -->
                    <div class="flex items-center justify-between gap-2 px-5 pt-5 pb-3 border-b border-outline-variant/40">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="material-symbols-outlined text-[16px] text-on-surface-variant/60">event_repeat</span>
                            <p class="text-[12px] font-bold text-on-surface truncate">{{ r.cycle?.name ?? 'No cycle' }}</p>
                        </div>
                        <!-- Review type badge -->
                        <span
                            class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
                            :style="`background:rgba(${typeMeta(r.type).rgb},0.12);color:rgb(${typeMeta(r.type).rgb})`"
                        >
                            <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">{{ typeMeta(r.type).icon }}</span>
                            {{ typeMeta(r.type).label }}
                        </span>
                    </div>

                    <div class="p-5 space-y-4">
                        <!-- Employee + reviewer identity strip -->
                        <div class="flex items-center gap-3">
                            <!-- Reviewee avatar -->
                            <div
                                class="h-10 w-10 rounded-full flex items-center justify-center text-[12px] font-black text-white flex-shrink-0 ring-2 ring-outline-variant/40"
                                :style="`background:${avatarGrad(r.employee_id)}`"
                            >{{ initials(r.employee?.name) }}</div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[14px] font-black text-on-surface truncate">{{ r.employee?.name ?? 'â€”' }}</p>
                                <p class="text-[11px] text-on-surface-variant/60 truncate">{{ r.employee?.department ?? r.employee?.employee_no ?? '' }}</p>
                            </div>
                            <!-- Relationship pill + reviewer -->
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <div
                                    class="h-7 w-7 rounded-full flex items-center justify-center text-[9px] font-black text-white ring-2 ring-surface-container-lowest"
                                    :style="`background:${avatarGrad((r.reviewer?.id ?? 0) + 3)}`"
                                    :title="`Reviewer: ${r.reviewer?.name ?? 'â€”'}`"
                                >{{ initials(r.reviewer?.name) }}</div>
                                <span class="text-[10px] font-semibold text-on-surface-variant/60">{{ r.reviewer?.name ?? 'â€”' }}</span>
                            </div>
                        </div>

                        <!-- Workflow progress strip â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                        <div class="rounded-xl bg-surface-container/50 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60 mb-2">Workflow</p>
                            <div class="flex items-center gap-0">
                                <template v-for="(step, si) in WORKFLOW_STEPS" :key="step.key">
                                    <!-- Step node -->
                                    <div class="flex flex-col items-center gap-1 flex-1">
                                        <div
                                            class="h-7 w-7 rounded-full flex items-center justify-center transition-all"
                                            :class="si <= workflowStep(r.status)
                                                ? 'text-white shadow-sm'
                                                : 'bg-surface-container-low text-on-surface-variant/40'"
                                            :style="si <= workflowStep(r.status) ? `background:rgb(${statusMeta(r.status).rgb})` : ''"
                                        >
                                            <span class="material-symbols-outlined text-[14px]" :style="si <= workflowStep(r.status) ? 'font-variation-settings:\'FILL\' 1' : ''">
                                                {{ si < workflowStep(r.status) ? 'check' : step.icon }}
                                            </span>
                                        </div>
                                        <span
                                            class="text-[9px] font-bold uppercase tracking-wider text-center leading-tight"
                                            :class="si <= workflowStep(r.status) ? 'text-on-surface' : 'text-on-surface-variant/40'"
                                        >{{ step.label }}</span>
                                    </div>
                                    <!-- Connector line -->
                                    <div
                                        v-if="si < WORKFLOW_STEPS.length - 1"
                                        class="h-0.5 flex-1 mb-4 rounded-full transition-all"
                                        :style="si < workflowStep(r.status)
                                            ? `background:rgb(${statusMeta(r.status).rgb})`
                                            : 'background:rgba(var(--color-outline-variant)/0.4)'"
                                    ></div>
                                </template>
                            </div>
                        </div>

                        <!-- Ratings row (if rated) -->
                        <div v-if="r.overall_rating != null" class="flex items-center gap-3 rounded-xl border border-outline-variant/40 bg-surface-container/40 px-3 py-2.5">
                            <div v-for="{ label, key } in [{ label:'Overall', key:'overall_rating' }, { label:'Performance', key:'performance_rating' }, { label:'Potential', key:'potential_rating' }]" :key="key" class="flex-1 text-center">
                                <p class="text-[9px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ label }}</p>
                                <p class="text-[18px] font-black font-mono tabular-nums text-on-surface">{{ renderRating(r[key]) }}</p>
                            </div>
                        </div>

                        <!-- Dates strip -->
                        <div class="flex items-center justify-between text-[11px] text-on-surface-variant/60">
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                Created {{ formatDate(r.created_at) }}
                            </span>
                            <span v-if="r.submitted_at" class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">send</span>
                                Submitted {{ relativeDate(r.submitted_at) }}
                            </span>
                        </div>

                        <!-- Action button -->
                        <div v-if="actionLabel(r)" class="pt-1">
                            <button
                                v-if="canSubmit(r)"
                                @click="submitDraft(r)"
                                class="w-full flex items-center justify-center gap-2 rounded-xl border border-secondary/30 py-2.5 text-[12px] font-bold text-secondary hover:bg-secondary/8 transition-colors"
                            >
                                <span class="material-symbols-outlined text-[16px]">send</span>
                                Submit Review
                            </button>
                            <button
                                v-else-if="canAck(r)"
                                @click="ackReview(r)"
                                class="w-full flex items-center justify-center gap-2 rounded-xl py-2.5 text-[12px] font-bold text-white transition-colors"
                                style="background:linear-gradient(135deg,#059669,#10b981)"
                            >
                                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">task_alt</span>
                                Acknowledge
                            </button>
                        </div>
                        <div v-else class="pt-1 flex items-center justify-center gap-1.5 text-[11px] text-on-surface-variant/40">
                            <span class="material-symbols-outlined text-[14px]">info</span>
                            <span
                                class="font-semibold"
                                :style="`color:rgb(${statusMeta(r.status).rgb})`"
                            >{{ statusMeta(r.status).label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â”€â”€ Pagination â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="reviews?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing <span class="font-semibold text-on-surface">{{ reviews.meta?.from }}</span> â€“ <span class="font-semibold text-on-surface">{{ reviews.meta?.to }}</span>
                    of <span class="font-semibold text-on-surface">{{ reviews.meta?.total }}</span>
                </p>
                <Pagination :links="reviews.links" />
            </div>
        </div>

        <!-- â”€â”€ New Review SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel :open="showAddPanel" title="New Review" size="lg" @close="showAddPanel = false">
            <form @submit.prevent="submitReview" class="space-y-5 p-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Cycle <span class="text-red-500">*</span></label>
                        <select v-model="form.cycle_id" required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.cycle_id }">
                            <option value="" disabled>Select cycle</option>
                            <option v-for="c in cycleList" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.cycle_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.cycle_id }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Type <span class="text-red-500">*</span></label>
                        <select v-model="form.type" required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all">
                            <option value="self">Self review</option>
                            <option value="manager">Manager review</option>
                            <option value="peer">Peer review</option>
                            <option value="skip_level">Skip-level</option>
                            <option value="upward">Upward</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee ID <span class="text-red-500">*</span></label>
                        <input v-model="form.employee_id" type="number" required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.employee_id }" />
                        <p v-if="form.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.employee_id }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Reviewer User ID <span class="text-red-500">*</span></label>
                        <input v-model="form.reviewer_id" type="number" required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.reviewer_id }" />
                        <p v-if="form.errors.reviewer_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.reviewer_id }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-outline-variant/60 bg-surface-container/40 p-4 space-y-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Ratings (1.0 â€“ 5.0)</p>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Overall</label>
                            <input v-model="form.overall_rating" type="number" step="0.1" min="1" max="5"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Performance</label>
                            <input v-model="form.performance_rating" type="number" step="0.1" min="1" max="5"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Potential</label>
                            <input v-model="form.potential_rating" type="number" step="0.1" min="1" max="5"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Strengths</label>
                    <textarea v-model="form.strengths" rows="3" placeholder="What did this person do exceptionally well?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Opportunities</label>
                    <textarea v-model="form.opportunities" rows="3" placeholder="What should this person focus on next?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Additional Comments</label>
                    <textarea v-model="form.comments" rows="4" placeholder="Any further context or examplesâ€¦"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showAddPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">Cancel</button>
                    <button @click="submitReview" :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Save Draft
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- â”€â”€ New Cycle SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel :open="showCyclePanel" title="New Review Cycle" size="md" @close="showCyclePanel = false">
            <form @submit.prevent="submitCycle" class="space-y-5 p-6">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Cycle Name <span class="text-red-500">*</span></label>
                    <input v-model="cycleForm.name" type="text" placeholder="e.g. Q2 2026 Performance" required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': cycleForm.errors.name }" />
                    <p v-if="cycleForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ cycleForm.errors.name }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Cadence <span class="text-red-500">*</span></label>
                    <select v-model="cycleForm.cadence" required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all">
                        <option value="annual">Annual</option>
                        <option value="half_year">Half-yearly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="probation">Probation</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Starts On <span class="text-red-500">*</span></label>
                        <input v-model="cycleForm.starts_at" type="date" required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Ends On <span class="text-red-500">*</span></label>
                        <input v-model="cycleForm.ends_at" type="date" required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': cycleForm.errors.ends_at }" />
                        <p v-if="cycleForm.errors.ends_at" class="mt-1 text-[11px] text-red-500">{{ cycleForm.errors.ends_at }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-outline-variant/60 bg-surface-container/40 p-4 space-y-3">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Optional Deadlines</p>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-[11px] font-semibold text-on-surface-variant mb-1 block">Self due</label>
                            <input v-model="cycleForm.self_review_due" type="date"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50" />
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold text-on-surface-variant mb-1 block">Peer due</label>
                            <input v-model="cycleForm.peer_review_due" type="date"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50" />
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold text-on-surface-variant mb-1 block">Mgr due</label>
                            <input v-model="cycleForm.manager_review_due" type="date"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50" />
                        </div>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showCyclePanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">Cancel</button>
                    <button @click="submitCycle" :disabled="cycleForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span v-if="cycleForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Create Cycle
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
