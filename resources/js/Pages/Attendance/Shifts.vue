<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    shifts:      Object,   // paginated: { data: [], links: [], meta: {} }
    departments: Array,    // [{ id, name }]
    employees:   Array,    // [{ id, employee_no, position, user: { name } }]
    assignments: Array,    // [{ id, employee, shift, effective_from, effective_to }]
});

// ── Panels ──────────────────────────────────────────────────────────────────
const showCreate = ref(false);
const showAssign = ref(false);

// ── Create shift form ────────────────────────────────────────────────────────
const newShift = useForm({
    code:                 '',
    name:                 '',
    start_time:           '08:00',
    end_time:             '17:00',
    grace_period_minutes: 15,
    full_day_hours:       8.0,
    half_day_hours:       4.0,
    working_days:         ['mon', 'tue', 'wed', 'thu', 'fri'],
    department_id:        null,
    is_active:            true,
});

function createShift() {
    newShift.post(route('attendance.shifts.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newShift.reset(); },
    });
}

// ── Assign shift form ────────────────────────────────────────────────────────
const newAssignment = useForm({
    employee_id:    '',
    shift_id:       '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to:   null,
});

function assignShift() {
    newAssignment.post(route('attendance.shifts.assign'), {
        preserveScroll: true,
        onSuccess: () => { showAssign.value = false; newAssignment.reset(); },
    });
}

// ── Day picker ────────────────────────────────────────────────────────────────
const dayLabels = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };
const allDays   = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function toggleDay(d) {
    const i = newShift.working_days.indexOf(d);
    if (i >= 0) newShift.working_days.splice(i, 1);
    else newShift.working_days.push(d);
}

// ── Stats ─────────────────────────────────────────────────────────────────────
const stats = computed(() => {
    const all = props.shifts?.data ?? [];
    return {
        totalShifts:   all.length,
        activeShifts:  all.filter(s => s.is_active).length,
        customAssigned: (props.assignments ?? []).length,
    };
});

// ── Time bar visual ──────────────────────────────────────────────────────────
function timeToMinutes(t) {
    if (!t) return 0;
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
}

function shiftBarWidth(s) {
    const start  = timeToMinutes(s.start_time);
    const end    = timeToMinutes(s.end_time);
    const span   = end - start;
    const dayMin = 24 * 60;
    return Math.round((span / dayMin) * 100);
}

function shiftBarLeft(s) {
    const start  = timeToMinutes(s.start_time);
    const dayMin = 24 * 60;
    return Math.round((start / dayMin) * 100);
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const formatDate = (d) => {
    if (!d) return 'open-ended';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const daysRemaining = (to) => {
    if (!to) return null;
    const diff = Math.floor((new Date(to) - Date.now()) / 86400000);
    return diff;
};

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
</script>

<template>
    <Head title="Shift Schedules" />
    <AuthenticatedLayout active-module="attendance">

        <!-- ── Header ──────────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Shift Schedules</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Define shift patterns and assign them to employees.
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ stats.totalShifts }} shifts
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2.5">
                    <button
                        @click="showAssign = true"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-bold text-primary hover:bg-surface-container-low transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[17px]">person_add</span>
                        Assign Shift
                    </button>
                    <button
                        @click="showCreate = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background: linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Shift
                    </button>
                </div>
            </div>
        </template>

        <div class="p-6 space-y-6 animate-reveal-up">

            <!-- ── Stats row ─────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    :value="stats.totalShifts"
                    label="Total Shifts"
                    icon="schedule"
                    color="#205295"
                />
                <StatCard
                    :value="stats.activeShifts"
                    label="Active Shifts"
                    icon="check_circle"
                    color="#059669"
                />
                <StatCard
                    :value="stats.customAssigned"
                    label="Custom Assignments"
                    icon="people"
                    color="#7c5cff"
                />
            </div>

            <!-- ── Shift cards grid ──────────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Shift Definitions</p>
                    <span class="text-[11px] font-bold text-on-surface-variant/50">
                        Default: Mon–Fri · 08:00–17:00 · 15 min grace (applies when no assignment active)
                    </span>
                </div>

                <div v-if="!shifts?.data?.length" class="p-12">
                    <EmptyState title="No shifts defined yet." class="py-4">
                        <template #action>
                            <button
                                @click="showCreate = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background: linear-gradient(135deg,#205295,#2c74b3)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Create First Shift
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="(s, i) in shifts.data"
                        :key="s.id"
                        class="relative rounded-2xl border bg-surface-container-low p-4 transition-all hover:-translate-y-0.5 hover:shadow-card animate-slide-up-fade"
                        :style="`animation-delay: ${i * 0.06}s; border-color: ${s.is_active ? 'rgba(5,150,105,0.2)' : 'rgba(0,0,0,0.08)'}`"
                    >
                        <!-- Active/archived badge -->
                        <div class="absolute top-3.5 right-3.5">
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wide',
                                    s.is_active
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-100 text-slate-500'
                                ]"
                            >
                                <span class="h-1.5 w-1.5 rounded-full" :class="s.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                {{ s.is_active ? 'Active' : 'Archived' }}
                            </span>
                        </div>

                        <!-- Shift name + code -->
                        <p class="text-[11px] font-mono text-on-surface-variant/60 mb-0.5">{{ s.code }}</p>
                        <p class="text-[15px] font-black text-on-surface leading-tight pr-16">{{ s.name }}</p>

                        <!-- Time window visual -->
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-[11px] font-mono text-on-surface-variant mb-1">
                                <span>{{ s.start_time }}</span>
                                <span>{{ s.end_time }}</span>
                            </div>
                            <div class="relative h-2 rounded-full bg-outline-variant/30 overflow-hidden">
                                <div
                                    class="absolute top-0 h-full rounded-full"
                                    :style="{
                                        left:  shiftBarLeft(s)  + '%',
                                        width: shiftBarWidth(s) + '%',
                                        background: s.is_active
                                            ? 'linear-gradient(90deg,#205295,#2c74b3)'
                                            : '#94a3b8'
                                    }"
                                ></div>
                            </div>
                        </div>

                        <!-- Day-of-week chips -->
                        <div class="mt-3 flex flex-wrap gap-1">
                            <span
                                v-for="d in allDays"
                                :key="d"
                                :class="[
                                    'rounded-md px-1.5 py-0.5 text-[10px] font-bold',
                                    (s.working_days ?? []).includes(d)
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-outline-variant/20 text-on-surface-variant/30'
                                ]"
                            >
                                {{ dayLabels[d] }}
                            </span>
                        </div>

                        <!-- Meta row -->
                        <div class="mt-3 flex items-center justify-between text-[11px] text-on-surface-variant">
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">timer</span>
                                {{ s.grace_period_minutes }}m grace
                            </span>
                            <span v-if="s.department?.name" class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">corporate_fare</span>
                                {{ s.department.name }}
                            </span>
                            <span class="flex items-center gap-1 font-mono">
                                <span class="material-symbols-outlined text-[14px]">people</span>
                                {{ (assignments ?? []).filter(a => a.shift?.id === s.id).length }} assigned
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Assignment table ──────────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Active Assignments</p>
                    <button
                        @click="showAssign = true"
                        class="rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] font-bold text-primary hover:bg-surface-container-low transition-colors flex items-center gap-1.5"
                    >
                        <span class="material-symbols-outlined text-[15px]">add</span>
                        Assign
                    </button>
                </div>

                <div v-if="!assignments?.length" class="p-12">
                    <EmptyState title="No custom assignments. All employees are on the default schedule." class="py-4">
                        <template #action>
                            <button
                                @click="showAssign = true"
                                class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-bold text-primary hover:bg-surface-container-low transition-colors flex items-center gap-2"
                            >
                                <span class="material-symbols-outlined text-[17px]">person_add</span>
                                Create Assignment
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="overflow-auto max-h-[400px]">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Employee</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Shift</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Effective From</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Effective To</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr
                                v-for="a in assignments"
                                :key="a.id"
                                class="hover:bg-surface-container/40 transition-colors"
                            >
                                <!-- Employee -->
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-8 w-8 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                            :style="`background: ${avatarGradient(a.employee?.id ?? 0)}`"
                                        >
                                            {{ initials(a.employee?.user?.name ?? a.employee?.employee_no) }}
                                        </div>
                                        <div>
                                            <p class="text-[13px] font-semibold text-on-surface leading-tight">
                                                {{ a.employee?.user?.name ?? '—' }}
                                            </p>
                                            <p class="font-mono text-[11px] text-on-surface-variant/60">{{ a.employee?.employee_no }}</p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Shift -->
                                <td class="px-4 py-3.5">
                                    <p class="text-[13px] font-semibold text-on-surface">{{ a.shift?.name ?? '—' }}</p>
                                    <p class="font-mono text-[11px] text-on-surface-variant/60">{{ a.shift?.code }}</p>
                                </td>

                                <!-- Effective From -->
                                <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                    {{ formatDate(a.effective_from) }}
                                </td>

                                <!-- Effective To -->
                                <td class="px-4 py-3.5">
                                    <span v-if="a.effective_to" class="text-[13px] text-on-surface-variant">
                                        {{ formatDate(a.effective_to) }}
                                    </span>
                                    <span v-else class="text-[12px] font-bold text-emerald-700 bg-emerald-50 rounded-full px-2 py-0.5">Open-ended</span>
                                </td>

                                <!-- Days remaining badge -->
                                <td class="px-4 py-3.5">
                                    <template v-if="a.effective_to">
                                        <span
                                            v-if="daysRemaining(a.effective_to) >= 0"
                                            :class="[
                                                'inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold',
                                                daysRemaining(a.effective_to) <= 7
                                                    ? 'bg-amber-100 text-amber-700'
                                                    : 'bg-surface-container text-on-surface-variant'
                                            ]"
                                        >
                                            {{ daysRemaining(a.effective_to) }}d left
                                        </span>
                                        <span v-else class="inline-flex items-center rounded-full bg-slate-100 text-slate-500 px-2.5 py-0.5 text-[11px] font-bold">
                                            Expired
                                        </span>
                                    </template>
                                    <span v-else class="text-[11px] text-on-surface-variant/40">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Create Shift slide-panel ─────────────────────────────────────── -->
        <SlidePanel :open="showCreate" @close="showCreate = false" title="Create New Shift" size="lg">
            <form @submit.prevent="createShift" class="space-y-5 p-6">

                <!-- Code + Name -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                            Shift Code <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="newShift.code"
                            maxlength="20"
                            required
                            placeholder="e.g. MORNING"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] font-mono uppercase text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': newShift.errors.code }"
                        />
                        <p v-if="newShift.errors.code" class="mt-1 text-[11px] text-red-500">{{ newShift.errors.code }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                            Shift Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="newShift.name"
                            maxlength="80"
                            required
                            placeholder="e.g. Morning Standard"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': newShift.errors.name }"
                        />
                        <p v-if="newShift.errors.name" class="mt-1 text-[11px] text-red-500">{{ newShift.errors.name }}</p>
                    </div>
                </div>

                <!-- Time window -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Start Time <span class="text-red-500">*</span></label>
                        <input
                            v-model="newShift.start_time"
                            type="time"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">End Time <span class="text-red-500">*</span></label>
                        <input
                            v-model="newShift.end_time"
                            type="time"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <!-- Working days pill toggle -->
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-2 block">Working Days</label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="d in allDays"
                            :key="d"
                            type="button"
                            @click="toggleDay(d)"
                            :class="[
                                'rounded-xl px-3.5 py-1.5 text-[12px] font-bold border transition-all',
                                newShift.working_days.includes(d)
                                    ? 'border-primary bg-primary text-white shadow-glow-sm'
                                    : 'border-outline-variant bg-surface-container-low text-on-surface-variant hover:border-primary/40 hover:bg-primary/5'
                            ]"
                        >
                            {{ dayLabels[d] }}
                        </button>
                    </div>
                </div>

                <!-- Grace + hours -->
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Grace (min)</label>
                        <input
                            v-model.number="newShift.grace_period_minutes"
                            type="number"
                            min="0"
                            max="120"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Full day (h)</label>
                        <input
                            v-model.number="newShift.full_day_hours"
                            type="number"
                            step="0.25"
                            min="1"
                            max="24"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Half day (h)</label>
                        <input
                            v-model.number="newShift.half_day_hours"
                            type="number"
                            step="0.25"
                            min="0.5"
                            max="12"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <!-- Department -->
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Department (optional)</label>
                    <select
                        v-model="newShift.department_id"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option :value="null">— Any department —</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </div>

                <!-- Active toggle -->
                <label class="flex items-center gap-3 cursor-pointer rounded-xl border border-outline-variant/60 bg-surface-container-low px-4 py-3">
                    <input
                        v-model="newShift.is_active"
                        type="checkbox"
                        class="h-4 w-4 rounded accent-primary"
                    />
                    <span class="text-[13px] font-semibold text-on-surface">Active shift</span>
                    <span class="text-[12px] text-on-surface-variant/60">Inactive shifts are archived and won't be assigned.</span>
                </label>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showCreate = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="createShift"
                        :disabled="newShift.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background: linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span v-if="newShift.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Create Shift</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── Assign Shift slide-panel ──────────────────────────────────────── -->
        <SlidePanel :open="showAssign" @close="showAssign = false" title="Assign Shift to Employee" size="md">
            <form @submit.prevent="assignShift" class="space-y-5 p-6">

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Employee <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="newAssignment.employee_id"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': newAssignment.errors.employee_id }"
                    >
                        <option value="" disabled>Select employee…</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.employee_no }} — {{ e.user?.name ?? e.position }}
                        </option>
                    </select>
                    <p v-if="newAssignment.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ newAssignment.errors.employee_id }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Shift <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="newAssignment.shift_id"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': newAssignment.errors.shift_id }"
                    >
                        <option value="" disabled>Select shift…</option>
                        <option v-for="s in shifts?.data ?? []" :key="s.id" :value="s.id">
                            {{ s.code }} — {{ s.name }} ({{ s.start_time }} – {{ s.end_time }})
                        </option>
                    </select>
                    <p v-if="newAssignment.errors.shift_id" class="mt-1 text-[11px] text-red-500">{{ newAssignment.errors.shift_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Effective From <span class="text-red-500">*</span></label>
                        <input
                            v-model="newAssignment.effective_from"
                            type="date"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                            Effective To
                            <span class="ml-1 font-normal text-on-surface-variant/60">(optional)</span>
                        </label>
                        <input
                            v-model="newAssignment.effective_to"
                            type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <div class="rounded-xl border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-[12px] text-on-surface-variant/70">
                    Leave <span class="font-bold">Effective To</span> blank for an open-ended assignment that remains active until manually changed.
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showAssign = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="assignShift"
                        :disabled="newAssignment.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background: linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span v-if="newAssignment.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Save Assignment</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
