<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
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

// Auto-open create panel when arriving via Quick Action (?new=1)
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('new') === '1') {
        showAddPanel.value = true;
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
});

const submitEmployee = () => {
    form.post(route('employees.store'), {
        onSuccess: () => {
            form.reset();
            showAddPanel.value = false;
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
    deptForm.post(route('storeDepartment'), {
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

// ── Stats derived from meta (backend should provide these) ───────────────────
const stats = computed(() => ({
    total:       props.employees?.meta?.total             ?? 0,
    active:      props.employees?.meta?.active_count      ?? 0,
    onLeave:     props.employees?.meta?.on_leave_count    ?? 0,
    departments: props.employees?.meta?.departments_count ?? props.departments?.length ?? 0,
}));

// ── Initials + gradient ───────────────────────────────────────────────────────
const gradients = [
    'linear-gradient(135deg,#0051d5,#316bf3)',
    'linear-gradient(135deg,#059669,#34d399)',
    'linear-gradient(135deg,#d97706,#fbbf24)',
    'linear-gradient(135deg,#7c3aed,#a78bfa)',
    'linear-gradient(135deg,#dc2626,#f87171)',
    'linear-gradient(135deg,#0891b2,#22d3ee)',
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
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- ── Header slot ─────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Employees</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Manage workforce records, positions and employment details.
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ stats.total }} total
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2.5">
                    <button
                        @click="showDeptPanel = true"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">corporate_fare</span>
                            Add Department
                        </span>
                    </button>
                    <button
                        @click="showAddPanel = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        Add Employee
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- ── Stats row ──────────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                    :value="stats.total"
                    label="Total Employees"
                    icon="people"
                    color="#0051d5"
                    :href="route('employees.index')"
                />
                <StatCard
                    :value="stats.active"
                    label="Active"
                    icon="check_circle"
                    color="#059669"
                />
                <StatCard
                    :value="stats.onLeave"
                    label="On Leave"
                    icon="beach_access"
                    color="#d97706"
                />
                <StatCard
                    :value="stats.departments"
                    label="Departments"
                    icon="corporate_fare"
                    color="#7c3aed"
                    :href="route('departments.index')"
                />
            </div>

            <!-- ── Filters bar ─────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] max-w-xs">
                    <SearchInput
                        v-model="localFilters.search"
                        placeholder="Search name, ID, position…"
                    />
                </div>

                <select
                    v-model="localFilters.department_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Departments</option>
                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                        {{ dept.name }}
                    </option>
                </select>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="on_leave">On Leave</option>
                    <option value="inactive">Inactive</option>
                    <option value="terminated">Terminated</option>
                </select>

                <button
                    v-if="localFilters.search || localFilters.department_id || localFilters.status"
                    @click="() => { localFilters.search = ''; localFilters.department_id = ''; localFilters.status = ''; applyFilters(); }"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
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
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Add Employee
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="max-h-[calc(100vh-440px)] min-h-[280px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Employee</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Staff ID</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Department</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Position</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Hire Date</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr
                                v-for="emp in employees.data"
                                :key="emp.id"
                                class="border-b border-outline-variant/50 hover:bg-surface-container/40 cursor-pointer transition-colors"
                                @click="router.get(route('employees.show', emp.id))"
                            >
                                <!-- Avatar + name -->
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-8 w-8 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white overflow-hidden"
                                            :style="emp.avatar_url ? '' : `background:${avatarGradient(emp.id)}`"
                                        >
                                            <img v-if="emp.avatar_url" :src="emp.avatar_url" :alt="emp.user?.name" class="h-full w-full object-cover" />
                                            <span v-else>{{ initials(emp.user?.name) }}</span>
                                        </div>
                                        <div>
                                            <p class="text-[13px] font-semibold text-on-surface leading-tight">{{ emp.user?.name ?? '—' }}</p>
                                            <p class="text-[11px] text-on-surface-variant/60 leading-tight">
                                                {{ emp.user?.email ?? '' }}
                                                <span v-if="emp.manager?.name" class="ml-1 text-on-surface-variant/45">· reports to {{ emp.manager.name }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span class="font-mono text-[12px] text-on-surface-variant">{{ emp.employee_no }}</span>
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    {{ emp.department?.name ?? '—' }}
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    {{ emp.position ?? '—' }}
                                </td>

                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    {{ formatDate(emp.hire_date) }}
                                </td>

                                <td class="px-4 py-3.5">
                                    <StatusBadge :status="emp.status?.value ?? emp.status" type="employee" />
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3.5" @click.stop>
                                    <div class="flex items-center gap-1">
                                        <Link
                                            :href="route('employees.show', emp.id)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant hover:bg-secondary/10 hover:text-secondary transition-colors"
                                            title="View"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">visibility</span>
                                        </Link>
                                        <button
                                            @click="confirmDelete(emp.id, $event)"
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

                <!-- Pagination -->
                <div v-if="employees?.links?.length > 3" class="border-t border-outline-variant/50 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[12px] text-on-surface-variant">
                            Showing
                            <span class="font-semibold text-on-surface">{{ employees.meta?.from }}</span>
                            –
                            <span class="font-semibold text-on-surface">{{ employees.meta?.to }}</span>
                            of
                            <span class="font-semibold text-on-surface">{{ employees.meta?.total }}</span>
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

                <!-- ── Login account block ────────────────────────────────────── -->
                <div class="rounded-2xl border border-secondary/15 bg-secondary/5 p-4 space-y-4">
                    <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.18em] text-secondary">
                        <span class="material-symbols-outlined text-[15px]">account_circle</span>
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
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
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
                        <span class="ml-1 font-normal text-on-surface-variant/60">(2–10 chars, uppercase)</span>
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
                        @click="submitDepartment"
                        :disabled="deptForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
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

    </AuthenticatedLayout>
</template>
