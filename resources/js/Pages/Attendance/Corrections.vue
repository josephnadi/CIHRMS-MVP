<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    corrections: Object,
    filters:     Object,
    stats:       Object,
});

// ── Filters ──
const localFilters = reactive({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
});

const applyFilters = () => router.get(
    route('attendance.corrections.index'),
    { search: localFilters.search || undefined, status: localFilters.status || undefined },
    { preserveState: true, replace: true },
);

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

const hasActiveFilters = computed(() => localFilters.search || localFilters.status);

// ── Stats ──
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

// Decision composition (this period)
const totals = computed(() => ({
    pending:  derivedStats.value.pending  ?? 0,
    approved: derivedStats.value.approved_week ?? 0,
    rejected: derivedStats.value.rejected_week ?? 0,
}));

const totalCount = computed(() => totals.value.pending + totals.value.approved + totals.value.rejected);
const approvalRate = computed(() => {
    const decided = totals.value.approved + totals.value.rejected;
    return decided ? Math.round((totals.value.approved / decided) * 100) : 0;
});

// Oldest pending (SLA flag)
const oldestPendingDays = computed(() => {
    const pending = (props.corrections?.data ?? []).filter(c => c.status === 'pending');
    if (!pending.length) return null;
    const oldest = pending.reduce((min, c) => new Date(c.created_at) < new Date(min.created_at) ? c : min, pending[0]);
    return Math.floor((Date.now() - new Date(oldest.created_at).getTime()) / 86400000);
});

// ── Review modal ──
const reviewing  = ref(null);
const reviewForm = useForm({ decision: 'approve', decision_notes: '' });

function openReview(c, decision) {
    reviewing.value = c;
    reviewForm.decision = decision;
    reviewForm.decision_notes = '';
}

function submitReview() {
    reviewForm.patch(route('attendance.corrections.review', reviewing.value.id), {
        preserveScroll: true,
        onSuccess: () => { reviewing.value = null; },
    });
}

// ── Kanban columns ──
const COLUMNS = [
    { id: 'pending',  label: 'Pending review',  icon: 'pending',      tone: 'amber' },
    { id: 'approved', label: 'Approved',        icon: 'check_circle', tone: 'green' },
    { id: 'rejected', label: 'Rejected',        icon: 'cancel',       tone: 'rose'  },
];

const columns = computed(() =>
    COLUMNS.map(col => ({
        ...col,
        items: (props.corrections?.data ?? []).filter(c => c.status === col.id),
    }))
);

const columnHeader = (id) => ({
    pending:  { bg: 'bg-amber-50 dark:bg-amber-900/20', border: 'border-amber-200 dark:border-amber-800/40', text: 'text-amber-700 dark:text-amber-300', count: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200', accent: '#d97706' },
    approved: { bg: 'bg-green-50 dark:bg-green-900/20', border: 'border-green-200 dark:border-green-800/40', text: 'text-green-700 dark:text-green-300', count: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200', accent: '#059669' },
    rejected: { bg: 'bg-rose-50 dark:bg-rose-900/20',   border: 'border-rose-200 dark:border-rose-800/40',   text: 'text-rose-700 dark:text-rose-300',   count: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',     accent: '#dc2626' },
}[id]);

// ── Helpers ──
const formatDateTime = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
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
const directionLabel = (dir) => dir === 'in' ? 'Clock-in' : 'Clock-out';

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};
</script>

<template>
    <Head title="Attendance Corrections" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Attendance corrections</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Review and decide on employee-submitted clock-in / clock-out correction requests
                            <span v-if="derivedStats.pending > 0" class="ml-2 inline-flex items-center gap-1.5 rounded-full bg-amber-100 dark:bg-amber-900/30 px-2.5 py-0.5 text-[11px] font-black text-amber-700 dark:text-amber-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500 live-dot"></span>
                                {{ derivedStats.pending }} pending
                            </span>
                        </p>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- Sub-nav -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <Link :href="route('attendance.index')"
                          class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-on-surface-variant hover:border-secondary/40 hover:text-secondary transition-colors">
                        <span class="material-symbols-outlined text-[15px]">today</span> Daily
                    </Link>
                    <span class="inline-flex items-center gap-1.5 rounded-xl border bg-secondary/8 border-secondary/25 px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-secondary">
                        <span class="material-symbols-outlined text-[15px]">fact_check</span> Corrections
                    </span>
                    <Link v-if="$page.props.auth.permissions?.includes('attendance.shift_manage')"
                          :href="route('attendance.shifts.index')"
                          class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-on-surface-variant hover:border-secondary/40 hover:text-secondary transition-colors">
                        <span class="material-symbols-outlined text-[15px]">schedule</span> Shifts
                    </Link>
                </div>

                <!-- ── Hero banner ── -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                     style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.18),transparent 70%)"></div>
                    <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.06)"></div>

                    <div class="relative flex flex-wrap items-center justify-between gap-8">
                        <div>
                            <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(18,217,227,0.7)">Review queue · this period</p>
                            <h2 class="text-3xl font-black leading-tight">
                                <em class="not-italic" style="color:#ffd700">{{ derivedStats.pending }}</em> awaiting review
                                <span class="text-base font-bold opacity-50">· {{ approvalRate }}% approval rate</span>
                            </h2>
                            <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                                <template v-if="oldestPendingDays !== null && oldestPendingDays >= 3">
                                    <span style="color:#fbbf24">Oldest pending: {{ oldestPendingDays }} days</span> — consider reviewing soon to keep payroll on track.
                                </template>
                                <template v-else-if="derivedStats.pending > 0">
                                    All pending requests are within the {{ oldestPendingDays ?? 0 }}-day window. Decisions feed back into the affected attendance summary on approval.
                                </template>
                                <template v-else>
                                    No pending requests — the queue is clear.
                                </template>
                            </p>
                        </div>
                        <div class="flex items-center gap-8 flex-shrink-0">
                            <div v-for="kpi in [
                                { label: 'Pending',  val: totals.pending,  color: '#ffd700' },
                                { label: 'Approved', val: totals.approved, color: '#12d9e3' },
                                { label: 'Rejected', val: totals.rejected, color: '#dc2626' },
                            ]" :key="kpi.label" class="text-center">
                                <p class="text-3xl font-black leading-none tabular-nums" :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── KPI tiles ── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Pending review',    val: derivedStats.pending,       sub: 'In queue',          cls: 'icon-gold',    icon: 'pending' },
                        { label: 'Approved · 7 days', val: derivedStats.approved_week, sub: 'Last week',         cls: 'icon-cyan',    icon: 'check_circle' },
                        { label: 'Rejected · 7 days', val: derivedStats.rejected_week, sub: 'Last week',         cls: 'icon-danger',  icon: 'cancel' },
                        { label: 'Approval rate',     val: approvalRate + '%',          sub: 'Decided requests', cls: 'icon-magenta', icon: 'thumb_up' },
                    ]" :key="card.label"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                        <div class="icon-tile" :class="card.cls">
                            <span class="material-symbols-outlined">{{ card.icon }}</span>
                        </div>
                        <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1 text-[28px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                        <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                    </div>
                </div>

                <!-- ── Filter strip ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                        </div>
                        <div class="relative flex-1 min-w-[200px] max-w-xs">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">search</span>
                            <input aria-label="Search" v-model="localFilters.search" placeholder="Search employee name or staff ID…"
                                   class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                        <select aria-label="Status" v-model="localFilters.status" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All statuses</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <button v-if="hasActiveFilters" @click="clearFilters"
                                class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">close</span>
                            Clear
                        </button>
                    </div>
                </div>

                <!-- ── Kanban ── -->
                <div v-if="!corrections?.data?.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-16">
                    <EmptyState title="No correction requests found"
                                description="Either nothing matches your filters, or no requests have been submitted yet. Employees raise correction requests from their personal attendance page." />
                </div>

                <div v-else class="grid grid-cols-1 gap-5 md:grid-cols-3 animate-reveal-up">
                    <div v-for="col in columns" :key="col.id" class="flex flex-col gap-3">

                        <!-- Column header -->
                        <div :class="['flex items-center justify-between rounded-xl border px-4 py-2.5', columnHeader(col.id).bg, columnHeader(col.id).border]">
                            <div class="flex items-center gap-2" :class="columnHeader(col.id).text">
                                <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">{{ col.icon }}</span>
                                <span class="text-[11px] font-black uppercase tracking-[0.1em]">{{ col.label }}</span>
                            </div>
                            <span :class="['rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums', columnHeader(col.id).count]">
                                {{ col.items.length }}
                            </span>
                        </div>

                        <!-- Empty -->
                        <div v-if="!col.items.length"
                             class="rounded-2xl border border-dashed border-outline-variant/40 p-6 text-center text-[12px] text-on-surface-variant/40 font-medium italic">
                            No {{ col.label.toLowerCase() }}
                        </div>

                        <!-- Cards -->
                        <div v-for="(c, i) in col.items" :key="c.id"
                             class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 transition-all hover:shadow-md hover:-translate-y-0.5"
                             :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.04}s;border-left:3px solid ${columnHeader(col.id).accent};`">

                            <!-- Employee + age -->
                            <div class="flex items-start justify-between gap-2 mb-3">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[10.5px] font-black text-secondary flex-shrink-0">
                                        {{ initials(c.employee?.user?.name ?? c.employee?.employee_no) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[12.5px] font-bold text-primary leading-tight truncate">{{ c.employee?.user?.name ?? '—' }}</p>
                                        <p class="text-[10px] text-on-surface-variant truncate">{{ c.employee?.employee_no }}</p>
                                    </div>
                                </div>
                                <span class="flex-shrink-0 text-[10px] font-bold text-on-surface-variant/60 bg-surface-container-low rounded-full px-2 py-0.5">{{ ageBadge(c.created_at) }}</span>
                            </div>

                            <!-- Requested entry -->
                            <div class="rounded-xl bg-surface-container-low/60 border border-outline-variant/40 px-3 py-2.5 mb-3 space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                          :class="c.requested_direction === 'in' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                        <span class="material-symbols-outlined text-[12px]">{{ directionIcon(c.requested_direction) }}</span>
                                        {{ directionLabel(c.requested_direction) }}
                                    </span>
                                    <span class="font-mono text-[11.5px] tabular-nums font-bold text-on-surface">{{ formatDateTime(c.requested_event_at) }}</span>
                                </div>
                                <p class="text-[11.5px] text-on-surface-variant leading-relaxed line-clamp-2" :title="c.reason">{{ c.reason }}</p>
                            </div>

                            <!-- Reviewer attribution -->
                            <div v-if="c.status !== 'pending' && c.reviewer"
                                 class="mb-3 rounded-xl bg-surface-container-low/40 px-3 py-2 text-[11px]">
                                <div class="flex items-center gap-1.5 text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[14px]" :style="`color:${columnHeader(col.id).accent}`">verified</span>
                                    <span class="font-bold text-on-surface">{{ c.reviewer?.name }}</span>
                                    <span class="mx-1 opacity-50">·</span>
                                    <span>{{ formatDate(c.reviewed_at) }}</span>
                                </div>
                                <p v-if="c.decision_notes" class="mt-1 text-[11px] text-on-surface-variant/80 italic line-clamp-2">{{ c.decision_notes }}</p>
                            </div>

                            <!-- Submitted timestamp -->
                            <div class="text-[10px] text-on-surface-variant/50 mb-3 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[12px]">schedule</span>
                                Submitted {{ formatDateTime(c.created_at) }}
                            </div>

                            <!-- Actions (pending only) -->
                            <div v-if="c.status === 'pending'" class="flex items-center gap-2">
                                <button @click="openReview(c, 'approve')"
                                        class="flex-1 rounded-xl bg-green-50 hover:bg-green-100 border border-green-200 text-green-700 px-3 py-1.5 text-[12px] font-black transition-colors flex items-center justify-center gap-1.5">
                                    <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                    Approve
                                </button>
                                <button @click="openReview(c, 'reject')"
                                        class="flex-1 rounded-xl bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-700 px-3 py-1.5 text-[12px] font-black transition-colors flex items-center justify-center gap-1.5">
                                    <span class="material-symbols-outlined text-[14px]">cancel</span>
                                    Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="corrections?.meta?.links?.length > 3 || corrections?.links?.length > 3" class="flex justify-end">
                    <Pagination :links="corrections?.meta?.links ?? corrections?.links ?? []" />
                </div>
            </div>

            <!-- ── Review modal ── -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div v-if="reviewing"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4"
                     @click.self="reviewing = null">
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="reviewing = null"></div>

                    <div class="relative z-10 w-full max-w-md rounded-2xl bg-surface-container-lowest border border-outline-variant/60 shadow-lifted-lg animate-scale-in overflow-hidden">

                        <!-- Header -->
                        <div :class="['flex items-center justify-between gap-3 px-6 py-4 border-b',
                                      reviewForm.decision === 'approve'
                                        ? 'border-green-100 bg-gradient-to-r from-green-50 to-cyan-50/40 dark:from-green-900/20 dark:to-cyan-900/10'
                                        : 'border-rose-100 bg-gradient-to-r from-rose-50 to-amber-50/40 dark:from-rose-900/20 dark:to-amber-900/10']">
                            <div class="flex items-center gap-2.5">
                                <span class="material-symbols-outlined text-[22px]"
                                      :class="reviewForm.decision === 'approve' ? 'text-green-600' : 'text-rose-600'"
                                      style="font-variation-settings:'FILL' 1">
                                    {{ reviewForm.decision === 'approve' ? 'check_circle' : 'cancel' }}
                                </span>
                                <h3 class="text-[16px] font-black"
                                    :class="reviewForm.decision === 'approve' ? 'text-green-800 dark:text-green-300' : 'text-rose-800 dark:text-rose-300'">
                                    {{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }} correction
                                </h3>
                            </div>
                            <button @click="reviewing = null"
                                    class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors">
                                <span class="material-symbols-outlined text-[18px]">close</span>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="px-6 py-5 space-y-4">
                            <!-- Summary -->
                            <div class="rounded-xl border border-outline-variant/40 bg-surface-container-low/60 px-4 py-3 space-y-1.5 text-[13px]">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px] text-secondary">badge</span>
                                    <span class="font-bold text-primary">{{ reviewing.employee?.user?.name ?? reviewing.employee?.employee_no }}</span>
                                    <span class="text-on-surface-variant text-[11.5px]">· {{ reviewing.employee?.employee_no }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[15px]">{{ directionIcon(reviewing.requested_direction) }}</span>
                                    <span class="font-bold">{{ directionLabel(reviewing.requested_direction) }}</span>
                                    <span class="text-on-surface-variant/60">at</span>
                                    <span class="font-mono tabular-nums font-bold text-primary">{{ formatDateTime(reviewing.requested_event_at) }}</span>
                                </div>
                                <p class="text-[12px] text-on-surface-variant/80 italic line-clamp-3 pt-1 border-t border-outline-variant/30">{{ reviewing.reason }}</p>
                            </div>

                            <form @submit.prevent="submitReview" class="space-y-4">
                                <div>
                                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                                        Decision notes
                                        <span v-if="reviewForm.decision === 'reject'" class="text-rose-500">*</span>
                                        <span v-else class="ml-1 font-normal text-on-surface-variant/60 normal-case">(optional)</span>
                                    </label>
                                    <textarea aria-label="Decision notes * (optional)" v-model="reviewForm.decision_notes" rows="3"
                                              :required="reviewForm.decision === 'reject'"
                                              :placeholder="reviewForm.decision === 'approve'
                                                ? 'Add any notes for the employee (optional)…'
                                                : 'Explain why the request is being rejected — the employee will see this.'"
                                              class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20 resize-none"
                                              :class="{ 'border-rose-400': reviewForm.errors.decision_notes }"></textarea>
                                    <p v-if="reviewForm.errors.decision_notes" class="mt-1 text-[11px] text-rose-500">{{ reviewForm.errors.decision_notes }}</p>
                                </div>

                                <div class="flex items-center justify-end gap-3 pt-2">
                                    <button type="button" @click="reviewing = null"
                                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                                        Cancel
                                    </button>
                                    <button type="submit" :disabled="reviewForm.processing"
                                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                                            :style="reviewForm.decision === 'approve'
                                                ? 'background:linear-gradient(135deg,#059669,#34d399)'
                                                : 'background:linear-gradient(135deg,#dc2626,#f87171)'">
                                        <span v-if="reviewForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                        <span v-else class="material-symbols-outlined text-[16px]">{{ reviewForm.decision === 'approve' ? 'check_circle' : 'cancel' }}</span>
                                        {{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </Transition>

    </div>
</template>
