<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import KanbanBoard from '@/Components/KanbanBoard.vue';

const props = defineProps({
    tickets:      Object,
    staff:        Array,
    filters:      Object,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('tickets.manage');
});

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const localFilters = reactive({
    search:      props.filters?.search      ?? '',
    status:      props.filters?.status      ?? '',
    priority:    props.filters?.priority    ?? '',
    assigned_to: props.filters?.assigned_to ?? '',
    overdue:     props.filters?.overdue ? '1' : '',
});

const applyFilters = () => {
    router.get(
        route('tickets.index'),
        {
            search:      localFilters.search      || undefined,
            status:      localFilters.status      || undefined,
            priority:    localFilters.priority    || undefined,
            assigned_to: localFilters.assigned_to || undefined,
            overdue:     localFilters.overdue     || undefined,
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
    localFilters.priority = '';
    localFilters.assigned_to = '';
    localFilters.overdue = '';
    applyFilters();
};

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const stats = computed(() => {
    const data = props.tickets?.data ?? [];
    return {
        total:      props.tickets?.meta?.total ?? data.length,
        open:       data.filter(t => t.status === 'open').length,
        inProgress: data.filter(t => t.status === 'in_progress').length,
        overdue:    data.filter(t => t.is_overdue).length,
    };
});

// â”€â”€ Panels / dialogs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showAddPanel     = ref(false);
const showDeleteDialog = ref(false);
const selectedId       = ref(null);

// Auto-open create panel when arriving via Quick Action (?new=1)
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('new') === '1') {
        showAddPanel.value = true;
    }
});

const form = useForm({
    title:       '',
    description: '',
    priority:    'medium',
    due_at:      '',
});

const submit = () => {
    form.post(route('tickets.store'), {
        onSuccess: () => {
            form.reset();
            showAddPanel.value = false;
        },
    });
};

const confirmDelete = (id, e) => {
    e.stopPropagation();
    selectedId.value = id;
    showDeleteDialog.value = true;
};

const doDelete = () => {
    router.delete(route('tickets.destroy', selectedId.value), {
        onFinish: () => {
            showDeleteDialog.value = false;
            selectedId.value = null;
        },
    });
};

// â”€â”€ Inline status / assignment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const quickAssign = (ticket, userId) => {
    router.patch(route('tickets.update', ticket.id), {
        status:      ticket.status,
        assigned_to: userId || null,
    }, { preserveScroll: true });
};

const quickStatus = (ticket, status) => {
    router.patch(route('tickets.update', ticket.id), {
        status,
        assigned_to: ticket.assigned_to?.id ?? null,
    }, { preserveScroll: true });
};

// Inline priority change — partial PATCH, only `priority` is sent
const PRIORITY_OPTIONS = ['critical', 'high', 'medium', 'low'];
const priorityMenuFor = ref(null);

const quickPriority = (ticket, priority) => {
    if (ticket.priority === priority) return;
    router.patch(route('tickets.update', ticket.id), {
        priority,
    }, { preserveScroll: true });
};

// Close the priority menu on any outside click. Lifecycle-bound so the
// listener doesn't leak when the user navigates away.
const onOutsideTicketPriorityClick = (e) => {
    if (! e.target.closest('.ticket-priority-menu')) priorityMenuFor.value = null;
};
onMounted(() => document.addEventListener('click', onOutsideTicketPriorityClick));
onBeforeUnmount(() => document.removeEventListener('click', onOutsideTicketPriorityClick));

// â”€â”€ View toggle (List | Board) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const VIEW_STORAGE_KEY = 'cihrms.tickets.view';
const view = ref('board'); // default per request â€” Kanban first

onMounted(() => {
    try {
        const stored = localStorage.getItem(VIEW_STORAGE_KEY);
        if (stored === 'list' || stored === 'board') view.value = stored;
    } catch (e) { /* localStorage unavailable */ }
});

watch(view, (v) => {
    try { localStorage.setItem(VIEW_STORAGE_KEY, v); } catch (e) { /* noop */ }
});

// â”€â”€ Kanban columns derived from tickets.data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const STATUS_COLUMNS = [
    { id: 'open',        label: 'Open',        color: 'blue'   },
    { id: 'in_progress', label: 'In Progress', color: 'violet' },
    { id: 'resolved',    label: 'Resolved',    color: 'green'  },
    { id: 'closed',      label: 'Closed',      color: 'gray'   },
];

const kanbanColumns = computed(() => STATUS_COLUMNS.map(col => ({
    ...col,
    items: (props.tickets?.data ?? []).filter(t => t.status === col.id),
})));

// Optimistic move handler â€” fires PATCH and lets Inertia reload props
const onTicketMove = ({ itemId, toColumnId }) => {
    const ticket = (props.tickets?.data ?? []).find(t => t.id === itemId);
    if (!ticket) return;
    router.patch(route('tickets.update', itemId), {
        status:      toColumnId,
        assigned_to: ticket.assigned_to?.id ?? null,
    }, { preserveScroll: true, preserveState: true });
};

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const priorityClasses = {
    critical: 'bg-red-500/15 text-red-600 dark:text-red-400',
    high:     'bg-orange-500/15 text-orange-600 dark:text-orange-400',
    medium:   'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    low:      'bg-slate-400/15 text-slate-600 dark:text-slate-300',
};

const priorityIcon = {
    critical: 'priority_high',
    high:     'keyboard_double_arrow_up',
    medium:   'horizontal_rule',
    low:      'keyboard_double_arrow_down',
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
};

const daysSince = (d) => {
    if (!d) return '';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 86400000);
    if (diff === 0) return 'today';
    if (diff === 1) return '1d ago';
    return `${diff}d ago`;
};

// ── Jira-style issue key. Each ticket reads as "SD-{id}" (Service Desk). ──
const issueKey = (id) => 'SD-' + String(id).padStart(3, '0');

// ── Avatar helper — initials + deterministic palette colour. ──
const initials = (name) => {
    if (!name) return '?';
    return name.trim().split(/\s+/).slice(0, 2).map(s => s.charAt(0).toUpperCase()).join('');
};
const AVATAR_PALETTE = [
    { bg: '#1a237e', fg: '#fff' },
    { bg: '#3949ab', fg: '#fff' },
    { bg: '#12d9e3', fg: '#06303a' },
    { bg: '#d912e3', fg: '#fff' },
    { bg: '#16a34a', fg: '#fff' },
    { bg: '#d97706', fg: '#fff' },
    { bg: '#dc2626', fg: '#fff' },
    { bg: '#7986cb', fg: '#fff' },
];
const avatarTone = (name) => {
    if (!name) return AVATAR_PALETTE[0];
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
    return AVATAR_PALETTE[Math.abs(h) % AVATAR_PALETTE.length];
};

// ── Priority left-rail colour (3px bar on every card) ──
const priorityRail = {
    critical: '#dc2626',  // red
    high:     '#ea580c',  // orange
    medium:   '#d97706',  // amber
    low:      '#12d9e3',  // cyan (signals "calm, in queue")
};

// ── Age pip — colour-codes freshness so triagers can scan ──
// Tickets that linger silently are the ones that bite. The pip turns hot
// the longer it sits unresolved.
const ageMeta = (createdAt) => {
    if (!createdAt) return null;
    const days = (Date.now() - new Date(createdAt).getTime()) / 86400000;
    if (days < 1)  return { color: '#16a34a', label: 'fresh',  hours: Math.max(1, Math.round(days * 24)) + 'h' };
    if (days < 3)  return { color: '#12d9e3', label: 'active', hours: Math.round(days) + 'd' };
    if (days < 7)  return { color: '#d97706', label: 'aging',  hours: Math.round(days) + 'd' };
    if (days < 14) return { color: '#ea580c', label: 'stale',  hours: Math.round(days) + 'd' };
    return            { color: '#dc2626', label: 'cold',   hours: Math.round(days) + 'd' };
};

// ── Mission-control SLA stats ──
// Derived purely client-side from the visible page so the hero feels live
// without an extra round-trip.
const ops = computed(() => {
    const data = props.tickets?.data ?? [];
    const open = data.filter(t => t.status !== 'closed' && t.status !== 'resolved');
    const resolved = data.filter(t => t.status === 'resolved' || t.status === 'closed');
    const overdue = data.filter(t => t.is_overdue);
    const onTime  = resolved.filter(t => !t.is_overdue).length;
    const slaPct  = resolved.length > 0 ? Math.round((onTime / resolved.length) * 100) : 100;

    // Avg age of open tickets in days
    const avgAgeDays = open.length > 0
        ? Math.round(open.reduce((s, t) => s + (Date.now() - new Date(t.created_at).getTime()) / 86400000, 0) / open.length)
        : 0;

    // Active resolvers — distinct assignees among open tickets
    const resolvers = new Set();
    open.forEach(t => { if (t.assigned_to?.name) resolvers.add(t.assigned_to.name); });

    return {
        slaPct,
        avgAgeDays,
        activeResolvers: resolvers.size,
        critical: data.filter(t => t.priority === 'critical' && t.status !== 'closed' && t.status !== 'resolved').length,
        resolvedThisRun: resolved.length,
    };
});
</script>

<template>
    <Head title="Tickets" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Service Desk</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Track, triage and resolve internal support requests.
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ stats.total }} total
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <!-- View toggle: List | Board -->
                    <div class="inline-flex items-center rounded-xl border border-outline-variant/60 bg-surface-container-lowest p-0.5 shadow-sm">
                        <button
                            type="button"
                            @click="view = 'list'"
                            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-bold transition-all"
                            :class="view === 'list'
                                ? 'bg-secondary/10 text-secondary'
                                : 'text-on-surface-variant/70 hover:text-on-surface'"
                            aria-label="List view"
                            title="List view"
                        >
                            <span class="material-symbols-outlined text-[16px]">view_list</span>
                            List
                        </button>
                        <button
                            type="button"
                            @click="view = 'board'"
                            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-bold transition-all"
                            :class="view === 'board'
                                ? 'bg-secondary/10 text-secondary'
                                : 'text-on-surface-variant/70 hover:text-on-surface'"
                            aria-label="Board view"
                            title="Board view"
                        >
                            <span class="material-symbols-outlined text-[16px]">view_kanban</span>
                            Board
                        </button>
                    </div>

                    <button
                        @click="showAddPanel = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">add_circle</span>
                        New Ticket
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- ── Operations hero ─────────────────────────────────
                 The Service Desk is operational territory — the visual
                 should feel like a live console, not four passive cards.
                 We pack the four primary KPIs into a single hero band with
                 a pulsing live ribbon and on-duty resolver count. -->
            <div class="relative overflow-hidden rounded-3xl px-7 py-6 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%,#283593 55%,#3949ab 100%);border:1px solid rgba(255,255,255,0.07);">
                <!-- Atmospheric radials -->
                <div class="pointer-events-none absolute -right-12 -top-12 h-64 w-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.22),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-6 bottom-0 h-44 w-44 rounded-full blur-2xl" style="background:rgba(255,215,0,0.10)"></div>

                <!-- Live ribbon — a thin gradient bar at the top of the card,
                     loops to suggest "data flowing in". -->
                <div class="absolute inset-x-0 top-0 h-px overflow-hidden">
                    <div class="tk-ribbon h-px w-1/3"></div>
                </div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 live-dot"></span>
                            <p class="text-[9px] font-black uppercase tracking-[0.25em]" style="color:rgba(18,217,227,0.85)">Service desk · live operations</p>
                        </div>
                        <h2 class="text-3xl font-black leading-tight">
                            <em class="not-italic" style="color:#12d9e3">{{ stats.open + stats.inProgress }}</em> active ticket<span v-if="(stats.open + stats.inProgress) !== 1">s</span>
                            <template v-if="ops.critical > 0"> · <em class="not-italic" style="color:#fda4af">{{ ops.critical }}</em> critical</template>
                        </h2>
                        <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.55)">
                            <span style="color:#ffd700">{{ ops.slaPct }}%</span> resolved on time ·
                            <span style="color:#7986cb">{{ ops.avgAgeDays }}d</span> avg open age ·
                            <span style="color:#a7f3d0">{{ ops.activeResolvers }}</span> on duty
                        </p>
                    </div>

                    <!-- Inline KPI strip — four counters with palette personalities -->
                    <div class="flex items-center gap-7 flex-shrink-0">
                        <div v-for="(kpi, i) in [
                            { label: 'Total',       val: stats.total,      color: '#ffffff' },
                            { label: 'Open',        val: stats.open,       color: '#12d9e3' },
                            { label: 'In progress', val: stats.inProgress, color: '#7986cb' },
                            { label: 'Overdue',     val: stats.overdue,    color: '#fda4af', accent: stats.overdue > 0 },
                        ]" :key="kpi.label" class="text-center"
                             :style="`animation:slideUpFade 0.45s ease both;animation-delay:${i*0.06}s`">
                            <p class="text-[34px] font-black leading-none tabular-nums"
                               :class="kpi.accent ? 'tk-kpi-warn' : ''"
                               :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                            <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.4)">{{ kpi.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5% gold hairline — single institutional moment on the page -->
            <div class="h-px w-full" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.45),transparent)"></div>

            <!-- Filters strip -->
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <div class="flex items-center gap-2 pl-2 pr-1 text-on-surface-variant/60">
                    <span class="material-symbols-outlined text-[18px]" style="color:#1a237e">filter_list</span>
                    <span class="text-[10px] font-black uppercase tracking-[0.18em]">Filter</span>
                </div>

                <div class="flex-1 min-w-[220px] max-w-sm">
                    <SearchInput v-model="localFilters.search" placeholder="Search title or description…" />
                </div>

                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">workspaces</span>
                    <select
                        v-model="localFilters.status"
                        @change="applyFilters"
                        class="appearance-none rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-9 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Statuses</option>
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                    <span class="material-symbols-outlined pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/60">expand_more</span>
                </div>

                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">flag</span>
                    <select
                        v-model="localFilters.priority"
                        @change="applyFilters"
                        class="appearance-none rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-9 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Priorities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                    <span class="material-symbols-outlined pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/60">expand_more</span>
                </div>

                <div v-if="canManage" class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">person</span>
                    <select
                        v-model="localFilters.assigned_to"
                        @change="applyFilters"
                        class="appearance-none rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-9 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Assignees</option>
                        <option v-for="user in staff" :key="user.id" :value="user.id">{{ user.name }}</option>
                    </select>
                    <span class="material-symbols-outlined pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/60">expand_more</span>
                </div>

                <label class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low px-3.5 py-2.5 text-[12.5px] cursor-pointer hover:border-red-300/60 transition-colors">
                    <input
                        type="checkbox"
                        :checked="!!localFilters.overdue"
                        @change="ev => { localFilters.overdue = ev.target.checked ? '1' : ''; applyFilters(); }"
                        class="h-3.5 w-3.5 accent-red-500"
                    />
                    <span class="material-symbols-outlined text-[15px] text-red-500/80" style="font-variation-settings:'FILL' 1">schedule</span>
                    <span class="font-semibold text-on-surface-variant">Overdue only</span>
                </label>

                <button
                    v-if="localFilters.search || localFilters.status || localFilters.priority || localFilters.assigned_to || localFilters.overdue"
                    @click="clearFilters"
                    class="ml-auto flex items-center gap-1.5 rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container hover:border-red-300/60 hover:text-red-600 transition-all"
                >
                    <span class="material-symbols-outlined text-[16px]">backspace</span>
                    Clear
                </button>
            </div>

            <!-- â”€â”€ Board view (Kanban) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="view === 'board'" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                <div v-if="tickets?.data?.length === 0" class="p-8">
                    <EmptyState
                        title="No tickets to show"
                        description="Open a new ticket or adjust your filters to start populating the board."
                        icon="confirmation_number"
                    >
                        <template #action>
                            <button
                                @click="showAddPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                New Ticket
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <KanbanBoard
                    v-else
                    :columns="kanbanColumns"
                    :interactive="canManage"
                    @move="onTicketMove"
                >
                    <template #card="{ item }">
                        <Link :href="route('tickets.show', item.id)" class="block relative">
                            <!-- Priority left-rail — fast scan signal for triage.
                                 Critical/high get a subtle pulse to draw the eye. -->
                            <span
                                class="absolute -left-3 top-0 bottom-0 w-[3px] rounded-full"
                                :class="(item.priority === 'critical' || item.priority === 'high') ? 'tk-rail-pulse' : ''"
                                :style="`background:${priorityRail[item.priority] ?? '#94a3b8'}`"
                                aria-hidden="true"
                            ></span>

                            <!-- Priority chip + ID + age pip -->
                            <div class="flex items-start gap-2 mb-2">
                                <span
                                    :class="['inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md text-[9.5px] font-bold uppercase tracking-wider whitespace-nowrap', priorityClasses[item.priority]]"
                                    :title="`Priority: ${item.priority_label}`"
                                >
                                    <span class="material-symbols-outlined text-[12px]">{{ priorityIcon[item.priority] }}</span>
                                    {{ item.priority_label }}
                                </span>
                                <span class="text-[10px] font-mono text-on-surface-variant/50">#{{ item.id }}</span>

                                <!-- Age pip — small dot + numeric age. Hot colours = aging fast. -->
                                <span
                                    v-if="ageMeta(item.created_at)"
                                    class="ml-auto inline-flex items-center gap-1 text-[9.5px] font-bold tabular-nums"
                                    :style="`color:${ageMeta(item.created_at).color}`"
                                    :title="`Created ${daysSince(item.created_at)} — ${ageMeta(item.created_at).label}`"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :style="`background:${ageMeta(item.created_at).color};box-shadow:0 0 0 2px ${ageMeta(item.created_at).color}22`"
                                    ></span>
                                    {{ ageMeta(item.created_at).hours }}
                                </span>
                            </div>

                            <p class="text-[13px] font-bold text-on-surface leading-snug line-clamp-2">{{ item.title }}</p>

                            <p v-if="item.description" class="mt-1 text-[11px] text-on-surface-variant/70 line-clamp-2">{{ item.description }}</p>

                            <!-- Meta row -->
                            <div class="mt-3 flex items-center justify-between gap-2 text-[10.5px]">
                                <div class="min-w-0 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[14px] text-on-surface-variant/40">person</span>
                                    <span class="truncate text-on-surface-variant">{{ item.employee?.name ?? 'Unknown' }}</span>
                                </div>
                                <div
                                    v-if="item.assigned_to"
                                    class="flex items-center gap-1 rounded-full px-1.5 py-0.5"
                                    style="background:rgba(26, 35, 126,0.10);color:#1a237e"
                                >
                                    <span class="material-symbols-outlined text-[12px]">badge</span>
                                    <span class="font-bold truncate max-w-[80px]">{{ item.assigned_to.name }}</span>
                                </div>
                            </div>

                            <!-- Due date -->
                            <div v-if="item.due_at" class="mt-2 flex items-center gap-1 text-[10px]"
                                 :class="item.is_overdue ? 'text-red-600 font-bold tk-overdue' : 'text-on-surface-variant/60'">
                                <span class="material-symbols-outlined text-[12px]">schedule</span>
                                <span>Due {{ formatDate(item.due_at) }}</span>
                                <span v-if="item.is_overdue" class="ml-auto text-[9px] font-black uppercase tracking-wider">Overdue</span>
                            </div>
                        </Link>
                    </template>
                </KanbanBoard>

                <p v-if="!canManage" class="mt-4 flex items-center gap-1.5 text-[11px] text-on-surface-variant/60 italic">
                    <span class="material-symbols-outlined text-[14px]">lock</span>
                    Read-only view â€” only ticket managers can move cards or change status.
                </p>
            </div>

            <!-- â”€â”€ List view (table) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-else class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">

                <div v-if="tickets?.data?.length === 0" class="p-12">
                    <EmptyState
                        title="No tickets found"
                        description="Adjust your filters or open a new ticket to get started."
                        icon="confirmation_number"
                    >
                        <template #action>
                            <button
                                @click="showAddPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                New Ticket
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="max-h-[calc(100vh-440px)] min-h-[280px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Ticket</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Requester</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Priority</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Assigned</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Due</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr
                                v-for="ticket in tickets.data"
                                :key="ticket.id"
                                class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                                @click="router.get(route('tickets.show', ticket.id))"
                            >
                                <td class="px-4 py-3.5">
                                    <p class="text-[13px] font-bold text-on-surface leading-tight truncate max-w-[260px]">{{ ticket.title }}</p>
                                    <p class="mt-0.5 text-[11px] text-on-surface-variant/60 leading-tight">
                                        <span class="font-mono">#{{ ticket.id }}</span> · opened {{ daysSince(ticket.created_at) }}
                                    </p>
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    <p class="leading-tight font-semibold">{{ ticket.employee?.name ?? '—' }}</p>
                                    <p class="mt-0.5 text-[11px] text-on-surface-variant/60 leading-tight font-mono">{{ ticket.employee?.employee_no ?? '' }}</p>
                                </td>

                                <td class="px-4 py-3.5">
                                    <!-- Inline priority change — click the pill to open a menu of options -->
                                    <div class="relative ticket-priority-menu" @click.stop>
                                        <button type="button"
                                                @click="priorityMenuFor = priorityMenuFor === ticket.id ? null : ticket.id"
                                                :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-[0.08em] cursor-pointer hover:ring-2 hover:ring-secondary/30 transition-all', priorityClasses[ticket.priority]]"
                                                aria-label="Change priority">
                                            <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">{{ priorityIcon[ticket.priority] }}</span>
                                            {{ ticket.priority_label }}
                                            <span class="material-symbols-outlined text-[12px] opacity-70">expand_more</span>
                                        </button>
                                        <div v-if="priorityMenuFor === ticket.id"
                                             class="absolute left-0 top-7 z-30 w-36 rounded-xl border border-outline-variant/60 bg-surface-container-lowest shadow-lifted py-1.5">
                                            <button v-for="p in PRIORITY_OPTIONS" :key="p"
                                                    type="button"
                                                    @click="quickPriority(ticket, p); priorityMenuFor = null"
                                                    :disabled="ticket.priority === p"
                                                    class="w-full flex items-center gap-2 px-3 py-1.5 text-left text-[11.5px] font-semibold uppercase tracking-wider transition-colors"
                                                    :class="ticket.priority === p ? 'text-secondary cursor-default bg-secondary/[0.05]' : 'text-on-surface hover:bg-surface-container'">
                                                <span class="material-symbols-outlined text-[14px]">{{ priorityIcon[p] }}</span>
                                                {{ p }}
                                                <span v-if="ticket.priority === p" class="material-symbols-outlined text-[14px] ml-auto">check</span>
                                            </button>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5">
                                    <StatusBadge :status="ticket.status" type="ticket" />
                                </td>

                                <td class="px-4 py-3.5" @click.stop>
                                    <select
                                        v-if="canManage"
                                        :value="ticket.assigned_to?.id ?? ''"
                                        @change="ev => quickAssign(ticket, ev.target.value)"
                                        class="rounded-lg border border-outline-variant/60 bg-surface-container-low px-2 py-1 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50 max-w-[140px] hover:border-secondary/40 transition-colors"
                                    >
                                        <option value="">Unassigned</option>
                                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                    </select>
                                    <span v-else class="text-[12px] text-on-surface-variant">{{ ticket.assigned_to?.name ?? 'Unassigned' }}</span>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span
                                        :class="['text-[12px] flex items-center gap-1 tabular-nums', ticket.is_overdue ? 'text-red-600 font-bold' : 'text-on-surface-variant']"
                                    >
                                        <span v-if="ticket.is_overdue" class="material-symbols-outlined text-[14px] text-red-500" style="font-variation-settings:'FILL' 1">schedule</span>
                                        {{ formatDate(ticket.due_at) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3.5" @click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        <Link
                                            :href="route('tickets.show', ticket.id)"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-secondary/10 hover:text-secondary hover:border-secondary/15 transition-all"
                                            title="View ticket"
                                            aria-label="View ticket"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">visibility</span>
                                        </Link>
                                        <button
                                            v-if="canManage && ticket.status !== 'resolved' && ticket.status !== 'closed'"
                                            @click="quickStatus(ticket, 'resolved')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-green-500/10 hover:text-green-600 hover:border-green-500/15 transition-all"
                                            title="Mark resolved"
                                            aria-label="Mark resolved"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">check_circle</span>
                                        </button>
                                        <button
                                            v-if="canManage"
                                            @click="confirmDelete(ticket.id, $event)"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-red-500/10 hover:text-red-600 hover:border-red-500/15 transition-all"
                                            title="Delete ticket"
                                            aria-label="Delete ticket"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">delete</span>
                                        </button>
                                        <span class="material-symbols-outlined ml-0.5 text-[18px] text-on-surface-variant/30 opacity-0 -translate-x-1 transition-all duration-200 group-hover:opacity-100 group-hover:translate-x-0 group-hover:text-secondary/70" aria-hidden="true">chevron_right</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="tickets?.links?.length > 3" class="border-t border-outline-variant/50 bg-surface-container-low/40 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="flex items-center gap-1.5 text-[12px] text-on-surface-variant">
                            <span class="material-symbols-outlined text-[15px]" style="color:#1a237e;opacity:0.7">format_list_numbered</span>
                            Showing
                            <span class="font-bold text-on-surface tabular-nums">{{ tickets.meta?.from }}</span>
                            –
                            <span class="font-bold text-on-surface tabular-nums">{{ tickets.meta?.to }}</span>
                            of
                            <span class="font-bold text-on-surface tabular-nums">{{ tickets.meta?.total }}</span>
                        </p>
                        <Pagination :links="tickets.links" />
                    </div>
                </div>
            </div>
        </div>

        <!-- New Ticket -->
        <SlidePanel :open="showAddPanel" title="Open New Ticket" size="lg" @close="showAddPanel = false">
            <form @submit.prevent="submit" class="space-y-5 p-6">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Title <span class="text-red-500">*</span></label>
                    <input
                        v-model="form.title"
                        type="text"
                        placeholder="Brief description of the issue"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.title }"
                    />
                    <p v-if="form.errors.title" class="mt-1 text-[11px] text-red-500">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description <span class="text-red-500">*</span></label>
                    <textarea
                        v-model="form.description"
                        rows="5"
                        placeholder="Provide detailed information about the issue, steps to reproduce, and expected outcomeâ€¦"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                        :class="{ 'border-red-400': form.errors.description }"
                    ></textarea>
                    <p v-if="form.errors.description" class="mt-1 text-[11px] text-red-500">{{ form.errors.description }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Priority <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.priority"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Due Date</label>
                        <input
                            v-model="form.due_at"
                            type="datetime-local"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showAddPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submit"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Submit Ticket</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

        <ConfirmDialog
            :open="showDeleteDialog"
            title="Delete Ticket"
            message="Are you sure you want to delete this ticket? This action cannot be undone."
            :danger="true"
            @confirm="doDelete"
            @cancel="showDeleteDialog = false"
        />

    </AuthenticatedLayout>
</template>

<style scoped>
/* Live "data flowing" ribbon — a small gradient bar that streaks across the
   top edge of the operations hero. Loops forever; visual breathing room. */
.tk-ribbon {
    background: linear-gradient(90deg, transparent, rgba(18,217,227,0.9), rgba(255,215,0,0.7), transparent);
    animation: tkRibbon 3.8s linear infinite;
}
@keyframes tkRibbon {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}

/* Live-dot — a single tiny pulse on the hero "Live operations" tag. */
.live-dot { animation: tkLiveDot 1.6s ease-in-out infinite; }
@keyframes tkLiveDot {
    0%, 100% { opacity: 1;   transform: scale(1); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); }
    50%      { opacity: 0.4; transform: scale(0.7); box-shadow: 0 0 0 6px rgba(74, 222, 128, 0); }
}

/* Overdue KPI count — soft heartbeat to draw the eye when > 0. */
.tk-kpi-warn { animation: tkWarnPulse 2s ease-in-out infinite; }
@keyframes tkWarnPulse {
    0%, 100% { text-shadow: 0 0 0 rgba(248, 113, 113, 0); }
    50%      { text-shadow: 0 0 14px rgba(248, 113, 113, 0.55); }
}

/* Priority-rail pulse on critical / high cards — barely perceptible, just
   enough that the rail gently breathes. */
.tk-rail-pulse {
    animation: tkRailPulse 2.4s ease-in-out infinite;
}
@keyframes tkRailPulse {
    0%, 100% { opacity: 1;   filter: brightness(1); }
    50%      { opacity: 0.6; filter: brightness(1.4); }
}

/* Overdue due-date — a faint flicker so it never goes silent. */
.tk-overdue {
    animation: tkOverdueFlash 2.6s ease-in-out infinite;
}
@keyframes tkOverdueFlash {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.55; }
}

@media (prefers-reduced-motion: reduce) {
    .tk-ribbon, .live-dot, .tk-kpi-warn, .tk-rail-pulse, .tk-overdue {
        animation: none !important;
    }
}
</style>
