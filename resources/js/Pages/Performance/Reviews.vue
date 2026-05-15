<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    reviews:      Object,
    cycles:       Object,
    activeCycle:  Object,
    filters:      Object,
    activeModule: String,
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

// ── Filters ──────────────────────────────────────────────────────────────────
const localFilters = reactive({
    cycle_id:    props.filters?.cycle_id    ?? '',
    type:        props.filters?.type        ?? '',
    status:      props.filters?.status      ?? '',
});

const applyFilters = () => {
    router.get(route('performance.reviews.index'), {
        cycle_id: localFilters.cycle_id || undefined,
        type:     localFilters.type     || undefined,
        status:   localFilters.status   || undefined,
    }, { preserveState: true, replace: true });
};

// ── Stats ────────────────────────────────────────────────────────────────────
const reviewList = computed(() => props.reviews?.data ?? []);
const cycleList  = computed(() => props.cycles?.data ?? props.cycles ?? []);

const stats = computed(() => {
    const data = reviewList.value;
    return {
        total:        props.reviews?.meta?.total ?? data.length,
        draft:        data.filter(r => r.status === 'draft').length,
        submitted:    data.filter(r => r.status === 'submitted').length,
        acknowledged: data.filter(r => r.status === 'acknowledged').length,
    };
});

// ── New review panel ─────────────────────────────────────────────────────────
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

// ── New cycle panel ──────────────────────────────────────────────────────────
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

// ── Actions ──────────────────────────────────────────────────────────────────
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

// ── Helpers ──────────────────────────────────────────────────────────────────
const typeColors = {
    self:       '#0051d5',
    manager:    '#7c3aed',
    peer:       '#0891b2',
    skip_level: '#d97706',
    upward:     '#059669',
};

const statusBadge = (r) => ({
    background: `${r.status_color}1a`,
    color: r.status_color,
    border: `1px solid ${r.status_color}33`,
});

const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const renderRating = (v) => v == null ? '—' : Number(v).toFixed(1);

const isMine = (r) => r.reviewer?.id === currentUser.value?.id || r.reviewer_id === currentUser.value?.id;
const isSubject = (r) => r.employee?.id && currentUser.value?.id ? false : false; // subject linkage requires user_id; rely on canAck below

const canSubmit = (r) => r.status === 'draft' && (canManage.value || isMine(r));
const canAck = (r) => r.status === 'submitted';  // employee-side; backend authorizes by employee.user_id
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
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Reviews</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Manage review cycles and capture self, manager, peer and 360° feedback.
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
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Review
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard :value="stats.total"        label="Total Reviews" icon="rate_review" color="#0051d5" />
                <StatCard :value="stats.draft"        label="Drafts"        icon="edit_note"   color="#6b7280" />
                <StatCard :value="stats.submitted"    label="Submitted"     icon="send"        color="#0051d5" />
                <StatCard :value="stats.acknowledged" label="Acknowledged"  icon="task_alt"    color="#059669" />
            </div>

            <!-- Cycles strip -->
            <div v-if="cycleList.length" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70">Review Cycles</h3>
                </div>
                <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
                    <div
                        v-for="c in cycleList.slice(0, 4)" :key="c.id"
                        class="rounded-xl border border-outline-variant/60 bg-surface-container/40 p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[13px] font-bold text-on-surface truncate">{{ c.name }}</p>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-on-surface-variant/60 mt-0.5">{{ c.cadence }}</p>
                            </div>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                :style="`background:${c.status === 'active' ? 'rgba(5,150,105,0.12)' : 'rgba(107,114,128,0.12)'};color:${c.status === 'active' ? '#059669' : '#6b7280'}`"
                            >{{ c.status }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-3 text-[11px] text-on-surface-variant">
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">rate_review</span>
                                {{ c.reviews_count ?? 0 }}
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">track_changes</span>
                                {{ c.goals_count ?? 0 }}
                            </span>
                            <span class="ml-auto text-[10px] text-on-surface-variant/60">
                                {{ formatDate(c.starts_at) }} – {{ formatDate(c.ends_at) }}
                            </span>
                        </div>
                        <button
                            v-if="canManage && c.status === 'active'"
                            @click="closeCycle(c)"
                            class="mt-2 text-[10px] font-bold uppercase tracking-wider text-red-600 hover:text-red-700 flex items-center gap-1"
                        >
                            <span class="material-symbols-outlined text-[13px]">lock</span>
                            Close cycle
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <select
                    v-model="localFilters.cycle_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Cycles</option>
                    <option v-for="c in cycleList" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <select
                    v-model="localFilters.type"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
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
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="acknowledged">Acknowledged</option>
                </select>
            </div>

            <!-- Reviews table -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">

                <div v-if="reviewList.length === 0" class="p-12">
                    <EmptyState
                        title="No reviews yet"
                        description="Reviews are tied to a cycle. Create a cycle first, then start drafting reviews."
                        icon="rate_review"
                    >
                        <template #action>
                            <button
                                @click="showAddPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                New Review
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="max-h-[calc(100vh-540px)] min-h-[280px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Employee</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Cycle</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Type</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Reviewer</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Ratings</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr v-for="r in reviewList" :key="r.id" class="hover:bg-surface-container/40 transition-colors">
                                <td class="px-4 py-3">
                                    <p class="text-[13px] font-semibold text-on-surface leading-tight">{{ r.employee?.name ?? '—' }}</p>
                                    <p class="text-[11px] text-on-surface-variant/60 font-mono">{{ r.employee?.employee_no }}</p>
                                </td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ r.cycle?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider"
                                        :style="`background:${typeColors[r.type]}1a;color:${typeColors[r.type]}`"
                                    >{{ r.type_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ r.reviewer?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 text-[11px]">
                                        <div class="flex flex-col">
                                            <span class="text-[9px] font-bold uppercase tracking-wider text-on-surface-variant/60">Overall</span>
                                            <span class="font-mono font-bold text-on-surface">{{ renderRating(r.overall_rating) }}</span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-[9px] font-bold uppercase tracking-wider text-on-surface-variant/60">Perf</span>
                                            <span class="font-mono font-bold text-on-surface">{{ renderRating(r.performance_rating) }}</span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-[9px] font-bold uppercase tracking-wider text-on-surface-variant/60">Pot</span>
                                            <span class="font-mono font-bold text-on-surface">{{ renderRating(r.potential_rating) }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                        :style="statusBadge(r)"
                                    >{{ r.status_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1">
                                        <button
                                            v-if="canSubmit(r)"
                                            @click="submitDraft(r)"
                                            class="flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-secondary hover:bg-secondary/10 transition-colors"
                                            title="Submit"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">send</span>
                                            Submit
                                        </button>
                                        <button
                                            v-if="canAck(r)"
                                            @click="ackReview(r)"
                                            class="flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-green-600 hover:bg-green-500/10 transition-colors"
                                            title="Acknowledge"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">task_alt</span>
                                            Ack
                                        </button>
                                        <span v-if="!canSubmit(r) && !canAck(r)" class="text-[11px] text-on-surface-variant/40 italic">—</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="reviews?.links?.length > 3" class="border-t border-outline-variant/50 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[12px] text-on-surface-variant">
                            Showing <span class="font-semibold text-on-surface">{{ reviews.meta?.from }}</span> – <span class="font-semibold text-on-surface">{{ reviews.meta?.to }}</span>
                            of <span class="font-semibold text-on-surface">{{ reviews.meta?.total }}</span>
                        </p>
                        <Pagination :links="reviews.links" />
                    </div>
                </div>
            </div>
        </div>

        <!-- ── New Review SlidePanel ───────────────────────────────────────── -->
        <SlidePanel :open="showAddPanel" title="New Review" size="lg" @close="showAddPanel = false">
            <form @submit.prevent="submitReview" class="space-y-5 p-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Cycle <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.cycle_id"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.cycle_id }"
                        >
                            <option value="" disabled>Select cycle</option>
                            <option v-for="c in cycleList" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.cycle_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.cycle_id }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Type <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.type"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
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
                        <input
                            v-model="form.employee_id"
                            type="number"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.employee_id }"
                        />
                        <p v-if="form.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.employee_id }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Reviewer User ID <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.reviewer_id"
                            type="number"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.reviewer_id }"
                        />
                        <p v-if="form.errors.reviewer_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.reviewer_id }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-outline-variant/60 bg-surface-container/40 p-4 space-y-4">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70">Ratings (1.0 – 5.0)</p>
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
                    <textarea v-model="form.strengths" rows="3"
                        placeholder="What did this person do exceptionally well?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Opportunities</label>
                    <textarea v-model="form.opportunities" rows="3"
                        placeholder="What should this person focus on next?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Additional Comments</label>
                    <textarea v-model="form.comments" rows="4"
                        placeholder="Any further context or examples…"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none" />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showAddPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitReview" :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)">
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Save Draft
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── New Cycle SlidePanel ────────────────────────────────────────── -->
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
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70">Optional Deadlines</p>
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
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitCycle" :disabled="cycleForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)">
                        <span v-if="cycleForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Create Cycle
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
