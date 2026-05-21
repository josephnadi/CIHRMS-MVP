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


defineOptions({ layout: AuthenticatedLayout });
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

// ── Filters ──────────────────────────────────────────────────────────────────
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

// ── Stats ────────────────────────────────────────────────────────────────────
const stats = computed(() => {
    const data = props.tickets?.data ?? [];
    return {
        total:      props.tickets?.meta?.total ?? data.length,
        open:       data.filter(t => t.status === 'open').length,
        inProgress: data.filter(t => t.status === 'in_progress').length,
        overdue:    data.filter(t => t.is_overdue).length,
    };
});

// ── Panels / dialogs ─────────────────────────────────────────────────────────
const showAddPanel     = ref(false);
const showDeleteDialog = ref(false);
const selectedId       = ref(null);

// Auto-open create panel when arriving via Quick Action (?new=1).
// Strip the flag immediately so refresh + post-submit back() don't re-trigger
// the panel and leave the backdrop stuck over the page.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showAddPanel.value = true;
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash,
        );
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

// ── Inline status / assignment ───────────────────────────────────────────────
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
const onOutsideAssigneeFilterClick = (e) => {
    if (! e.target.closest('.tk-assignee-filter')) assigneeMenuOpen.value = false;
};
onMounted(() => {
    document.addEventListener('click', onOutsideTicketPriorityClick);
    document.addEventListener('click', onOutsideAssigneeFilterClick);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onOutsideTicketPriorityClick);
    document.removeEventListener('click', onOutsideAssigneeFilterClick);
});

// ── View toggle (List | Board) ───────────────────────────────────────────────
const VIEW_STORAGE_KEY = 'cihrms.tickets.view';
const view = ref('board'); // default per request — Kanban first

onMounted(() => {
    try {
        const stored = localStorage.getItem(VIEW_STORAGE_KEY);
        if (stored === 'list' || stored === 'board') view.value = stored;
    } catch (e) { /* localStorage unavailable */ }
});

watch(view, (v) => {
    try { localStorage.setItem(VIEW_STORAGE_KEY, v); } catch (e) { /* noop */ }
});

// ── Jira-style state ──
// Quick-filter chip — narrows what shows on the board without touching the
// server filters. None / mine / unassigned / overdue / critical.
const quickFilter = ref('none');

// Swimlane — group rows by Priority or Assignee on the board.
const swimlane = ref('none'); // 'none' | 'priority' | 'assignee'

// Density — compact crams more cards into view (Jira's "Compact" mode).
const density = ref('comfortable'); // 'comfortable' | 'compact'

// Persist board prefs
const BOARD_PREFS_KEY = 'cihrms.tickets.boardPrefs';
onMounted(() => {
    try {
        const raw = localStorage.getItem(BOARD_PREFS_KEY);
        if (raw) {
            const p = JSON.parse(raw);
            if (p.quickFilter) quickFilter.value = p.quickFilter;
            if (p.swimlane)    swimlane.value   = p.swimlane;
            if (p.density)     density.value    = p.density;
        }
    } catch (e) { /* noop */ }
});
watch([quickFilter, swimlane, density], () => {
    try {
        localStorage.setItem(BOARD_PREFS_KEY, JSON.stringify({
            quickFilter: quickFilter.value, swimlane: swimlane.value, density: density.value,
        }));
    } catch (e) { /* noop */ }
});

// Keyboard "/" focuses the board search (Jira-like hotkey).
const boardSearchEl = ref(null);
const onGlobalKey = (e) => {
    if (e.key === '/' && document.activeElement?.tagName !== 'INPUT' && document.activeElement?.tagName !== 'TEXTAREA') {
        e.preventDefault();
        boardSearchEl.value?.focus?.();
    }
    if (e.key === 'Escape') { drawerTicket.value = null; }
};
onMounted(() => document.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onGlobalKey));

// Detail drawer — click a card → opens right-side drawer with full
// context + inline transition buttons.
const drawerTicket = ref(null);
const openDrawer = (ticket) => { drawerTicket.value = ticket; };
const closeDrawer = () => { drawerTicket.value = null; };

// Current user (used by the "Mine" quick filter)
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);
const currentUser   = computed(() => page.props.auth?.user ?? null);

// ── Assignee filter (profile chip + search) ────────────────────────────────
// `assigneeFilter` holds the user id whose tickets the board should show.
// Defaults to the current user's id so the page opens already focused on
// "mine". `null` disables the filter. The small search input below the chip
// looks up users from `props.staff` (server-provided directory) and writes
// the chosen id into `assigneeFilter`.
const assigneeFilter = ref(null);            // number | null — set to id to narrow
const assigneeSearch = ref('');               // text in the search input
const assigneeMenuOpen = ref(false);

const selectedAssignee = computed(() => {
    if (!assigneeFilter.value) return null;
    if (assigneeFilter.value === currentUserId.value) return currentUser.value;
    return (props.staff ?? []).find(u => u.id === assigneeFilter.value) ?? null;
});

const assigneeMatches = computed(() => {
    const q = assigneeSearch.value.trim().toLowerCase();
    if (!q) return (props.staff ?? []).slice(0, 8);
    return (props.staff ?? [])
        .filter(u => (u.name ?? '').toLowerCase().includes(q) || (u.email ?? '').toLowerCase().includes(q))
        .slice(0, 8);
});

const setAssigneeFilter = (userId) => {
    assigneeFilter.value = userId;
    assigneeSearch.value = '';
    assigneeMenuOpen.value = false;
};

const clearAssigneeFilter = () => {
    assigneeFilter.value = null;
    assigneeSearch.value = '';
};

// "Filter to me" shortcut — toggles between current user and clear.
const toggleMineFilter = () => {
    if (assigneeFilter.value === currentUserId.value) {
        clearAssigneeFilter();
    } else {
        setAssigneeFilter(currentUserId.value);
    }
};

// Apply quick-filter + assignee-filter on top of server-paged data.
const filteredTickets = computed(() => {
    let data = props.tickets?.data ?? [];

    if (assigneeFilter.value !== null) {
        data = data.filter(t => t.assigned_to?.id === assigneeFilter.value);
    }

    switch (quickFilter.value) {
        case 'mine':
            return data.filter(t => t.assigned_to?.id === currentUserId.value);
        case 'unassigned':
            return data.filter(t => !t.assigned_to);
        case 'overdue':
            return data.filter(t => t.is_overdue);
        case 'critical':
            return data.filter(t => t.priority === 'critical' || t.priority === 'high');
        default:
            return data;
    }
});

// ── Swimlane groups (Jira's signature feature) ──
const PRIORITY_ORDER = ['critical', 'high', 'medium', 'low'];

const swimlaneGroups = computed(() => {
    if (swimlane.value === 'none') {
        return [{ id: 'all', label: null, items: filteredTickets.value }];
    }
    if (swimlane.value === 'priority') {
        return PRIORITY_ORDER.map(p => ({
            id: p,
            label: p.charAt(0).toUpperCase() + p.slice(1),
            color: priorityRail[p],
            items: filteredTickets.value.filter(t => t.priority === p),
        })).filter(g => g.items.length > 0);
    }
    if (swimlane.value === 'assignee') {
        const buckets = new Map();
        filteredTickets.value.forEach(t => {
            const key = t.assigned_to?.id ?? 'unassigned';
            if (!buckets.has(key)) {
                buckets.set(key, {
                    id: key,
                    label: t.assigned_to?.name ?? 'Unassigned',
                    items: [],
                });
            }
            buckets.get(key).items.push(t);
        });
        return [...buckets.values()].sort((a, b) =>
            (a.id === 'unassigned' ? 1 : 0) - (b.id === 'unassigned' ? 1 : 0)
        );
    }
    return [{ id: 'all', label: null, items: filteredTickets.value }];
});

// Kanban columns are derived per swimlane group at render time.
// Each card gets a `draggable` flag the KanbanBoard reads to allow / lock
// drag on a per-item basis. A user can only move a card when they are
// either the assignee or hold `tickets.manage`. The backend policy is the
// source of truth — this flag just makes the UI honest.
function columnsFor(group) {
    const meId = currentUserId.value;
    return STATUS_COLUMNS.map(col => ({
        ...col,
        items: group.items
            .filter(t => t.status === col.id)
            .map(t => ({ ...t, draggable: canManage.value || (meId !== null && t.assigned_to?.id === meId) })),
    }));
}

// ── Kanban columns derived from tickets.data ──
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

// Optimistic move handler — fires PATCH and lets Inertia reload props
const onTicketMove = ({ itemId, toColumnId }) => {
    const ticket = (props.tickets?.data ?? []).find(t => t.id === itemId);
    if (!ticket) return;
    router.patch(route('tickets.update', itemId), {
        status:      toColumnId,
        assigned_to: ticket.assigned_to?.id ?? null,
    }, { preserveScroll: true, preserveState: true });
};

// Click on a column's "+" button → open the new-ticket slide panel.
// The status the ticket is filed under is set server-side on store (always
// Open by default), so we don't pass the column id here.
const onColumnAdd = (_columnId) => {
    showAddPanel.value = true;
};

// ── Helpers ──────────────────────────────────────────────────────────────────
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

// ── Editorial-Sovereign masthead ──────────────────────────────────
// Treats the Service Desk like the front page of an institutional broadsheet.
// Volume = year offset from CIHRM-GH platform inception (2023). Issue = day-of-year.
const nowTick = ref(Date.now());
let nowTimer = null;
onMounted(() => { nowTimer = setInterval(() => { nowTick.value = Date.now(); }, 1000); });
onBeforeUnmount(() => { if (nowTimer) clearInterval(nowTimer); });

const editionLabel = computed(() => {
    const d   = new Date(nowTick.value);
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
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">support_agent</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SERVICE DESK</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Service Desk</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Requests in flight — triaged, assigned, and resolved against service-level commitments.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="view = 'board'"
                                :class="['flex items-center gap-2 rounded-xl px-3 py-2.5 text-[13px] font-black transition-all',
                                         view === 'board' ? 'bg-secondary/10 text-secondary ring-1 ring-secondary/30'
                                                          : 'border border-outline-variant/50 bg-surface-container-lowest text-primary shadow-card hover:-translate-y-px']">
                            <span class="material-symbols-outlined text-[17px]">view_kanban</span>
                            Board
                        </button>
                        <button @click="view = 'list'"
                                :class="['flex items-center gap-2 rounded-xl px-3 py-2.5 text-[13px] font-black transition-all',
                                         view === 'list' ? 'bg-secondary/10 text-secondary ring-1 ring-secondary/30'
                                                         : 'border border-outline-variant/50 bg-surface-container-lowest text-primary shadow-card hover:-translate-y-px']">
                            <span class="material-symbols-outlined text-[17px]">view_list</span>
                            List
                        </button>
                        <button @click="showAddPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">add_circle</span>
                            New Ticket
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- ── Jira-style board toolbar ──
                     Quick-filter chips on the left, board controls on the right.
                     Mirrors Atlassian's compact toolbar above a Jira board. -->
                <div v-if="view === 'board'" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-3 py-2.5 shadow-card flex flex-wrap items-center gap-3">

                    <!-- ── Assignee filter (profile chip + search) ──
                         A round chip showing the currently-filtered user; click it
                         to expand a small popover with a search input that hunts
                         the staff directory. Selecting a row narrows the board to
                         that person's tickets. Clicking the chip when it shows
                         "me" clears the filter. -->
                    <div class="relative tk-assignee-filter">
                        <!-- The chip itself -->
                        <button
                            type="button"
                            @click="assigneeMenuOpen = !assigneeMenuOpen"
                            class="tk-assignee-chip flex items-center gap-2 rounded-full border pl-1 pr-3 py-1 transition-colors"
                            :class="assigneeFilter !== null
                                ? 'border-secondary/40 bg-secondary/10 text-secondary'
                                : 'border-outline-variant/60 bg-surface-container-low text-on-surface-variant hover:bg-surface-container'"
                            :title="selectedAssignee ? `Showing tickets for ${selectedAssignee.name}` : 'Filter by assignee'"
                        >
                            <span class="flex h-6 w-6 items-center justify-center rounded-full overflow-hidden ring-1 ring-white shadow-sm"
                                  :style="!selectedAssignee?.avatar ? 'background:linear-gradient(135deg,#0d1452,#1a237e)' : ''">
                                <img v-if="selectedAssignee?.avatar" :src="selectedAssignee.avatar" :alt="selectedAssignee.name" class="h-full w-full object-cover" />
                                <span v-else class="text-[10px] font-black text-white">
                                    {{ (selectedAssignee?.name ?? currentUser?.name ?? '?').slice(0, 1).toUpperCase() }}
                                </span>
                            </span>
                            <span class="text-[12px] font-bold tracking-tight">
                                <template v-if="!selectedAssignee">Filter by person</template>
                                <template v-else-if="selectedAssignee.id === currentUserId">Showing: me</template>
                                <template v-else>Showing: {{ selectedAssignee.name }}</template>
                            </span>
                            <span v-if="assigneeFilter !== null" @click.stop="clearAssigneeFilter"
                                  class="material-symbols-outlined text-[14px] -mr-1 opacity-70 hover:opacity-100"
                                  title="Clear assignee filter">close</span>
                            <span v-else class="material-symbols-outlined text-[14px] -mr-1 opacity-60">expand_more</span>
                        </button>

                        <!-- Popover: search + suggestions -->
                        <Transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="opacity-0 -translate-y-1 scale-95"
                            enter-to-class="opacity-100 translate-y-0 scale-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0 scale-95"
                        >
                            <div v-if="assigneeMenuOpen" @click.stop
                                 class="absolute left-0 top-10 z-30 w-72 rounded-xl border border-outline-variant/60 bg-surface-container-lowest shadow-lifted overflow-hidden">
                                <!-- "Show me" shortcut -->
                                <button type="button" @click="toggleMineFilter"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-[12px] font-bold border-b border-outline-variant/40 hover:bg-secondary/5"
                                        :class="assigneeFilter === currentUserId ? 'text-secondary bg-secondary/[0.04]' : 'text-on-surface'">
                                    <span class="material-symbols-outlined text-[16px]">person</span>
                                    Show only mine
                                    <span v-if="assigneeFilter === currentUserId" class="ml-auto material-symbols-outlined text-[16px]">check</span>
                                </button>

                                <!-- Search input -->
                                <div class="relative px-2.5 pt-2 pb-1.5">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[15px] text-on-surface-variant/50">search</span>
                                    <input v-model="assigneeSearch"
                                           placeholder="Search team by name…"
                                           autofocus
                                           class="w-full rounded-lg border border-outline-variant/60 bg-surface-container-low pl-7 pr-2 py-1.5 text-[12.5px] focus:border-secondary focus:ring-2 focus:ring-secondary/15" />
                                </div>

                                <!-- Suggestion list -->
                                <ul class="max-h-60 overflow-y-auto py-1">
                                    <li v-for="u in assigneeMatches" :key="u.id">
                                        <button type="button" @click="setAssigneeFilter(u.id)"
                                                class="w-full flex items-center gap-2 px-3 py-2 text-left text-[12.5px] hover:bg-secondary/[0.06] transition-colors"
                                                :class="assigneeFilter === u.id ? 'bg-secondary/[0.06] text-secondary font-bold' : 'text-on-surface'">
                                            <span class="flex h-6 w-6 items-center justify-center rounded-full overflow-hidden ring-1 ring-white shadow-sm"
                                                  :style="!u.avatar ? 'background:linear-gradient(135deg,#0d1452,#1a237e)' : ''">
                                                <img v-if="u.avatar" :src="u.avatar" :alt="u.name" class="h-full w-full object-cover" />
                                                <span v-else class="text-[10px] font-black text-white">{{ (u.name ?? '?').slice(0, 1).toUpperCase() }}</span>
                                            </span>
                                            <span class="min-w-0 flex-1 truncate">{{ u.name }}</span>
                                            <span v-if="assigneeFilter === u.id" class="material-symbols-outlined text-[16px]">check</span>
                                        </button>
                                    </li>
                                    <li v-if="assigneeMatches.length === 0" class="px-3 py-3 text-center text-[11.5px] text-on-surface-variant/60 italic">
                                        No team members match.
                                    </li>
                                </ul>
                            </div>
                        </Transition>
                    </div>

                    <!-- Quick filters -->
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button v-for="opt in [
                            { id: 'none',       label: 'All',         icon: 'view_kanban', count: filteredTickets.length },
                            { id: 'mine',       label: 'Mine',        icon: 'person',      count: (tickets?.data ?? []).filter(t => t.assigned_to?.id === currentUserId).length },
                            { id: 'unassigned', label: 'Unassigned',  icon: 'person_off',  count: (tickets?.data ?? []).filter(t => !t.assigned_to).length },
                            { id: 'overdue',    label: 'Overdue',     icon: 'schedule',    count: stats.overdue, tone: 'red' },
                            { id: 'critical',   label: 'Critical',    icon: 'priority_high', count: (tickets?.data ?? []).filter(t => t.priority === 'critical' || t.priority === 'high').length, tone: 'orange' },
                        ]" :key="opt.id" @click="quickFilter = opt.id"
                                :class="['tk-chip group inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[11.5px] font-bold transition-all',
                                          quickFilter === opt.id
                                            ? (opt.tone === 'red'    ? 'bg-red-50 text-red-700 ring-1 ring-red-300 dark:bg-red-900/30 dark:text-red-300'
                                            :  opt.tone === 'orange' ? 'bg-amber-50 text-amber-800 ring-1 ring-amber-300 dark:bg-amber-900/30 dark:text-amber-300'
                                            :  'bg-secondary/10 text-secondary ring-1 ring-secondary/30')
                                            : 'text-on-surface-variant hover:bg-surface-container-low']">
                            <span class="material-symbols-outlined text-[14px]">{{ opt.icon }}</span>
                            {{ opt.label }}
                            <span :class="['inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[9.5px] font-black tabular-nums',
                                            quickFilter === opt.id ? 'bg-white/70 text-secondary' : 'bg-surface-container-low text-on-surface-variant/70']">{{ opt.count }}</span>
                        </button>
                    </div>

                    <!-- Inline search with "/" hotkey hint -->
                    <div class="relative flex-1 min-w-[200px] max-w-[260px] ml-auto">
                        <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[15px] text-on-surface-variant/50">search</span>
                        <input
                            ref="boardSearchEl"
                            v-model="localFilters.search"
                            placeholder="Search board…"
                            class="w-full rounded-lg border-outline-variant/60 bg-surface-container-low pl-8 pr-10 py-1.5 text-[12.5px] focus:border-secondary focus:ring-2 focus:ring-secondary/15"
                        />
                        <kbd class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center justify-center h-[18px] min-w-[18px] px-1.5 rounded-md border border-outline-variant/60 bg-surface-container-low text-[9.5px] font-black text-on-surface-variant/60 font-mono pointer-events-none">/</kbd>
                    </div>

                    <!-- Group-by (swimlane) -->
                    <div class="flex items-center gap-1 rounded-lg border border-outline-variant/60 bg-surface-container-low px-1 py-0.5">
                        <span class="px-1.5 text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Group</span>
                        <button v-for="opt in [
                            { id: 'none',     label: 'None',     icon: 'view_module' },
                            { id: 'priority', label: 'Priority', icon: 'flag' },
                            { id: 'assignee', label: 'Assignee', icon: 'group' },
                        ]" :key="opt.id" @click="swimlane = opt.id"
                                :class="['inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-bold transition-all',
                                          swimlane === opt.id ? 'bg-secondary text-white shadow-glow-sm' : 'text-on-surface-variant hover:bg-surface-container']"
                                :title="`Group by ${opt.label}`">
                            <span class="material-symbols-outlined text-[13px]">{{ opt.icon }}</span>
                            <span class="hidden lg:inline">{{ opt.label }}</span>
                        </button>
                    </div>

                    <!-- Density toggle -->
                    <div class="flex items-center gap-1 rounded-lg border border-outline-variant/60 bg-surface-container-low px-0.5 py-0.5">
                        <button @click="density = 'comfortable'"
                                :class="['inline-flex items-center justify-center h-7 w-7 rounded-md transition-all',
                                          density === 'comfortable' ? 'bg-secondary text-white' : 'text-on-surface-variant hover:bg-surface-container']"
                                title="Comfortable density">
                            <span class="material-symbols-outlined text-[15px]">view_agenda</span>
                        </button>
                        <button @click="density = 'compact'"
                                :class="['inline-flex items-center justify-center h-7 w-7 rounded-md transition-all',
                                          density === 'compact' ? 'bg-secondary text-white' : 'text-on-surface-variant hover:bg-surface-container']"
                                title="Compact density">
                            <span class="material-symbols-outlined text-[15px]">density_small</span>
                        </button>
                    </div>
                </div>

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

                <!-- ── Board view (Kanban with swimlanes) ── -->
                <div v-if="view === 'board'" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                    <div v-if="filteredTickets.length === 0" class="p-8">
                        <EmptyState
                            title="No tickets in this view"
                            :description="quickFilter !== 'none' ? 'Try another quick filter or clear it to see everything.' : 'Open a new ticket or adjust your filters to start populating the board.'"
                            icon="confirmation_number"
                        >
                            <template #action>
                                <div class="flex items-center gap-2">
                                    <button v-if="quickFilter !== 'none'" @click="quickFilter = 'none'"
                                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                                        Clear quick filter
                                    </button>
                                    <button @click="showAddPanel = true"
                                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                        <span class="material-symbols-outlined text-[18px]">add</span>
                                        New Ticket
                                    </button>
                                </div>
                            </template>
                        </EmptyState>
                    </div>

                    <!-- Swimlane v-for (Jira's signature) -->
                    <div v-else :class="['tk-density', density === 'compact' ? 'tk-density--compact' : '']">
                        <div v-for="(group, gi) in swimlaneGroups" :key="group.id"
                             :class="['tk-swimlane', gi > 0 ? 'mt-6 pt-6 border-t border-outline-variant/40' : '']">

                            <!-- Swimlane header (hidden when group label is null/none) -->
                            <div v-if="group.label" class="flex items-center gap-2 mb-3 px-1">
                                <span v-if="group.color" class="h-2.5 w-2.5 rounded-full" :style="`background:${group.color}`"></span>
                                <span class="material-symbols-outlined text-[14px] text-on-surface-variant/60">
                                    {{ swimlane === 'priority' ? 'flag' : 'person' }}
                                </span>
                                <h3 class="text-[12px] font-black uppercase tracking-[0.14em] text-on-surface">{{ group.label }}</h3>
                                <span class="text-[10.5px] font-bold text-on-surface-variant/60 tabular-nums">{{ group.items.length }} ticket<span v-if="group.items.length !== 1">s</span></span>
                                <span class="ml-auto h-px flex-1 bg-gradient-to-r from-outline-variant/30 to-transparent"></span>
                            </div>

                            <KanbanBoard
                                :columns="columnsFor(group)"
                                :interactive="true"
                                @move="onTicketMove"
                                @add="onColumnAdd"
                            >
                                <template #card="{ item }">
                                    <div @click.stop="openDrawer(item)" class="tk-pcard block relative cursor-pointer">
                                        <!-- Title -->
                                        <h4 class="tk-pcard__title text-[14px] font-black text-on-surface leading-snug" :class="density === 'compact' ? 'line-clamp-1' : 'line-clamp-2'">
                                            {{ item.title }}
                                        </h4>

                                        <!-- Dual label row — STATUS pill + PRIORITY pill (Plaky/Asana style) -->
                                        <div class="tk-pcard__labels mt-2 flex flex-wrap items-center gap-1.5">
                                            <span :class="['tk-label', `tk-label--${item.status}`]">
                                                {{ item.status_label }}
                                            </span>
                                            <span :class="['tk-label', `tk-label--prio-${item.priority}`]">
                                                {{ item.priority_label }}
                                            </span>
                                        </div>

                                        <!-- Labeled field table — the screenshot's signature layout -->
                                        <dl class="tk-fields mt-3 space-y-2">
                                            <div class="tk-field">
                                                <dt class="tk-field__label">
                                                    <span class="material-symbols-outlined text-[14px]">tag</span>
                                                    Task ID
                                                </dt>
                                                <dd class="tk-field__value font-mono font-bold">{{ issueKey(item.id) }}</dd>
                                            </div>

                                            <div class="tk-field">
                                                <dt class="tk-field__label">
                                                    <span class="material-symbols-outlined text-[14px]">group</span>
                                                    Assignees
                                                </dt>
                                                <dd class="tk-field__value">
                                                    <span class="tk-avatar-stack">
                                                        <span v-if="item.assigned_to"
                                                              class="tk-avatar flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-black ring-2 ring-surface-container-lowest"
                                                              :style="`background:${avatarTone(item.assigned_to.name).bg};color:${avatarTone(item.assigned_to.name).fg}`"
                                                              :title="`Assigned to ${item.assigned_to.name}`">
                                                            {{ initials(item.assigned_to.name) }}
                                                        </span>
                                                        <span v-else class="text-[11px] text-on-surface-variant/50 italic">Unassigned</span>
                                                        <button v-if="canManage"
                                                                type="button"
                                                                @click.stop="openDrawer(item)"
                                                                class="tk-avatar tk-avatar--add flex h-6 w-6 items-center justify-center rounded-full border border-dashed border-outline-variant/70 text-on-surface-variant/50 hover:border-secondary hover:text-secondary transition-colors"
                                                                title="Add assignee">
                                                            <span class="material-symbols-outlined text-[14px]">add</span>
                                                        </button>
                                                    </span>
                                                </dd>
                                            </div>

                                            <div v-if="item.due_at || density !== 'compact'" class="tk-field">
                                                <dt class="tk-field__label">
                                                    <span class="material-symbols-outlined text-[14px]">calendar_month</span>
                                                    Due date
                                                </dt>
                                                <dd class="tk-field__value tabular-nums"
                                                    :class="item.is_overdue ? 'text-red-600 font-black' : ''">
                                                    <span v-if="item.due_at" class="inline-flex items-center gap-1">
                                                        <span v-if="item.is_overdue" class="material-symbols-outlined text-[13px] tk-overdue">warning</span>
                                                        {{ formatDate(item.due_at) }}
                                                    </span>
                                                    <span v-else class="text-on-surface-variant/50">—</span>
                                                </dd>
                                            </div>

                                            <div v-if="density !== 'compact'" class="tk-field">
                                                <dt class="tk-field__label">
                                                    <span class="material-symbols-outlined text-[14px]">timer</span>
                                                    Age
                                                </dt>
                                                <dd class="tk-field__value tabular-nums"
                                                    :style="`color:${ageMeta(item.created_at)?.color ?? 'inherit'}`">
                                                    <span v-if="ageMeta(item.created_at)" class="inline-flex items-center gap-1.5">
                                                        <span class="h-1.5 w-1.5 rounded-full"
                                                              :style="`background:${ageMeta(item.created_at).color};box-shadow:0 0 0 2px ${ageMeta(item.created_at).color}22`"></span>
                                                        {{ ageMeta(item.created_at).hours }} · {{ ageMeta(item.created_at).label }}
                                                    </span>
                                                    <span v-else>—</span>
                                                </dd>
                                            </div>
                                        </dl>

                                        <!-- Footer — requester, age compact, priority diamond accent -->
                                        <div class="tk-pcard__footer mt-3 pt-3 border-t border-outline-variant/40 flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-1.5 min-w-0">
                                                <span class="material-symbols-outlined text-[14px] text-on-surface-variant/50">person</span>
                                                <span class="text-[11px] text-on-surface-variant/70 truncate">{{ item.employee?.name ?? 'Unknown' }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                <span class="text-[10.5px] font-mono text-on-surface-variant/50 tabular-nums">{{ daysSince(item.created_at) }}</span>
                                                <span v-if="item.priority === 'critical' || item.priority === 'high'"
                                                      class="tk-prio-diamond flex h-4 w-4 items-center justify-center"
                                                      :style="`color:${priorityRail[item.priority]}`"
                                                      :title="`${item.priority_label} priority`">
                                                    <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">stat_1</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </KanbanBoard>
                        </div>
                    </div>

                    <p v-if="!canManage" class="mt-4 flex items-center gap-1.5 text-[11px] text-on-surface-variant/60 italic">
                        <span class="material-symbols-outlined text-[14px]">lock</span>
                        Read-only view — only ticket managers can move cards or change status.
                    </p>
                </div>

                <!-- ── List view (table) ───────────────────────────────────────── -->
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
                            placeholder="Provide detailed information about the issue, steps to reproduce, and expected outcome…"
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

            <!-- ── Jira-style detail drawer ──
                 Clicking a card opens this from the right edge. Keeps the user
                 on the board so triage can happen rapidly. Inline transitions,
                 priority, and assignee changes patch via the existing
                 quickStatus / quickAssign / quickPriority handlers. -->
            <SlidePanel
                :open="drawerTicket !== null"
                :title="drawerTicket ? `${issueKey(drawerTicket.id)} · ${drawerTicket.title}` : ''"
                size="lg"
                @close="closeDrawer"
            >
                <div v-if="drawerTicket" class="p-6 space-y-5">

                    <!-- Status + priority row (inline editors) -->
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Status pill -->
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Status</span>
                            <div class="inline-flex items-center rounded-lg border border-outline-variant/60 bg-surface-container-low p-0.5">
                                <button v-for="opt in [
                                    { id: 'open',        label: 'Open',        color: '#12d9e3' },
                                    { id: 'in_progress', label: 'In progress', color: '#1a237e' },
                                    { id: 'resolved',    label: 'Resolved',    color: '#16a34a' },
                                    { id: 'closed',      label: 'Closed',      color: '#64748b' },
                                ]" :key="opt.id" @click="canManage && quickStatus(drawerTicket, opt.id)"
                                        :disabled="!canManage"
                                        :class="['inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[11px] font-bold transition-all',
                                                  drawerTicket.status === opt.id
                                                    ? 'text-white shadow-sm'
                                                    : 'text-on-surface-variant hover:bg-surface-container',
                                                  !canManage ? 'cursor-not-allowed opacity-70' : '']"
                                        :style="drawerTicket.status === opt.id ? `background:${opt.color}` : ''">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${drawerTicket.status === opt.id ? 'rgba(255,255,255,0.85)' : opt.color}`"></span>
                                    {{ opt.label }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Priority inline editor -->
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 min-w-[60px]">Priority</span>
                        <div class="inline-flex items-center gap-1">
                            <button v-for="p in ['critical','high','medium','low']" :key="p"
                                    @click="canManage && quickPriority(drawerTicket, p)"
                                    :disabled="!canManage"
                                    :class="['inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10.5px] font-bold uppercase tracking-wider transition-all',
                                              drawerTicket.priority === p ? priorityClasses[p] + ' ring-1 ring-current' : 'text-on-surface-variant/60 hover:bg-surface-container',
                                              !canManage ? 'cursor-not-allowed opacity-70' : '']">
                                <span class="material-symbols-outlined text-[12px]">{{ priorityIcon[p] }}</span>
                                {{ p }}
                            </button>
                        </div>
                    </div>

                    <!-- Assignee inline editor -->
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 min-w-[60px]">Assignee</span>
                        <select
                            :value="drawerTicket.assigned_to?.id ?? ''"
                            @change="ev => canManage && quickAssign(drawerTicket, ev.target.value || null)"
                            :disabled="!canManage"
                            class="rounded-lg border-outline-variant bg-surface-container-low px-3 py-1.5 text-[12.5px] focus:border-secondary focus:ring-secondary/20"
                        >
                            <option value="">Unassigned</option>
                            <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <div v-if="drawerTicket.assigned_to"
                             class="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-black"
                             :style="`background:${avatarTone(drawerTicket.assigned_to.name).bg};color:${avatarTone(drawerTicket.assigned_to.name).fg}`">
                            {{ initials(drawerTicket.assigned_to.name) }}
                        </div>
                    </div>

                    <hr class="border-outline-variant/40"/>

                    <!-- Description -->
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Description</p>
                        <p class="text-[13px] text-on-surface leading-relaxed whitespace-pre-wrap">{{ drawerTicket.description || 'No description.' }}</p>
                    </div>

                    <!-- Meta grid -->
                    <div class="grid grid-cols-2 gap-3 text-[12px]">
                        <div class="rounded-xl border border-outline-variant/50 bg-surface-container-low/30 p-3">
                            <p class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60 mb-0.5">Requester</p>
                            <p class="font-bold text-primary">{{ drawerTicket.employee?.name ?? 'Unknown' }}</p>
                            <p v-if="drawerTicket.employee?.employee_no" class="text-[10.5px] font-mono text-on-surface-variant/60">{{ drawerTicket.employee.employee_no }}</p>
                        </div>
                        <div class="rounded-xl border border-outline-variant/50 bg-surface-container-low/30 p-3">
                            <p class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60 mb-0.5">Created</p>
                            <p class="font-bold text-primary">{{ daysSince(drawerTicket.created_at) }}</p>
                            <p class="text-[10.5px] text-on-surface-variant/60">{{ drawerTicket.created_at ? new Date(drawerTicket.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }}</p>
                        </div>
                        <div v-if="drawerTicket.due_at" class="rounded-xl border p-3"
                             :class="drawerTicket.is_overdue
                                ? 'border-red-300/60 bg-red-50/40 dark:bg-red-900/15'
                                : 'border-outline-variant/50 bg-surface-container-low/30'">
                            <p class="text-[9.5px] font-black uppercase tracking-widest mb-0.5"
                               :class="drawerTicket.is_overdue ? 'text-red-700/70' : 'text-on-surface-variant/60'">Due</p>
                            <p class="font-bold" :class="drawerTicket.is_overdue ? 'text-red-700' : 'text-primary'">{{ formatDate(drawerTicket.due_at) }}</p>
                            <p v-if="drawerTicket.is_overdue" class="text-[10.5px] font-black uppercase tracking-wider text-red-600">Overdue</p>
                        </div>
                        <div v-if="ageMeta(drawerTicket.created_at)" class="rounded-xl border border-outline-variant/50 bg-surface-container-low/30 p-3">
                            <p class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60 mb-0.5">Freshness</p>
                            <p class="font-bold capitalize" :style="`color:${ageMeta(drawerTicket.created_at).color}`">{{ ageMeta(drawerTicket.created_at).label }}</p>
                        </div>
                    </div>
                </div>

                <template #footer>
                    <div class="flex items-center justify-between gap-3">
                        <Link v-if="drawerTicket" :href="route('tickets.show', drawerTicket.id)"
                              class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                            <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                            Open full view
                        </Link>
                        <div class="flex items-center gap-2">
                            <button v-if="canManage && drawerTicket && drawerTicket.status !== 'resolved' && drawerTicket.status !== 'closed'"
                                    @click="quickStatus(drawerTicket, 'resolved'); closeDrawer()"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-[13px] font-black uppercase tracking-wide text-emerald-700 hover:bg-emerald-100 transition-colors">
                                <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                Resolve
                            </button>
                            <button @click="closeDrawer"
                                    class="rounded-xl px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                                Close
                            </button>
                        </div>
                    </div>
                </template>
            </SlidePanel>

    </div>
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

/* ── Jira issue key — slightly off-balance, monospace, deserves space ── */
.tk-key {
    padding: 1px 5px;
    border-radius: 4px;
    background: rgba(26, 35, 126, 0.06);
    color: #1a237e !important;
}
.dark .tk-key {
    background: rgba(121, 134, 203, 0.16);
    color: #c5cae9 !important;
}

/* ── Density modes — Jira's "Compact" vs "Comfortable" ──
   Compact tightens vertical spacing inside every card and drops the
   description + due-date row to maximise on-screen card count. */
.tk-density--compact :deep(.kb-zone) {
    gap: 4px !important;
}
.tk-density--compact .tk-title {
    font-size: 12px !important;
    -webkit-line-clamp: 1 !important;
    line-clamp: 1 !important;
}
.tk-density--compact .tk-meta {
    margin-top: 6px !important;
}
.tk-density--compact .tk-avatar {
    height: 20px !important;
    width:  20px !important;
    font-size: 9px !important;
}

/* Quick-filter chips — subtle press feedback */
.tk-chip:active { transform: translateY(1px); }

/* Swimlane subtle bg gradient — keeps groups feeling like distinct lanes
   without heavy borders. */
.tk-swimlane { animation: tkSwimIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes tkSwimIn {
    0%   { opacity: 0; transform: translateY(8px); }
    100% { opacity: 1; transform: translateY(0); }
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

/* ─────────────────────────────────────────────────────────────
   Plaky/Asana-style card — labeled-row field table inside each
   ticket card. Bigger title up top, two pills below, then a
   compact field grid with iconographic labels, then a divider
   footer with requester + age + priority diamond.
─────────────────────────────────────────────────────────────── */
.tk-pcard {
    padding-block: 2px;
}
.tk-pcard__title {
    color: rgb(var(--ct-on-surface));
}

/* Label pills — slightly chunky, uppercase-bold, rounded-md (not full
   pill) to match Plaky's slab look. */
.tk-label {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 800;
    line-height: 1.2;
    text-transform: capitalize;
    letter-spacing: 0.01em;
    white-space: nowrap;
}

/* Status pill colours — mirror the reference's vivid label palette. */
.tk-label--open        { background: #ffe8d6; color: #c2410c; }
.tk-label--in_progress { background: #fce7f3; color: #be185d; }
.tk-label--resolved    { background: #dcfce7; color: #15803d; }
.tk-label--closed      { background: #e2e8f0; color: #475569; }
.dark .tk-label--open        { background: rgba(194, 65, 12, 0.20); color: #fdba74; }
.dark .tk-label--in_progress { background: rgba(190, 24, 93, 0.20); color: #f9a8d4; }
.dark .tk-label--resolved    { background: rgba(21, 128, 61, 0.20); color: #86efac; }
.dark .tk-label--closed      { background: rgba(71, 85, 105, 0.30); color: #cbd5e1; }

/* Priority pill colours — semantic with a "yellow MOFU" warm middle.
   Critical=red slab, High=orange, Medium=warm yellow (MOFU vibe),
   Low=cool blue. */
.tk-label--prio-critical { background: #fee2e2; color: #b91c1c; }
.tk-label--prio-high     { background: #ffedd5; color: #c2410c; }
.tk-label--prio-medium   { background: #fef9c3; color: #a16207; }
.tk-label--prio-low      { background: #e0f2fe; color: #0369a1; }
.dark .tk-label--prio-critical { background: rgba(185, 28, 28, 0.20); color: #fca5a5; }
.dark .tk-label--prio-high     { background: rgba(194, 65, 12, 0.20); color: #fdba74; }
.dark .tk-label--prio-medium   { background: rgba(161, 98, 7, 0.22); color: #fde047; }
.dark .tk-label--prio-low      { background: rgba(3, 105, 161, 0.22); color: #7dd3fc; }

/* Field rows — the labeled-row signature. Icon + name on the left,
   value on the right, baseline-aligned. */
.tk-fields {
    margin: 0;
}
.tk-field {
    display: grid;
    grid-template-columns: minmax(96px, 0.9fr) 1fr;
    align-items: center;
    gap: 8px;
    min-height: 22px;
}
.tk-field__label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: rgb(var(--ct-on-surface-variant) / 0.85);
}
.tk-field__label .material-symbols-outlined {
    color: rgb(var(--ct-on-surface-variant) / 0.55);
}
.tk-field__value {
    font-size: 12.5px;
    font-weight: 600;
    color: rgb(var(--ct-on-surface));
    margin: 0;
    min-width: 0;
}

/* Avatar stack — overlapping circles with an "+ add" affordance on
   the right, matching the reference's UI. */
.tk-avatar-stack {
    display: inline-flex;
    align-items: center;
}
.tk-avatar-stack > .tk-avatar + .tk-avatar {
    margin-left: -6px;
}
.tk-avatar--add {
    margin-left: 4px;
}

/* Priority diamond — a small filled diamond in the footer for
   critical/high tickets. Visually echoes the reference's red diamond. */
.tk-prio-diamond {
    transform: rotate(45deg);
    line-height: 1;
}
.tk-prio-diamond > .material-symbols-outlined {
    transform: rotate(-45deg);
}
</style>
