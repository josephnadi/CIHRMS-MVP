<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    employees:    Object, // paginated: { data: [], links: [], meta: { total, from, to, active_count, on_leave_count, departments_count } }
    departments:  Array,  // [{ id, name, code }]
    filters:      Object, // { search, department_id, status }
    activeModule: String,
});

// â”€â”€ Filter state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const localFilters = reactive({
    search:        props.filters?.search        ?? '',
    department_id: props.filters?.department_id ?? '',
    status:        props.filters?.status        ?? '',
});

const applyFilters = () => {
    router.get(
        route('employees.index'),
        {
            search:        localFilters.search        || undefined,
            department_id: localFilters.department_id || undefined,
            status:        localFilters.status        || undefined,
        },
        { preserveState: true, replace: true },
    );
};

// Debounced search watcher
let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

// â”€â”€ Panels / dialogs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showAddPanel     = ref(false);
const showDeptPanel    = ref(false);
const showDeleteDialog = ref(false);
const selectedId       = ref(null);

// Auto-open create panel when arriving via Quick Action (?new=1)
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('new') === '1') {
        showAddPanel.value = true;
    }
});

// â”€â”€ Add Employee form (creates User + Employee in one POST) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const form = useForm({
    create_user:   true,
    user_name:     '',
    user_email:    '',
    user_role:     'employee',
    user_password: '',
    staff_id:      '',
    department_id: '',
    manager_id:    '',
    employee_no:   '',
    position:      '',
    hire_date:     '',
    phone:         '',
    status:        'active',
});

const submitEmployee = () => {
    form.post(route('employees.store'), {
        onSuccess: () => {
            form.reset();
            showAddPanel.value = false;
        },
    });
};

// â”€â”€ Add Department form â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const deptForm = useForm({
    name:        '',
    code:        '',
    description: '',
});

const submitDepartment = () => {
    deptForm.post(route('storeDepartment'), {
        onSuccess: () => {
            deptForm.reset();
            showDeptPanel.value = false;
        },
    });
};

// â”€â”€ Delete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const confirmDelete = (id, event) => {
    event.stopPropagation();
    selectedId.value = id;
    showDeleteDialog.value = true;
};

const doDelete = () => {
    router.delete(route('employees.destroy', selectedId.value), {
        onFinish: () => {
            showDeleteDialog.value = false;
            selectedId.value = null;
        },
    });
};

// ── Inline status change ─────────────────────────────────────────
// HR can flip an employee between active / on_leave / inactive /
// terminated without opening the full edit form. PATCHes only the
// `status` field (UpdateEmployeeRequest accepts a partial update).
const statusMenuFor = ref(null);
const STATUS_OPTIONS = [
    { value: 'active',     label: 'Active',     icon: 'check_circle' },
    { value: 'on_leave',   label: 'On leave',   icon: 'beach_access' },
    { value: 'inactive',   label: 'Inactive',   icon: 'pause_circle' },
    { value: 'terminated', label: 'Terminated', icon: 'cancel'       },
];

const toggleStatusMenu = (empId, event) => {
    event?.stopPropagation();
    statusMenuFor.value = statusMenuFor.value === empId ? null : empId;
};

const setEmployeeStatus = (emp, newStatus, event) => {
    event?.stopPropagation();
    statusMenuFor.value = null;
    if (emp.status === newStatus) return;
    if (newStatus === 'terminated') {
        if (! window.confirm(`Mark ${emp.user?.name ?? 'this employee'} as TERMINATED?\n\nThis is a final-state change. Off-board the employee separately if needed.`)) return;
    }
    router.patch(
        route('employees.update', emp.id),
        { status: newStatus },
        { preserveScroll: true },
    );
};

// Close the status menu on any outside click. Registered + cleaned up via
// component lifecycle so leaving the page doesn't leak a listener.
const onOutsideEmpStatusClick = (e) => {
    if (! e.target.closest('.emp-status-menu')) statusMenuFor.value = null;
};
onMounted(() => document.addEventListener('click', onOutsideEmpStatusClick));
onBeforeUnmount(() => document.removeEventListener('click', onOutsideEmpStatusClick));

// â”€â”€ Stats derived from meta (backend should provide these) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const stats = computed(() => ({
    total:       props.employees?.meta?.total             ?? 0,
    active:      props.employees?.meta?.active_count      ?? 0,
    onLeave:     props.employees?.meta?.on_leave_count    ?? 0,
    departments: props.employees?.meta?.departments_count ?? props.departments?.length ?? 0,
}));

// ── Editorial-Sovereign masthead label ───────────────────────────
// Mirrors Dashboard.vue: long-form date + Vol. (Roman, years since 2023
// platform inception) + No. (day-of-year). Recomputed on render — the
// roster page does not need a live ticker, but the format stays consistent.
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

// Avatar gradients — disciplined cool family aligned with "Sovereign Precision".
// People module = navy/cobalt dominant + one magenta variant (5% accent for people)
// and one cyan variant (tech/young). No warm reds or ambers — they pollute the
// page-wide palette and break the institutional feel.
const gradients = [
    'linear-gradient(135deg,#0d1452,#1a237e)',          // navy → cobalt (institutional)
    'linear-gradient(135deg,#1a237e,#7986cb)',          // cobalt → soft sky
    'linear-gradient(135deg,#070b3a,#0d1452)',          // deep navy → navy (senior)
    'linear-gradient(135deg,#1a237e,#3949ab)',          // cobalt → bright blue
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',  // navy → cobalt → magenta (people spark)
    'linear-gradient(135deg,#1a237e,#12d9e3)',          // cobalt → cyan (tech)
];

const avatarGradient = (id) => gradients[id % gradients.length];

const initials = (name) => {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
};

const formatDate = (d) => {
    if (!d) return 'â€”';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head title="Employees" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- ── Header slot · Editorial Sovereign masthead ─────────────── -->
        <template #header>
            <div class="space-y-6">
                <!-- Masthead strip -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">WORKFORCE ROSTER</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · updated
                    </span>
                </div>

                <!-- Broadsheet hero -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Personnel · Establishment register</p>
                        <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                            The active
                            <span class="es-display-italic">roster.</span>
                        </h2>
                        <p class="es-display-sub">
                            Every name on record — positions, postings, and employment standing across the institute.
                            Filter, audit, and amend the establishment from this directory.
                        </p>

                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <button @click="showAddPanel = true" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">person_add</span>
                                Add Employee
                            </button>
                            <span class="text-on-surface-variant/30">·</span>
                            <button @click="showDeptPanel = true" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">corporate_fare</span>
                                Add Department
                            </button>
                            <span class="text-on-surface-variant/30">·</span>
                            <button @click="router.visit(route('departments.index'))" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">account_tree</span>
                                Departments
                            </button>
                        </div>
                    </div>

                    <!-- SIDEBAR column · feature KPI -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Workforce on record</p>
                            <p class="es-stat-hero-value">{{ stats.total.toLocaleString() }}</p>
                            <p class="es-stat-hero-caption">
                                Across {{ stats.departments }} department{{ stats.departments === 1 ? '' : 's' }} · {{ stats.active.toLocaleString() }} active
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">badge</span>
                                Establishment of the Institute
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Supporting metrics strip -->
                <div class="es-stat-strip rounded-none">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Total</p>
                        <p class="es-stat-cell-value">{{ stats.total.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Names on the register</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Active</p>
                        <p class="es-stat-cell-value">{{ stats.active.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Currently in post</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">On Leave</p>
                        <p class="es-stat-cell-value">{{ stats.onLeave.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Sanctioned absence</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Departments</p>
                        <p class="es-stat-cell-value">{{ stats.departments.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Organisational units</p>
                    </div>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- 5% gold hairline — the single institutional accent moment on this page -->
            <div class="h-px w-full" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.45),transparent)"></div>

            <!-- Filters strip -->
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <div class="flex items-center gap-2 pl-2 pr-1 text-on-surface-variant/60">
                    <span class="material-symbols-outlined text-[18px]" style="color:#1a237e">filter_list</span>
                    <span class="text-[10px] font-black uppercase tracking-[0.18em]">Filter</span>
                </div>

                <div class="flex-1 min-w-[220px] max-w-sm">
                    <SearchInput
                        v-model="localFilters.search"
                        placeholder="Search name, ID, position…"
                    />
                </div>

                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">corporate_fare</span>
                    <select
                        v-model="localFilters.department_id"
                        @change="applyFilters"
                        class="appearance-none rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-9 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Departments</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                    <span class="material-symbols-outlined pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/60">expand_more</span>
                </div>

                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">workspaces</span>
                    <select
                        v-model="localFilters.status"
                        @change="applyFilters"
                        class="appearance-none rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-9 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="on_leave">On Leave</option>
                        <option value="inactive">Inactive</option>
                        <option value="terminated">Terminated</option>
                    </select>
                    <span class="material-symbols-outlined pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/60">expand_more</span>
                </div>

                <button
                    v-if="localFilters.search || localFilters.department_id || localFilters.status"
                    @click="() => { localFilters.search = ''; localFilters.department_id = ''; localFilters.status = ''; applyFilters(); }"
                    class="ml-auto flex items-center gap-1.5 rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container hover:border-red-300/60 hover:text-red-600 transition-all"
                >
                    <span class="material-symbols-outlined text-[16px]">backspace</span>
                    Clear
                </button>
            </div>

            <!-- â”€â”€ Employee table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">

                <div v-if="employees?.data?.length === 0" class="p-12">
                    <EmptyState
                        title="No employees found"
                        description="Try adjusting your search or filters, or add a new employee."
                        icon="badge"
                    >
                        <template #action>
                            <button
                                @click="showAddPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">person_add</span>
                                Add Employee
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="max-h-[calc(100vh-440px)] min-h-[280px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr class="relative">
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Employee</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Staff ID</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Department</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Position</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Hire Date</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr
                                v-for="(emp, idx) in employees.data"
                                :key="emp.id"
                                class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                                @click="router.get(route('employees.show', emp.id))"
                            >
                                <!-- Avatar + name -->
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-9 w-9 flex-shrink-0 rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm flex items-center justify-center text-[11px] font-black text-white overflow-hidden transition-transform group-hover:scale-105"
                                            :style="emp.avatar_url ? '' : `background:${avatarGradient(emp.id)}`"
                                        >
                                            <img v-if="emp.avatar_url" :src="emp.avatar_url" :alt="emp.user?.name" class="h-full w-full object-cover" />
                                            <span v-else>{{ initials(emp.user?.name) }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-[13px] font-bold text-on-surface leading-tight truncate">{{ emp.user?.name ?? '—' }}</p>
                                            <p class="mt-0.5 text-[11px] text-on-surface-variant/60 leading-tight truncate">
                                                {{ emp.user?.email ?? '' }}
                                                <span v-if="emp.manager?.name" class="ml-1 text-on-surface-variant/45">· reports to {{ emp.manager.name }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span class="font-mono text-[12px] text-on-surface-variant tracking-tight">{{ emp.employee_no }}</span>
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    {{ emp.department?.name ?? '—' }}
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    {{ emp.position ?? '—' }}
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant tabular-nums">
                                    {{ formatDate(emp.hire_date) }}
                                </td>

                                <td class="px-4 py-3.5">
                                    <StatusBadge :status="emp.status?.value ?? emp.status" type="employee" />
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3.5" @click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        <Link
                                            :href="route('employees.show', emp.id)"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-secondary/10 hover:text-secondary hover:border-secondary/15 transition-all"
                                            title="View employee"
                                            aria-label="View employee"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">visibility</span>
                                        </Link>

                                        <!-- Inline status change -->
                                        <div class="emp-status-menu relative">
                                            <button
                                                type="button"
                                                @click="toggleStatusMenu(emp.id, $event)"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-amber-500/10 hover:text-amber-600 hover:border-amber-500/15 transition-all"
                                                title="Change status"
                                                aria-label="Change employee status"
                                            >
                                                <span class="material-symbols-outlined text-[17px]">swap_vert</span>
                                            </button>
                                            <div v-if="statusMenuFor === emp.id"
                                                 class="absolute right-0 top-9 z-30 w-44 rounded-xl border border-outline-variant/60 bg-surface-container-lowest shadow-lifted py-1.5">
                                                <button v-for="opt in STATUS_OPTIONS"
                                                        :key="opt.value"
                                                        type="button"
                                                        @click="setEmployeeStatus(emp, opt.value, $event)"
                                                        :disabled="emp.status === opt.value"
                                                        class="w-full flex items-center gap-2 px-3 py-2 text-left text-[12px] font-semibold transition-colors"
                                                        :class="emp.status === opt.value
                                                            ? 'text-secondary cursor-default bg-secondary/[0.05]'
                                                            : 'text-on-surface hover:bg-surface-container'">
                                                    <span class="material-symbols-outlined text-[15px]">{{ opt.icon }}</span>
                                                    {{ opt.label }}
                                                    <span v-if="emp.status === opt.value" class="material-symbols-outlined text-[15px] ml-auto">check</span>
                                                </button>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            @click="confirmDelete(emp.id, $event)"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-red-500/10 hover:text-red-600 hover:border-red-500/15 transition-all"
                                            title="Delete employee"
                                            aria-label="Delete employee"
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

                <!-- Pagination -->
                <div v-if="employees?.links?.length > 3" class="border-t border-outline-variant/50 bg-surface-container-low/40 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="flex items-center gap-1.5 text-[12px] text-on-surface-variant">
                            <span class="material-symbols-outlined text-[15px]" style="color:#1a237e;opacity:0.7">format_list_numbered</span>
                            Showing
                            <span class="font-bold text-on-surface tabular-nums">{{ employees.meta?.from }}</span>
                            –
                            <span class="font-bold text-on-surface tabular-nums">{{ employees.meta?.to }}</span>
                            of
                            <span class="font-bold text-on-surface tabular-nums">{{ employees.meta?.total }}</span>
                        </p>
                        <Pagination :links="employees.links" />
                    </div>
                </div>
            </div>
        </div>

        <!-- â”€â”€ Add Employee SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showAddPanel"
            title="Add Employee"
            size="lg"
            @close="showAddPanel = false"
        >
            <form @submit.prevent="submitEmployee" class="space-y-5 p-6">

                <!-- Login account block — cobalt-tinted (action context) -->
                <div class="rounded-2xl border border-secondary/15 bg-secondary/[0.04] p-4 space-y-4 relative overflow-hidden">
                    <div class="pointer-events-none absolute -top-6 -right-6 h-20 w-20 rounded-full" style="background:radial-gradient(circle,rgba(26, 35, 126,0.10),transparent 70%)"></div>
                    <div class="relative flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.18em] text-secondary">
                        <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">badge</span>
                        Login account
                    </div>

                    <!-- Name -->
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Full Name <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.user_name"
                            type="text"
                            placeholder="e.g. Ama Asante"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.user_name }"
                        />
                        <p v-if="form.errors.user_name" class="mt-1 text-[11px] text-red-500">{{ form.errors.user_name }}</p>
                    </div>

                    <!-- Email + Staff ID -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Email <span class="text-red-500">*</span></label>
                            <input
                                v-model="form.user_email"
                                type="email"
                                placeholder="employee@cihrm.gov.gh"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.user_email }"
                            />
                            <p v-if="form.errors.user_email" class="mt-1 text-[11px] text-red-500">{{ form.errors.user_email }}</p>
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Staff ID</label>
                            <input
                                v-model="form.staff_id"
                                type="text"
                                placeholder="GH-XX-000"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.staff_id }"
                            />
                            <p v-if="form.errors.staff_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.staff_id }}</p>
                        </div>
                    </div>

                    <!-- Role + temporary password -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">System Role <span class="text-red-500">*</span></label>
                            <select
                                v-model="form.user_role"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.user_role }"
                            >
                                <option value="employee">Employee</option>
                                <option value="manager">Line Manager</option>
                                <option value="dept_head">Department Head</option>
                                <option value="hr_admin">HR Admin</option>
                                <option value="finance_officer">Finance Officer</option>
                                <option value="it_support">IT Support</option>
                                <option value="auditor">Auditor</option>
                            </select>
                            <p v-if="form.errors.user_role" class="mt-1 text-[11px] text-red-500">{{ form.errors.user_role }}</p>
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Temporary Password <span class="text-red-500">*</span></label>
                            <input
                                v-model="form.user_password"
                                type="password"
                                autocomplete="new-password"
                                placeholder="Min 8 characters"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.user_password }"
                            />
                            <p v-if="form.errors.user_password" class="mt-1 text-[11px] text-red-500">{{ form.errors.user_password }}</p>
                        </div>
                    </div>
                </div>

                <!-- Department + Employee No -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Department <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.department_id"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.department_id }"
                        >
                            <option value="" disabled>Select department</option>
                            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                        </select>
                        <p v-if="form.errors.department_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.department_id }}</p>
                    </div>

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee No <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.employee_no"
                            type="text"
                            placeholder="GH-2024-001"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.employee_no }"
                        />
                        <p v-if="form.errors.employee_no" class="mt-1 text-[11px] text-red-500">{{ form.errors.employee_no }}</p>
                    </div>
                </div>

                <!-- Position + Phone -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Position</label>
                        <input
                            v-model="form.position"
                            type="text"
                            placeholder="e.g. Senior Analyst"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Phone</label>
                        <input
                            v-model="form.phone"
                            type="tel"
                            placeholder="+233 24 000 0000"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <!-- Hire Date + Status -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Hire Date</label>
                        <input
                            v-model="form.hire_date"
                            type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Status</label>
                        <select
                            v-model="form.status"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
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
                        @click="submitEmployee"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Save Employee</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- â”€â”€ Add Department SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showDeptPanel"
            title="Add Department"
            size="md"
            @close="showDeptPanel = false"
        >
            <form @submit.prevent="submitDepartment" class="space-y-5 p-6">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Department Name <span class="text-red-500">*</span></label>
                    <input
                        v-model="deptForm.name"
                        type="text"
                        placeholder="e.g. Information Technology"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': deptForm.errors.name }"
                    />
                    <p v-if="deptForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ deptForm.errors.name }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Department Code <span class="text-red-500">*</span>
                        <span class="ml-1 font-normal text-on-surface-variant/60">(2â€“10 chars, uppercase)</span>
                    </label>
                    <input
                        v-model="deptForm.code"
                        type="text"
                        placeholder="e.g. IT"
                        maxlength="10"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all uppercase"
                        :class="{ 'border-red-400': deptForm.errors.code }"
                    />
                    <p v-if="deptForm.errors.code" class="mt-1 text-[11px] text-red-500">{{ deptForm.errors.code }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description</label>
                    <textarea
                        v-model="deptForm.description"
                        rows="3"
                        placeholder="Brief description of this departmentâ€¦"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showDeptPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitDepartment"
                        :disabled="deptForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span v-if="deptForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Save Department</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- â”€â”€ Delete confirmation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <ConfirmDialog
            :open="showDeleteDialog"
            title="Delete Employee"
            message="Are you sure you want to delete this employee? This action cannot be undone and will remove all associated records."
            :danger="true"
            @confirm="doDelete"
            @cancel="showDeleteDialog = false"
        />

    </AuthenticatedLayout>
</template>
