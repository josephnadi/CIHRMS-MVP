<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import StatCard from '@/Components/StatCard.vue';
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

// ── Filters ──────────────────────────────────────────────────────────────────
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

// ── Stats ────────────────────────────────────────────────────────────────────
const goalList = computed(() => props.goals?.data ?? []);
const cycleList = computed(() => props.cycles?.data ?? props.cycles ?? []);

const stats = computed(() => {
    const data = goalList.value;
    return {
        total:     props.goals?.meta?.total ?? data.length,
        active:    data.filter(g => g.status === 'active').length,
        atRisk:    data.filter(g => g.status === 'at_risk').length,
        completed: data.filter(g => g.status === 'completed').length,
    };
});

// ── New goal panel ───────────────────────────────────────────────────────────
const showAddPanel = ref(false);

// Auto-open from ?new=1 (Quick Action deep link)
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

// ── Check-in panel ───────────────────────────────────────────────────────────
const showCheckinPanel = ref(false);
const checkinGoal = ref(null);
const checkinForm = useForm({
    progress_pct:  '',
    current_value: '',
    narrative:     '',
    mood:          'green',
});

const openCheckin = (goal, ev) => {
    ev?.stopPropagation();
    checkinGoal.value = goal;
    checkinForm.reset();
    checkinForm.current_value = goal.current_value ?? '';
    checkinForm.progress_pct = goal.progress_pct ?? '';
    showCheckinPanel.value = true;
};

const submitCheckin = () => {
    checkinForm.post(route('performance.goals.checkins.store', checkinGoal.value.id), {
        onSuccess: () => {
            checkinForm.reset();
            showCheckinPanel.value = false;
            checkinGoal.value = null;
        },
    });
};

// ── Delete ───────────────────────────────────────────────────────────────────
const showDeleteDialog = ref(false);
const selectedId = ref(null);

const confirmDelete = (id, ev) => {
    ev.stopPropagation();
    selectedId.value = id;
    showDeleteDialog.value = true;
};

const doDelete = () => {
    router.delete(route('performance.goals.destroy', selectedId.value), {
        onFinish: () => {
            showDeleteDialog.value = false;
            selectedId.value = null;
        },
    });
};

// ── Helpers ──────────────────────────────────────────────────────────────────
const moodMeta = {
    green: { color: '#059669', icon: 'sentiment_very_satisfied', label: 'On track' },
    amber: { color: '#d97706', icon: 'sentiment_neutral',         label: 'At risk' },
    red:   { color: '#dc2626', icon: 'sentiment_very_dissatisfied', label: 'Off track' },
};

const statusBadge = (g) => ({
    background: `${g.status_color}1a`,
    color: g.status_color,
    border: `1px solid ${g.status_color}33`,
});

const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const progressColor = (pct) => {
    if (pct >= 75) return '#059669';
    if (pct >= 40) return '#0051d5';
    if (pct > 0)   return '#d97706';
    return '#9ca3af';
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
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Goals</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        OKR-style outcomes tracked through periodic check-ins.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('performance.reviews.index')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">rate_review</span>
                        Reviews
                    </Link>
                    <button
                        @click="showAddPanel = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Goal
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard :value="stats.total"     label="Total Goals" icon="track_changes" color="#0051d5" />
                <StatCard :value="stats.active"    label="Active"      icon="trending_up"   color="#316bf3" />
                <StatCard :value="stats.atRisk"    label="At Risk"     icon="warning"       color="#d97706" />
                <StatCard :value="stats.completed" label="Completed"   icon="check_circle"  color="#059669" />
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] max-w-xs">
                    <SearchInput v-model="localFilters.search" placeholder="Search goals by title…" />
                </div>

                <select
                    v-model="localFilters.employee_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Employees</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.label }}</option>
                </select>

                <select
                    v-model="localFilters.cycle_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Cycles</option>
                    <option v-for="c in cycleList" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="at_risk">At Risk</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <button
                    v-if="localFilters.search || localFilters.employee_id || localFilters.cycle_id || localFilters.status"
                    @click="clearFilters"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- Goal cards -->
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
                            style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            New Goal
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="goal in goalList"
                    :key="goal.id"
                    class="group relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5 transition-all hover:shadow-lifted hover:-translate-y-0.5 cursor-pointer"
                    @click="openCheckin(goal, $event)"
                >
                    <!-- Header row -->
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold text-on-surface-variant/70 mb-1">
                                {{ goal.employee?.name ?? '—' }}
                                <span v-if="goal.cycle?.name" class="ml-1 text-on-surface-variant/45">· {{ goal.cycle.name }}</span>
                            </p>
                            <h3 class="text-[14px] font-bold text-on-surface leading-snug line-clamp-2">{{ goal.title }}</h3>
                        </div>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
                            :style="statusBadge(goal)"
                        >{{ goal.status_label }}</span>
                    </div>

                    <!-- Progress bar -->
                    <div class="mb-3">
                        <div class="flex items-center justify-between text-[11px] font-semibold text-on-surface-variant/70 mb-1">
                            <span>Progress</span>
                            <span class="font-mono text-on-surface">{{ Math.round(goal.progress_pct ?? 0) }}%</span>
                        </div>
                        <div class="h-2 rounded-full bg-surface-container overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :style="`width:${Math.min(100, Math.max(0, goal.progress_pct ?? 0))}%;background:${progressColor(goal.progress_pct ?? 0)}`"
                            ></div>
                        </div>
                    </div>

                    <!-- Values + meta -->
                    <div class="grid grid-cols-3 gap-2 text-[11px] mb-3">
                        <div>
                            <p class="text-on-surface-variant/60 font-semibold">Target</p>
                            <p class="text-on-surface font-mono font-bold">
                                {{ goal.target_value ?? '—' }}
                                <span class="text-on-surface-variant/50 font-normal">{{ goal.unit }}</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-on-surface-variant/60 font-semibold">Current</p>
                            <p class="text-on-surface font-mono font-bold">
                                {{ goal.current_value ?? 0 }}
                                <span class="text-on-surface-variant/50 font-normal">{{ goal.unit }}</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-on-surface-variant/60 font-semibold">Due</p>
                            <p class="text-on-surface font-semibold">{{ formatDate(goal.due_at) }}</p>
                        </div>
                    </div>

                    <!-- Last check-in -->
                    <div v-if="goal.last_checkin" class="flex items-center gap-2 rounded-lg bg-surface-container/40 px-2.5 py-1.5">
                        <span
                            class="material-symbols-outlined text-[16px]"
                            :style="`color:${moodMeta[goal.last_checkin.mood ?? 'green'].color};font-variation-settings:'FILL' 1`"
                        >{{ moodMeta[goal.last_checkin.mood ?? 'green'].icon }}</span>
                        <p class="text-[11px] text-on-surface-variant flex-1">
                            Last check-in
                            <span class="font-semibold text-on-surface">{{ formatDate(goal.last_checkin.recorded_at) }}</span>
                        </p>
                    </div>
                    <p v-else class="text-[11px] italic text-on-surface-variant/50">No check-ins yet</p>

                    <!-- Actions -->
                    <div class="mt-4 flex items-center justify-between border-t border-outline-variant/40 pt-3" @click.stop>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">{{ goal.cadence_label }}</span>
                        <div class="flex items-center gap-1">
                            <button
                                @click="openCheckin(goal, $event)"
                                class="flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-secondary hover:bg-secondary/10 transition-colors"
                                title="Record check-in"
                            >
                                <span class="material-symbols-outlined text-[15px]">check_box</span>
                                Check-in
                            </button>
                            <button
                                v-if="canManage"
                                @click="confirmDelete(goal.id, $event)"
                                class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-red-500/10 hover:text-red-600 transition-colors"
                                title="Delete"
                            >
                                <span class="material-symbols-outlined text-[16px]">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="goals?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing <span class="font-semibold text-on-surface">{{ goals.meta?.from }}</span> – <span class="font-semibold text-on-surface">{{ goals.meta?.to }}</span>
                    of <span class="font-semibold text-on-surface">{{ goals.meta?.total }}</span>
                </p>
                <Pagination :links="goals.links" />
            </div>
        </div>

        <!-- ── New Goal SlidePanel ─────────────────────────────────────────── -->
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
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Weight (0–100)</label>
                        <input
                            v-model="form.weight"
                            type="number"
                            min="0" max="100"
                            placeholder="20"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Unit</label>
                        <input
                            v-model="form.unit"
                            type="text"
                            placeholder="hours, %, count"
                            maxlength="20"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Target Value</label>
                        <input
                            v-model="form.target_value"
                            type="number"
                            step="0.01" min="0"
                            placeholder="4"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Current Value</label>
                        <input
                            v-model="form.current_value"
                            type="number"
                            step="0.01" min="0"
                            placeholder="0"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Starts On</label>
                        <input
                            v-model="form.starts_at"
                            type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Due On</label>
                        <input
                            v-model="form.due_at"
                            type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.due_at }"
                        />
                        <p v-if="form.errors.due_at" class="mt-1 text-[11px] text-red-500">{{ form.errors.due_at }}</p>
                    </div>
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
                        @click="submitGoal"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Save Goal
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── Check-in SlidePanel ─────────────────────────────────────────── -->
        <SlidePanel :open="showCheckinPanel" :title="checkinGoal ? `Check-in · ${checkinGoal.title}` : 'Check-in'" size="md" @close="showCheckinPanel = false">
            <form v-if="checkinGoal" @submit.prevent="submitCheckin" class="space-y-5 p-6">
                <div class="rounded-xl border border-outline-variant/60 bg-surface-container/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60">Goal</p>
                    <p class="mt-1 text-[13px] font-bold text-on-surface">{{ checkinGoal.title }}</p>
                    <p class="text-[11px] text-on-surface-variant/70">{{ checkinGoal.employee?.name }} · target {{ checkinGoal.target_value ?? '—' }} {{ checkinGoal.unit }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Current Value</label>
                        <input
                            v-model="checkinForm.current_value"
                            type="number"
                            step="0.01" min="0"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Progress %</label>
                        <input
                            v-model="checkinForm.progress_pct"
                            type="number"
                            step="1" min="0" max="100"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-2 block">Mood</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="(meta, key) in moodMeta" :key="key"
                            type="button"
                            @click="checkinForm.mood = key"
                            class="flex flex-col items-center gap-1 rounded-xl border-2 px-3 py-3 transition-all"
                            :style="checkinForm.mood === key ? `border-color:${meta.color};background:${meta.color}1a` : 'border-color:transparent;background:rgb(248 249 251 / 0.5)'"
                        >
                            <span
                                class="material-symbols-outlined text-[26px]"
                                :style="`color:${meta.color};font-variation-settings:'FILL' 1`"
                            >{{ meta.icon }}</span>
                            <span class="text-[11px] font-bold" :style="`color:${meta.color}`">{{ meta.label }}</span>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Narrative</label>
                    <textarea
                        v-model="checkinForm.narrative"
                        rows="4"
                        placeholder="What happened since last check-in? What's blocking progress?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showCheckinPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >Cancel</button>
                    <button
                        @click="submitCheckin"
                        :disabled="checkinForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
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
