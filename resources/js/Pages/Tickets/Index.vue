<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
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

// ── Kanban columns derived from tickets.data ─────────────────────────────────
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
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Ticket
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard :value="stats.total" label="Total Tickets" icon="confirmation_number" color="#0051d5" />
                <StatCard :value="stats.open" label="Open" icon="inbox" color="#316bf3" />
                <StatCard :value="stats.inProgress" label="In Progress" icon="autorenew" color="#7c3aed" />
                <StatCard :value="stats.overdue" label="Overdue" icon="schedule" color="#dc2626" />
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] max-w-xs">
                    <SearchInput v-model="localFilters.search" placeholder="Search title or description…" />
                </div>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>

                <select
                    v-model="localFilters.priority"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Priorities</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>

                <select
                    v-if="canManage"
                    v-model="localFilters.assigned_to"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Assignees</option>
                    <option v-for="user in staff" :key="user.id" :value="user.id">{{ user.name }}</option>
                </select>

                <label class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low px-3.5 py-2.5 text-[13px] cursor-pointer">
                    <input
                        type="checkbox"
                        :checked="!!localFilters.overdue"
                        @change="ev => { localFilters.overdue = ev.target.checked ? '1' : ''; applyFilters(); }"
                        class="h-3.5 w-3.5 accent-red-500"
                    />
                    <span class="font-semibold text-on-surface-variant">Overdue only</span>
                </label>

                <button
                    v-if="localFilters.search || localFilters.status || localFilters.priority || localFilters.assigned_to || localFilters.overdue"
                    @click="clearFilters"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- ── Board view (Kanban) ─────────────────────────────────────── -->
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
                                style="background:linear-gradient(135deg,#0051d5,#316bf3)"
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
                        <Link :href="route('tickets.show', item.id)" class="block">
                            <!-- Priority chip + title -->
                            <div class="flex items-start gap-2 mb-2">
                                <span
                                    :class="['inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md text-[9.5px] font-bold uppercase tracking-wider whitespace-nowrap', priorityClasses[item.priority]]"
                                    :title="`Priority: ${item.priority_label}`"
                                >
                                    <span class="material-symbols-outlined text-[12px]">{{ priorityIcon[item.priority] }}</span>
                                    {{ item.priority_label }}
                                </span>
                                <span class="text-[10px] font-mono text-on-surface-variant/50">#{{ item.id }}</span>
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
                                    class="flex items-center gap-1 rounded-full bg-violet-500/10 px-1.5 py-0.5 text-violet-700 dark:text-violet-300"
                                >
                                    <span class="material-symbols-outlined text-[12px]">badge</span>
                                    <span class="font-bold truncate max-w-[80px]">{{ item.assigned_to.name }}</span>
                                </div>
                            </div>

                            <!-- Due date -->
                            <div v-if="item.due_at" class="mt-2 flex items-center gap-1 text-[10px]"
                                 :class="item.is_overdue ? 'text-red-600 font-bold' : 'text-on-surface-variant/60'">
                                <span class="material-symbols-outlined text-[12px]">schedule</span>
                                <span>Due {{ formatDate(item.due_at) }}</span>
                                <span v-if="item.is_overdue" class="ml-auto text-[9px] font-black uppercase tracking-wider">Overdue</span>
                            </div>
                        </Link>
                    </template>
                </KanbanBoard>

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
                                style="background:linear-gradient(135deg,#0051d5,#316bf3)"
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
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Ticket</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Requester</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Priority</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Assigned</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Due</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr
                                v-for="ticket in tickets.data"
                                :key="ticket.id"
                                class="border-b border-outline-variant/50 hover:bg-surface-container/40 cursor-pointer transition-colors"
                                @click="router.get(route('tickets.show', ticket.id))"
                            >
                                <td class="px-4 py-3.5">
                                    <p class="text-[13px] font-semibold text-on-surface leading-tight">{{ ticket.title }}</p>
                                    <p class="text-[11px] text-on-surface-variant/60 leading-tight">#{{ ticket.id }} · opened {{ daysSince(ticket.created_at) }}</p>
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    <p class="leading-tight">{{ ticket.employee?.name ?? '—' }}</p>
                                    <p class="text-[11px] text-on-surface-variant/60 leading-tight font-mono">{{ ticket.employee?.employee_no ?? '' }}</p>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span
                                        :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider', priorityClasses[ticket.priority]]"
                                    >
                                        <span class="material-symbols-outlined text-[14px]">{{ priorityIcon[ticket.priority] }}</span>
                                        {{ ticket.priority_label }}
                                    </span>
                                </td>

                                <td class="px-4 py-3.5">
                                    <StatusBadge :status="ticket.status" type="ticket" />
                                </td>

                                <td class="px-4 py-3.5" @click.stop>
                                    <select
                                        v-if="canManage"
                                        :value="ticket.assigned_to?.id ?? ''"
                                        @change="ev => quickAssign(ticket, ev.target.value)"
                                        class="rounded-lg border border-outline-variant/60 bg-surface-container-low px-2 py-1 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50 max-w-[140px]"
                                    >
                                        <option value="">Unassigned</option>
                                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                    </select>
                                    <span v-else class="text-[12px] text-on-surface-variant">{{ ticket.assigned_to?.name ?? 'Unassigned' }}</span>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span
                                        :class="['text-[12px] flex items-center gap-1', ticket.is_overdue ? 'text-red-600 font-semibold' : 'text-on-surface-variant']"
                                    >
                                        <span v-if="ticket.is_overdue" class="material-symbols-outlined text-[14px] text-red-500">schedule</span>
                                        {{ formatDate(ticket.due_at) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3.5" @click.stop>
                                    <div class="flex items-center gap-1">
                                        <Link
                                            :href="route('tickets.show', ticket.id)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-secondary/10 hover:text-secondary transition-colors"
                                            title="View"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">visibility</span>
                                        </Link>
                                        <button
                                            v-if="canManage && ticket.status !== 'resolved' && ticket.status !== 'closed'"
                                            @click="quickStatus(ticket, 'resolved')"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-green-500/10 hover:text-green-600 transition-colors"
                                            title="Mark Resolved"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">check_circle</span>
                                        </button>
                                        <button
                                            v-if="canManage"
                                            @click="confirmDelete(ticket.id, $event)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-red-500/10 hover:text-red-600 transition-colors"
                                            title="Delete"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="tickets?.links?.length > 3" class="border-t border-outline-variant/50 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[12px] text-on-surface-variant">
                            Showing
                            <span class="font-semibold text-on-surface">{{ tickets.meta?.from }}</span>
                            –
                            <span class="font-semibold text-on-surface">{{ tickets.meta?.to }}</span>
                            of
                            <span class="font-semibold text-on-surface">{{ tickets.meta?.total }}</span>
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
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
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
