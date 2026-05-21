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
import MultiDonut from '@/Components/charts/MultiDonut.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    employees:    Object, // paginated: { data: [], links: [], meta: { total, from, to } }
    departments:  { type: [Object, Array], default: () => [] }, // Resource collection {data:[…]} or bare array
    benefitPlans: { type: [Object, Array], default: () => [] }, // active plans for the create panel
    stats:        { type: Object, default: () => ({}) },
    filters:      Object, // { search, department_id, status }
    activeModule: String,
});

// Normalise benefitPlans the same way departments are handled.
const benefitPlans = computed(() => {
    const b = props.benefitPlans;
    if (Array.isArray(b)) return b;
    if (b && Array.isArray(b.data)) return b.data;
    return [];
});

// Normalise departments — controller wraps it in a Resource collection
// ({ data: [...] }), but earlier callers passed a bare array. Accept both.
const departments = computed(() => {
    const d = props.departments;
    if (Array.isArray(d)) return d;
    if (d && Array.isArray(d.data)) return d.data;
    return [];
});

// ── Filter state ─────────────────────────────────────────────────────────────
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

// ── Panels / dialogs ─────────────────────────────────────────────────────────
const showAddPanel     = ref(false);
const showDeptPanel    = ref(false);
const showDeleteDialog = ref(false);
const selectedId       = ref(null);

// Auto-open create panel when arriving via Quick Action (?new=1).
// Strip the flag from the URL immediately so a refresh — or the post-submit
// back()/redirect cycle — doesn't re-trigger the panel and leave the backdrop
// stuck over the page.
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

// ── Add Employee form (creates User + Employee in one POST) ──────────────────
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
    benefit_plan_ids: [],
});

const togglePlan = (id) => {
    const idx = form.benefit_plan_ids.indexOf(id);
    if (idx === -1) form.benefit_plan_ids.push(id);
    else form.benefit_plan_ids.splice(idx, 1);
};
const formatGhs = (n) => 'GHS ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const submitEmployee = () => {
    // Close the panel first so the backdrop animates out before Inertia's
    // success-redirect refetches the page. Closing inside onSuccess raced
    // with the prop merge and left the backdrop stuck over the page after
    // the toast had already fired. Mirrors the Add Department flow.
    form.post(route('employees.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showAddPanel.value = false;
            form.reset();
        },
        onError: () => {
            // Validation errors → keep the panel open so the user can see
            // which field rejected. form.errors is already populated.
        },
    });
};

// ── Add Department form ──────────────────────────────────────────────────────
const deptForm = useForm({
    name:        '',
    code:        '',
    description: '',
});

const submitDepartment = () => {
    deptForm.post(route('departments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            deptForm.reset();
            showDeptPanel.value = false;
        },
    });
};

// ── Delete ───────────────────────────────────────────────────────────────────
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

// ── Dashboard aggregates from backend EmployeeService::stats() ──────────────
// Status / department / tenure breakdowns are scoped to the same RBAC view as
// the table below, so an HR admin sees the whole institute and a dept_head
// sees only their slice.
const totalHeadcount = computed(() => props.stats?.total ?? 0);

const statusCounts = computed(() => ({
    active:     props.stats?.status?.active     ?? 0,
    on_leave:   props.stats?.status?.on_leave   ?? 0,
    inactive:   props.stats?.status?.inactive   ?? 0,
    terminated: props.stats?.status?.terminated ?? 0,
}));

// Status colours — disciplined cool palette. Red kept for `terminated` as a
// deliberate severity signal (employment ended); the rest stay in the brand
// cobalt/sky/slate family to honour the "Sovereign Precision" direction.
const STATUS_PALETTE = {
    active:     { color: '#1a237e', label: 'Active'     },
    on_leave:   { color: '#7986cb', label: 'On leave'   },
    inactive:   { color: '#94a3b8', label: 'Inactive'   },
    terminated: { color: '#b91c1c', label: 'Terminated' },
};

const statusSegments = computed(() =>
    Object.entries(statusCounts.value)
        .filter(([, v]) => v > 0)
        .map(([k, v]) => ({ key: k, label: STATUS_PALETTE[k].label, value: v, color: STATUS_PALETTE[k].color })),
);

// Department donut — brand gradient walk so adjacent departments are visually
// distinct without sliding into a rainbow.
const DEPT_PALETTE = ['#0d1452', '#1a237e', '#3949ab', '#7986cb', '#12d9e3', '#d912e3'];
const OTHER_COLOR  = '#cbd5e1';

const departmentSegments = computed(() => {
    const top = props.stats?.top_departments ?? [];
    const segs = top.map((d, i) => ({
        key:   `dept-${d.id}`,
        label: d.name,
        value: d.count,
        color: DEPT_PALETTE[i % DEPT_PALETTE.length],
    }));
    const other = props.stats?.other_departments ?? 0;
    if (other > 0) {
        segs.push({ key: 'other', label: 'Other depts', value: other, color: OTHER_COLOR });
    }
    return segs;
});

// Headline tiles — the four numbers everyone actually looks at first.
const activeRate = computed(() => {
    const t = totalHeadcount.value;
    return t === 0 ? 0 : Math.round((statusCounts.value.active / t) * 100);
});

const newHires30d = computed(() => props.stats?.recent_hires_30d ?? 0);
const onLeavePct  = computed(() => {
    const t = totalHeadcount.value;
    return t === 0 ? 0 : Math.round((statusCounts.value.on_leave / t) * 100);
});
const deptCount = computed(() => props.stats?.departments_count ?? departments.value.length);

const pctOf = (n) => {
    const t = totalHeadcount.value;
    return t === 0 ? 0 : Math.round((n / t) * 100);
};

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
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head title="Employees" />
    <div data-page-root="true">
            <!-- ── Header slot · executive header ─────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">badge</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">WORKFORCE REGISTER</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Employee Directory</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">Every name on record — positions, postings, and employment standing across the institute.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            @click="showAddPanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">person_add</span>
                            Add Employee
                        </button>
                        <button
                            @click="showDeptPanel = true"
                            class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 text-[13px] font-semibold text-on-surface hover:bg-surface-container transition-colors"
                        >
                            <span class="material-symbols-outlined text-[16px]">corporate_fare</span>
                            Add Department
                        </button>
                        <button
                            @click="router.visit(route('departments.index'))"
                            class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 text-[13px] font-semibold text-on-surface hover:bg-surface-container transition-colors"
                        >
                            <span class="material-symbols-outlined text-[16px]">account_tree</span>
                            Departments
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- 5% gold hairline — the single institutional accent moment on this page -->
                <div class="h-px w-full" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.45),transparent)"></div>

                <!-- ── Workforce dashboard band ─────────────────────────────────────
                     Sovereign Precision: stat tiles in deep cobalt with monospace
                     numerals, twin donuts for status + departments, hairline
                     dividers. Numbers come from EmployeeService::stats() and are
                     RBAC-scoped server-side, so dept heads only see their slice.   -->
                <section class="space-y-4" aria-label="Workforce statistics">

                    <!-- Stat tiles -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <!-- TOTAL HEADCOUNT -->
                        <div class="relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card group">
                            <div class="pointer-events-none absolute -top-8 -right-8 h-28 w-28 rounded-full" style="background:radial-gradient(circle,rgba(26,35,126,0.10),transparent 70%)"></div>
                            <div class="relative">
                                <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em]" style="color:#1a237e">
                                    <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">groups</span>
                                    Total Headcount
                                </div>
                                <p class="mt-3 text-[2.1rem] font-black leading-none tracking-tight tabular-nums text-on-surface">{{ totalHeadcount }}</p>
                                <p class="mt-2 text-[11px] font-semibold text-on-surface-variant/70 leading-snug">
                                    <span class="tabular-nums" style="color:#1a237e">+{{ newHires30d }}</span> hired in last 30&nbsp;days
                                </p>
                            </div>
                        </div>

                        <!-- ACTIVE -->
                        <div class="relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                            <div class="pointer-events-none absolute -bottom-10 -right-10 h-28 w-28 rounded-full" style="background:radial-gradient(circle,rgba(18,217,227,0.10),transparent 70%)"></div>
                            <div class="relative">
                                <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em]" style="color:#0e7490">
                                    <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                                    Active On Roll
                                </div>
                                <p class="mt-3 text-[2.1rem] font-black leading-none tracking-tight tabular-nums text-on-surface">{{ statusCounts.active }}</p>
                                <div class="mt-3 flex items-center gap-2">
                                    <div class="h-1.5 flex-1 rounded-full bg-on-surface-variant/10 overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-700"
                                             :style="`width:${activeRate}%;background:linear-gradient(90deg,#1a237e,#12d9e3);`"></div>
                                    </div>
                                    <span class="text-[11px] font-bold tabular-nums" style="color:#1a237e">{{ activeRate }}%</span>
                                </div>
                            </div>
                        </div>

                        <!-- ON LEAVE -->
                        <div class="relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                            <div class="pointer-events-none absolute -top-10 -left-10 h-28 w-28 rounded-full" style="background:radial-gradient(circle,rgba(121,134,203,0.14),transparent 70%)"></div>
                            <div class="relative">
                                <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em]" style="color:#475569">
                                    <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">beach_access</span>
                                    Currently On Leave
                                </div>
                                <p class="mt-3 text-[2.1rem] font-black leading-none tracking-tight tabular-nums text-on-surface">{{ statusCounts.on_leave }}</p>
                                <p class="mt-2 text-[11px] font-semibold text-on-surface-variant/70 leading-snug">
                                    <span class="tabular-nums">{{ onLeavePct }}%</span> of workforce on temporary leave
                                </p>
                            </div>
                        </div>

                        <!-- DEPARTMENTS -->
                        <div class="relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                            <div class="pointer-events-none absolute -bottom-8 -right-8 h-28 w-28 rounded-full" style="background:radial-gradient(circle,rgba(217,18,227,0.08),transparent 70%)"></div>
                            <div class="relative">
                                <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em]" style="color:#7c3aed">
                                    <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">account_tree</span>
                                    Departments
                                </div>
                                <p class="mt-3 text-[2.1rem] font-black leading-none tracking-tight tabular-nums text-on-surface">{{ deptCount }}</p>
                                <p class="mt-2 text-[11px] font-semibold text-on-surface-variant/70 leading-snug">
                                    Institute-wide divisions on record
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Twin donut row: status mix + department distribution -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">

                        <!-- STATUS MIX -->
                        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-3.5 shadow-card">
                            <div class="flex items-center justify-between gap-3 mb-2.5">
                                <div>
                                    <div class="flex items-center gap-1.5 text-[9.5px] font-black uppercase tracking-[0.18em] text-secondary/80">
                                        <span class="material-symbols-outlined text-[12px]" style="color:#1a237e;font-variation-settings:'FILL' 1">workspaces</span>
                                        Status Mix
                                    </div>
                                    <h3 class="mt-0.5 text-[12px] font-bold text-on-surface leading-tight">Live workforce standing</h3>
                                </div>
                                <span class="text-[9.5px] font-bold uppercase tracking-[0.16em] text-on-surface-variant/60 tabular-nums">n = {{ totalHeadcount }}</span>
                            </div>

                            <div v-if="totalHeadcount === 0" class="py-5 text-center text-[12px] text-on-surface-variant/60">
                                No workforce data visible at this scope.
                            </div>
                            <div v-else class="flex items-center gap-3.5">
                                <MultiDonut
                                    :segments="statusSegments"
                                    :size="116"
                                    :stroke="11"
                                    center-label="On Roll"
                                />
                                <ul class="flex-1 space-y-1 min-w-0">
                                    <li v-for="seg in statusSegments" :key="seg.key" class="flex items-center gap-2">
                                        <span class="h-2 w-2 flex-shrink-0 rounded-sm" :style="`background:${seg.color}`"></span>
                                        <span class="flex-1 text-[11.5px] font-semibold text-on-surface truncate">{{ seg.label }}</span>
                                        <span class="text-[11.5px] font-black tabular-nums text-on-surface">{{ seg.value }}</span>
                                        <span class="text-[10px] font-bold tabular-nums text-on-surface-variant/60 w-8 text-right">{{ pctOf(seg.value) }}%</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- DEPARTMENT DISTRIBUTION -->
                        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-3.5 shadow-card">
                            <div class="flex items-center justify-between gap-3 mb-2.5">
                                <div>
                                    <div class="flex items-center gap-1.5 text-[9.5px] font-black uppercase tracking-[0.18em] text-secondary/80">
                                        <span class="material-symbols-outlined text-[12px]" style="color:#1a237e;font-variation-settings:'FILL' 1">corporate_fare</span>
                                        Department Distribution
                                    </div>
                                    <h3 class="mt-0.5 text-[12px] font-bold text-on-surface leading-tight">Where headcount sits</h3>
                                </div>
                                <span class="text-[9.5px] font-bold uppercase tracking-[0.16em] text-on-surface-variant/60 tabular-nums">Top {{ Math.min(6, (stats?.top_departments?.length ?? 0)) }}</span>
                            </div>

                            <div v-if="departmentSegments.length === 0" class="py-5 text-center text-[12px] text-on-surface-variant/60">
                                No department assignments yet.
                            </div>
                            <div v-else class="flex items-center gap-3.5">
                                <MultiDonut
                                    :segments="departmentSegments"
                                    :size="116"
                                    :stroke="11"
                                    :center-value="(stats?.top_departments?.length ?? 0) + (stats?.other_departments ? 1 : 0)"
                                    center-label="Divisions"
                                />
                                <ul class="flex-1 space-y-1 min-w-0">
                                    <li v-for="seg in departmentSegments" :key="seg.key" class="flex items-center gap-2">
                                        <span class="h-2 w-2 flex-shrink-0 rounded-sm" :style="`background:${seg.color}`"></span>
                                        <span class="flex-1 text-[11.5px] font-semibold text-on-surface truncate">{{ seg.label }}</span>
                                        <span class="text-[11.5px] font-black tabular-nums text-on-surface">{{ seg.value }}</span>
                                        <span class="text-[10px] font-bold tabular-nums text-on-surface-variant/60 w-8 text-right">{{ pctOf(seg.value) }}%</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

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

                <!-- ── Employee table ──────────────────────────────────────────────── -->
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

                    <div v-else class="min-h-[280px] overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
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

            <!-- ── Add Employee SlidePanel ──────────────────────────────────────── -->
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
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                    Staff ID
                                    <span class="ml-1 text-[10px] font-bold uppercase tracking-wider text-secondary">Auto</span>
                                </label>
                                <input
                                    v-model="form.staff_id"
                                    type="text"
                                    placeholder="Auto-generated (e.g. SID-000123)"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                    :class="{ 'border-red-400': form.errors.staff_id }"
                                />
                                <p class="mt-1 text-[11px] text-on-surface-variant/70">Leave blank to auto-assign. Must be unique if set.</p>
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
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                Employee No
                                <span class="ml-1 text-[10px] font-bold uppercase tracking-wider text-secondary">Auto</span>
                            </label>
                            <input
                                v-model="form.employee_no"
                                type="text"
                                placeholder="Auto-generated (e.g. CIHRM-0042)"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.employee_no }"
                            />
                            <p class="mt-1 text-[11px] text-on-surface-variant/70">Leave blank to auto-assign. Must be unique if set.</p>
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

                    <!-- Benefits block — gold-tinted (compensation context) -->
                    <div v-if="benefitPlans.length" class="rounded-2xl border border-brand-gold/20 bg-brand-gold/[0.04] p-4 space-y-3 relative overflow-hidden">
                        <div class="pointer-events-none absolute -top-6 -right-6 h-20 w-20 rounded-full" style="background:radial-gradient(circle,rgba(255,215,0,0.10),transparent 70%)"></div>
                        <div class="relative flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.18em] text-brand-gold-deep">
                                <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">verified_user</span>
                                Benefits enrolment
                            </div>
                            <span class="text-[10.5px] font-bold tabular-nums text-on-surface-variant/60">{{ form.benefit_plan_ids.length }} selected</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant/70 leading-snug">
                            Enrol the new hire in one or more active benefit plans. Each plan's monthly premium will be auto-computed from its contribution % and appear in their portal.
                        </p>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="plan in benefitPlans"
                                :key="plan.id"
                                class="group cursor-pointer rounded-xl border bg-surface-container-lowest p-3 transition-all duration-200 hover:border-brand-gold/50 hover:shadow-card-hover"
                                :class="form.benefit_plan_ids.includes(plan.id)
                                    ? 'border-brand-gold/60 bg-brand-gold/[0.06] shadow-card-hover'
                                    : 'border-outline-variant/60'"
                            >
                                <div class="flex items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        :value="plan.id"
                                        :checked="form.benefit_plan_ids.includes(plan.id)"
                                        @change="togglePlan(plan.id)"
                                        class="mt-0.5 h-4 w-4 rounded border-outline-variant text-brand-gold focus:ring-brand-gold/30"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[12.5px] font-bold text-on-surface truncate">{{ plan.name }}</p>
                                        <p class="mt-0.5 text-[10.5px] font-medium text-on-surface-variant/70 truncate">
                                            <span class="font-mono">{{ plan.code }}</span>
                                            <span v-if="plan.type"> · {{ plan.type }}</span>
                                            <span v-if="plan.provider"> · {{ plan.provider }}</span>
                                        </p>
                                        <p class="mt-1 text-[11px] font-semibold text-brand-gold-deep tabular-nums">
                                            {{ formatGhs(plan.monthly_cost) }}<span class="text-on-surface-variant/60 font-medium"> / month</span>
                                            <span v-if="plan.employee_contribution_percentage > 0" class="ml-1 text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/55">
                                                {{ plan.employee_contribution_percentage }}% employee
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <p v-if="form.errors['benefit_plan_ids.0']" class="text-[11px] text-red-500">{{ form.errors['benefit_plan_ids.0'] }}</p>
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
                            type="button"
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

            <!-- ── Add Department SlidePanel ────────────────────────────────────── -->
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
                            <span class="ml-1 font-normal text-on-surface-variant/60">(2—10 chars, uppercase)</span>
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
                            placeholder="Brief description of this department…"
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
                            type="button"
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

            <!-- ── Delete confirmation ───────────────────────────────────────────── -->
            <ConfirmDialog
                :open="showDeleteDialog"
                title="Delete Employee"
                message="Are you sure you want to delete this employee? This action cannot be undone and will remove all associated records."
                :danger="true"
                @confirm="doDelete"
                @cancel="showDeleteDialog = false"
            />

    </div>
</template>
