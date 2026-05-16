<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import SearchInput from '@/Components/SearchInput.vue';

const props = defineProps({
    corrections: Object,   // paginated: { data: [], links: [], meta: {} }
    filters:     Object,   // { search, status }
    stats:       Object,   // { pending, approved_week, rejected_week } — optional, derived if absent
});

// ── Filters ────────────────────────────────────────────────────────────────
const localFilters = reactive({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
});

const applyFilters = () => {
    router.get(
        route('attendance.corrections.index'),
        {
            search: localFilters.search || undefined,
            status: localFilters.status || undefined,
        },
        { preserveState: true, replace: true },
    );
};

let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const clearFilters = () => {
    localFilters.search = '';
    localFilters.status = '';
    applyFilters();
};

// ── Stats ──────────────────────────────────────────────────────────────────
const derivedStats = computed(() => {
    if (props.stats) return props.stats;
    const all = props.corrections?.data ?? [];
    const weekAgo = Date.now() - 7 * 86400000;
    return {
        pending:       all.filter(c => c.status === 'pending').length,
        approved_week: all.filter(c => c.status === 'approved' && new Date(c.reviewed_at) > weekAgo).length,
        rejected_week: all.filter(c => c.status === 'rejected' && new Date(c.reviewed_at) > weekAgo).length,
    };
});

// ── Review modal ────────────────────────────────────────────────────────────
const reviewing   = ref(null);
const reviewForm  = useForm({ decision: 'approve', decision_notes: '' });

function openReview(c, decision) {
    reviewing.value          = c;
    reviewForm.decision      = decision;
    reviewForm.decision_notes = '';
}

function submitReview() {
    reviewForm.patch(route('attendance.corrections.review', reviewing.value.id), {
        preserveScroll: true,
        onSuccess: () => { reviewing.value = null; },
    });
}

// ── Kanban columns ──────────────────────────────────────────────────────────
const COLUMNS = [
    { id: 'pending',  label: 'Pending Review', icon: 'pending',      color: 'amber'   },
    { id: 'approved', label: 'Approved',        icon: 'check_circle', color: 'emerald' },
    { id: 'rejected', label: 'Rejected',        icon: 'cancel',       color: 'rose'    },
];

const columns = computed(() =>
    COLUMNS.map(col => ({
        ...col,
        items: (props.corrections?.data ?? []).filter(c => c.status === col.id),
    }))
);

const colHeaderClass = {
    pending:  'bg-amber-50   border-amber-200  text-amber-700',
    approved: 'bg-emerald-50 border-emerald-200 text-emerald-700',
    rejected: 'bg-rose-50    border-rose-200    text-rose-700',
};

const colCountClass = {
    pending:  'bg-amber-100  text-amber-800',
    approved: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-rose-100   text-rose-800',
};

// ── Helpers ─────────────────────────────────────────────────────────────────
const formatDateTime = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const ageBadge = (created) => {
    if (!created) return '';
    const diff = Math.floor((Date.now() - new Date(created).getTime()) / 86400000);
    if (diff === 0) return 'today';
    if (diff === 1) return '1d ago';
    return `${diff}d ago`;
};

const directionIcon = (dir) => dir === 'in' ? 'login' : 'logout';
const directionLabel = (dir) => dir === 'in' ? 'Clock-In' : 'Clock-Out';

const avatarGradients = [
    'linear-gradient(135deg,#205295,#2c74b3)',
    'linear-gradient(135deg,#059669,#34d399)',
    'linear-gradient(135deg,#d97706,#fbbf24)',
    'linear-gradient(135deg,#7c5cff,#a78bfa)',
    'linear-gradient(135deg,#dc2626,#f87171)',
];

const avatarGradient = (id) => avatarGradients[(id ?? 0) % avatarGradients.length];
const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

const hasActiveFilters = computed(() => localFilters.search || localFilters.status);
</script>

<template>
    <Head title="Attendance Corrections" />
    <AuthenticatedLayout active-module="attendance">

        <!-- ── Header ──────────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Attendance Corrections</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Review and decide on employee-submitted attendance correction requests.
                        <span
                            v-if="derivedStats.pending > 0"
                            class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold text-amber-700"
                        >
                            {{ derivedStats.pending }} pending
                        </span>
                    </p>
                </div>
            </div>
        </template>

        <div class="p-6 space-y-6 animate-reveal-up">

            <!-- ── Stats row ─────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    :value="derivedStats.pending"
                    label="Pending Review"
                    icon="pending"
                    color="#d97706"
                />
                <StatCard
                    :value="derivedStats.approved_week"
                    label="Approved This Week"
                    icon="check_circle"
                    color="#059669"
                />
                <StatCard
                    :value="derivedStats.rejected_week"
                    label="Rejected This Week"
                    icon="cancel"
                    color="#dc2626"
                />
            </div>

            <!-- ── Filter strip ───────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] max-w-xs">
                    <SearchInput
                        v-model="localFilters.search"
                        placeholder="Search employee name or ID…"
                    />
                </div>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
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

            <!-- ── Kanban board: 3 columns ────────────────────────────────────── -->
            <div v-if="!corrections?.data?.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-12">
                <EmptyState title="No correction requests found." class="py-4" />
            </div>

            <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div
                    v-for="col in columns"
                    :key="col.id"
                    class="flex flex-col gap-3"
                >
                    <!-- Column header -->
                    <div
                        :class="['flex items-center justify-between rounded-xl border px-4 py-2.5', colHeaderClass[col.id]]"
                    >
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[17px]">{{ col.icon }}</span>
                            <span class="text-[12px] font-black uppercase tracking-[0.08em]">{{ col.label }}</span>
                        </div>
                        <span :class="['rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums', colCountClass[col.id]]">
                            {{ col.items.length }}
                        </span>
                    </div>

                    <!-- Empty column -->
                    <div
                        v-if="!col.items.length"
                        class="rounded-2xl border border-dashed border-outline-variant/40 p-6 text-center text-[12px] text-on-surface-variant/40 font-medium"
                    >
                        No {{ col.label.toLowerCase() }} requests
                    </div>

                    <!-- Correction cards -->
                    <div
                        v-for="(c, i) in col.items"
                        :key="c.id"
                        class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift animate-slide-up-fade"
                        :style="`animation-delay: ${i * 0.05}s`"
                    >
                        <!-- Employee + age -->
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div
                                    class="h-8 w-8 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                    :style="`background: ${avatarGradient(c.employee?.id ?? 0)}`"
                                >
                                    {{ initials(c.employee?.user?.name ?? c.employee?.employee_no) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[13px] font-bold text-on-surface leading-tight truncate">
                                        {{ c.employee?.user?.name ?? '—' }}
                                    </p>
                                    <p class="font-mono text-[10px] text-on-surface-variant/60">{{ c.employee?.employee_no }}</p>
                                </div>
                            </div>
                            <span class="flex-shrink-0 text-[10px] font-bold text-on-surface-variant/50 bg-surface-container rounded-full px-2 py-0.5">
                                {{ ageBadge(c.created_at) }}
                            </span>
                        </div>

                        <!-- Requested time + direction -->
                        <div class="flex items-center gap-2 mb-2.5">
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[10px] font-bold',
                                    c.requested_direction === 'in'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-rose-100 text-rose-700'
                                ]"
                            >
                                <span class="material-symbols-outlined text-[12px]">{{ directionIcon(c.requested_direction) }}</span>
                                {{ directionLabel(c.requested_direction) }}
                            </span>
                            <span class="text-[11px] font-mono text-on-surface-variant">
                                {{ formatDateTime(c.requested_event_at) }}
                            </span>
                        </div>

                        <!-- Reason snippet -->
                        <p class="text-[12px] text-on-surface-variant/80 leading-relaxed line-clamp-2 mb-3" :title="c.reason">
                            {{ c.reason }}
                        </p>

                        <!-- Reviewer attribution (approved/rejected) -->
                        <div
                            v-if="c.status !== 'pending' && c.reviewer"
                            class="mb-3 rounded-lg bg-surface-container-low px-3 py-2 text-[11px] text-on-surface-variant"
                        >
                            <span class="font-bold">{{ c.reviewer?.name }}</span>
                            <span class="mx-1">·</span>
                            {{ formatDate(c.reviewed_at) }}
                            <template v-if="c.decision_notes">
                                <span class="block mt-1 text-on-surface-variant/60 italic line-clamp-1">{{ c.decision_notes }}</span>
                            </template>
                        </div>

                        <!-- Submitted timestamp -->
                        <div class="text-[10px] text-on-surface-variant/40 mb-3 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[12px]">schedule</span>
                            Submitted {{ formatDateTime(c.created_at) }}
                        </div>

                        <!-- Approve/Reject actions (pending only) -->
                        <div v-if="c.status === 'pending'" class="flex items-center gap-2">
                            <button
                                @click="openReview(c, 'approve')"
                                class="flex-1 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 px-3 py-1.5 text-[12px] font-bold hover:bg-emerald-100 transition-colors flex items-center justify-center gap-1.5"
                            >
                                <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                Approve
                            </button>
                            <button
                                @click="openReview(c, 'reject')"
                                class="flex-1 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 px-3 py-1.5 text-[12px] font-bold hover:bg-rose-100 transition-colors flex items-center justify-center gap-1.5"
                            >
                                <span class="material-symbols-outlined text-[14px]">cancel</span>
                                Reject
                            </button>
                        </div>

                        <!-- Decision badge (non-pending) -->
                        <div v-else>
                            <StatusBadge :status="c.status" type="generic" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="corrections?.links?.length > 3" class="flex justify-end">
                <Pagination :links="corrections.links" />
            </div>
        </div>

        <!-- ── Review confirmation modal ─────────────────────────────────────── -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="reviewing"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                @click.self="reviewing = null"
            >
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="reviewing = null"></div>

                <!-- Modal -->
                <div class="relative z-10 w-full max-w-md rounded-2xl bg-surface-container-lowest border border-outline-variant/60 shadow-2xl animate-scale-in">

                    <!-- Header -->
                    <div
                        :class="[
                            'flex items-center justify-between gap-3 px-6 py-4 rounded-t-2xl border-b',
                            reviewForm.decision === 'approve'
                                ? 'border-emerald-100 bg-emerald-50'
                                : 'border-rose-100 bg-rose-50'
                        ]"
                    >
                        <div class="flex items-center gap-2.5">
                            <span
                                :class="[
                                    'material-symbols-outlined text-[22px]',
                                    reviewForm.decision === 'approve' ? 'text-emerald-600' : 'text-rose-600'
                                ]"
                            >{{ reviewForm.decision === 'approve' ? 'check_circle' : 'cancel' }}</span>
                            <h3
                                :class="[
                                    'text-[16px] font-black',
                                    reviewForm.decision === 'approve' ? 'text-emerald-800' : 'text-rose-800'
                                ]"
                            >
                                {{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }} Correction
                            </h3>
                        </div>
                        <button
                            @click="reviewing = null"
                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="px-6 py-5 space-y-4">
                        <!-- Summary -->
                        <div class="rounded-xl border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-[13px] space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[15px] text-on-surface-variant/60">badge</span>
                                <span class="font-semibold text-on-surface">{{ reviewing.employee?.user?.name ?? reviewing.employee?.employee_no }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-on-surface-variant">
                                <span class="material-symbols-outlined text-[15px] text-on-surface-variant/60">{{ directionIcon(reviewing.requested_direction) }}</span>
                                <span class="font-mono">{{ directionLabel(reviewing.requested_direction) }}</span>
                                <span class="text-on-surface-variant/60">at</span>
                                <span>{{ formatDateTime(reviewing.requested_event_at) }}</span>
                            </div>
                            <p class="text-[12px] text-on-surface-variant/70 italic line-clamp-2">{{ reviewing.reason }}</p>
                        </div>

                        <form @submit.prevent="submitReview" class="space-y-4">
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                    Decision Notes
                                    <span v-if="reviewForm.decision === 'reject'" class="text-red-500">*</span>
                                    <span v-else class="ml-1 font-normal text-on-surface-variant/60">(optional)</span>
                                </label>
                                <textarea
                                    v-model="reviewForm.decision_notes"
                                    :required="reviewForm.decision === 'reject'"
                                    rows="3"
                                    :placeholder="reviewForm.decision === 'approve'
                                        ? 'Add any notes for the employee (optional)…'
                                        : 'Explain why the request is being rejected…'"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                                    :class="{ 'border-red-400': reviewForm.errors.decision_notes }"
                                />
                                <p v-if="reviewForm.errors.decision_notes" class="mt-1 text-[11px] text-red-500">{{ reviewForm.errors.decision_notes }}</p>
                            </div>

                            <div class="flex items-center justify-end gap-3 pt-2">
                                <button
                                    type="button"
                                    @click="reviewing = null"
                                    class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="reviewForm.processing"
                                    :class="[
                                        'btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60',
                                    ]"
                                    :style="reviewForm.decision === 'approve'
                                        ? 'background: linear-gradient(135deg,#059669,#34d399)'
                                        : 'background: linear-gradient(135deg,#dc2626,#f87171)'"
                                >
                                    <span v-if="reviewForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                    <span>{{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Transition>

    </AuthenticatedLayout>
</template>
