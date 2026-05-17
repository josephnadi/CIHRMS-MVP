<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    shifts:      Object,
    departments: Array,
    employees:   Array,
    assignments: Array,
});

const showCreate = ref(false);
const showAssign = ref(false);

const newShift = useForm({
    code: '', name: '',
    start_time: '08:00', end_time: '17:00',
    grace_period_minutes: 15,
    full_day_hours: 8.0, half_day_hours: 4.0,
    working_days: ['mon', 'tue', 'wed', 'thu', 'fri'],
    department_id: null,
    is_active: true,
});

function createShift() {
    newShift.post(route('attendance.shifts.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newShift.reset(); },
    });
}

const newAssignment = useForm({
    employee_id: '', shift_id: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: null,
});

function assignShift() {
    newAssignment.post(route('attendance.shifts.assign'), {
        preserveScroll: true,
        onSuccess: () => { showAssign.value = false; newAssignment.reset(); },
    });
}

// ── Day picker ──
const dayLabels = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };
const allDays   = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function toggleDay(d) {
    const i = newShift.working_days.indexOf(d);
    if (i >= 0) newShift.working_days.splice(i, 1);
    else newShift.working_days.push(d);
}

// ── Stats ──
const stats = computed(() => {
    const all = props.shifts?.data ?? [];
    return {
        totalShifts: all.length,
        activeShifts: all.filter(s => s.is_active).length,
        assignments: (props.assignments ?? []).length,
        peakHours: all.reduce((max, s) => {
            const span = timeToMinutes(s.end_time) - timeToMinutes(s.start_time);
            return span > max ? span : max;
        }, 0) / 60,
    };
});

// Helper: current local hour as minutes-since-midnight (for "now line")
const nowMinutes = computed(() => {
    const n = new Date();
    return n.getHours() * 60 + n.getMinutes();
});

// Today is a working day for shift S?
const todayKey = (() => {
    const k = ['sun','mon','tue','wed','thu','fri','sat'];
    return k[new Date().getDay()];
})();

const runningNow = (s) => {
    if (!s.is_active) return false;
    if (!(s.working_days ?? []).includes(todayKey)) return false;
    const now = nowMinutes.value;
    return now >= timeToMinutes(s.start_time) && now <= timeToMinutes(s.end_time);
};

// ── Time helpers ──
function timeToMinutes(t) {
    if (!t) return 0;
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
}

function shiftBarWidth(s) {
    const start = timeToMinutes(s.start_time);
    const end   = timeToMinutes(s.end_time);
    return Math.round(((end - start) / 1440) * 100);
}

function shiftBarLeft(s) {
    return Math.round((timeToMinutes(s.start_time) / 1440) * 100);
}

const nowPctOfDay = computed(() => Math.round((nowMinutes.value / 1440) * 100));

// ── Display helpers ──
const formatDate = (d) => {
    if (!d) return 'open-ended';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const daysRemaining = (to) => {
    if (!to) return null;
    return Math.floor((new Date(to) - Date.now()) / 86400000);
};

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

// Total employees covered right now
const coveredNow = computed(() => {
    const activeShifts = (props.shifts?.data ?? []).filter(s => runningNow(s));
    const shiftIds = new Set(activeShifts.map(s => s.id));
    return (props.assignments ?? []).filter(a =>
        shiftIds.has(a.shift?.id) &&
        (!a.effective_to || new Date(a.effective_to) >= new Date())
    ).length;
});
</script>

<template>
    <Head title="Shift Schedules" />
    <AuthenticatedLayout active-module="attendance">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Shift Schedules</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Define shift patterns and assign them to staff · {{ stats.activeShifts }} active definition<span v-if="stats.activeShifts !== 1">s</span>
                    </p>
                </div>
                <div class="flex items-center gap-2.5">
                    <div class="flex items-center gap-1.5 rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1.5 dark:bg-cyan-900/20 dark:border-cyan-800/40">
                        <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-cyan-700 dark:text-cyan-300">{{ coveredNow }} on shift</span>
                    </div>
                    <button @click="showAssign = true"
                            class="rounded-xl border border-outline-variant px-4 py-2.5 text-[13px] font-bold text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-2">
                        <span class="material-symbols-outlined text-[17px]">person_add</span>
                        Assign Shift
                    </button>
                    <button @click="showCreate = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Shift
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-8">

            <!-- Sub-nav -->
            <div class="flex flex-wrap items-center gap-1.5">
                <Link :href="route('attendance.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-on-surface-variant hover:border-secondary/40 hover:text-secondary transition-colors">
                    <span class="material-symbols-outlined text-[15px]">today</span> Daily
                </Link>
                <Link v-if="$page.props.auth.permissions?.includes('attendance.approve')"
                      :href="route('attendance.corrections.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-on-surface-variant hover:border-secondary/40 hover:text-secondary transition-colors">
                    <span class="material-symbols-outlined text-[15px]">fact_check</span> Corrections
                </Link>
                <span class="inline-flex items-center gap-1.5 rounded-xl border bg-secondary/8 border-secondary/25 px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-secondary">
                    <span class="material-symbols-outlined text-[15px]">schedule</span> Shifts
                </span>
            </div>

            <!-- ── Hero banner ── -->
            <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.18),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.06)"></div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(18,217,227,0.7)">Shift catalogue</p>
                        <h2 class="text-3xl font-black leading-tight">
                            <em class="not-italic" style="color:#12d9e3">{{ stats.activeShifts }}</em> active definition<span v-if="stats.activeShifts !== 1">s</span>
                            <span class="text-base font-bold opacity-50">covering {{ stats.assignments }} custom assignment<span v-if="stats.assignments !== 1">s</span></span>
                        </h2>
                        <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                            Default schedule: Mon–Fri · 08:00–17:00 · 15 min grace.
                            Override with a shift assignment per employee, department, or window.
                        </p>
                    </div>
                    <div class="flex items-center gap-8 flex-shrink-0">
                        <div v-for="kpi in [
                            { label: 'On shift now',  val: coveredNow,         color: '#12d9e3' },
                            { label: 'Total shifts',  val: stats.totalShifts,  color: '#7986cb' },
                            { label: 'Assignments',   val: stats.assignments,  color: '#ffd700' },
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
                    { label: 'Total shifts',     val: stats.totalShifts,    sub: 'In catalogue',     cls: 'icon-cyan',  icon: 'schedule' },
                    { label: 'Active shifts',    val: stats.activeShifts,   sub: 'Can be assigned',  cls: 'icon-brand', icon: 'verified' },
                    { label: 'Assignments',      val: stats.assignments,    sub: 'Off-default staff',cls: 'icon-magenta', icon: 'people' },
                    { label: 'On shift now',     val: coveredNow,           sub: 'Live coverage',    cls: 'icon-gold',  icon: 'pulse' },
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

            <!-- ── Today's coverage ribbon: 24h timeline with each shift band ── -->
            <div v-if="shifts?.data?.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 animate-reveal-up">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-[15px] font-black text-primary">Today's coverage</h3>
                        <p class="mt-0.5 text-[11px] text-on-surface-variant">24-hour timeline · shifts running today are highlighted in cyan; the gold marker shows the current time.</p>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/60">{{ todayKey }}</span>
                </div>

                <!-- Hour scale -->
                <div class="relative h-5 mb-1">
                    <div v-for="h in [0,3,6,9,12,15,18,21,24]" :key="h"
                         class="absolute top-0 -translate-x-1/2 text-[9px] font-mono text-on-surface-variant/50"
                         :style="`left:${(h/24)*100}%`">{{ String(h).padStart(2,'0') }}:00</div>
                </div>

                <div class="relative space-y-2">
                    <!-- "Now" marker -->
                    <div class="absolute top-0 bottom-0 w-px z-10" :style="`left:${nowPctOfDay}%;background:#ffd700;box-shadow:0 0 8px rgba(255,215,0,0.6)`"></div>
                    <div class="absolute -top-1.5 z-10 -translate-x-1/2"
                         :style="`left:${nowPctOfDay}%`">
                        <span class="h-2.5 w-2.5 inline-block rounded-full" style="background:#ffd700;box-shadow:0 0 8px rgba(255,215,0,0.6)"></span>
                    </div>

                    <!-- Per-shift bars -->
                    <div v-for="s in shifts.data" :key="s.id"
                         class="relative h-7 rounded-lg bg-surface-container-low/60 border border-outline-variant/40 overflow-hidden">
                        <div class="absolute top-0 bottom-0 rounded-md flex items-center pl-2 pr-2 text-[10px] font-black tracking-wide whitespace-nowrap overflow-hidden"
                             :style="`left:${shiftBarLeft(s)}%;width:${shiftBarWidth(s)}%;background:${runningNow(s) ? 'linear-gradient(90deg,#12d9e3,#1a237e)' : (s.is_active ? 'linear-gradient(90deg,rgba(26, 35, 126,0.55),rgba(57, 73, 171,0.45))' : 'rgba(100,116,139,0.35)')};color:white;`">
                            <span class="material-symbols-outlined text-[12px] mr-1 flex-shrink-0">{{ runningNow(s) ? 'play_circle' : 'schedule' }}</span>
                            <span class="truncate">{{ s.code }} · {{ s.name }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Shift definitions ── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                    <h3 class="text-[15px] font-black text-primary">Shift definitions</h3>
                    <span class="text-[10px] font-bold text-on-surface-variant/60">{{ stats.totalShifts }} total · {{ stats.activeShifts }} active</span>
                </div>

                <div v-if="!shifts?.data?.length" class="px-6 py-12">
                    <EmptyState title="No shifts defined yet"
                                description="Define a shift pattern with start/end times and working days, then assign it to staff."
                                class="py-4">
                        <template #action>
                            <button @click="showCreate = true"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Create first shift
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                    <div v-for="(s, i) in shifts.data" :key="s.id"
                         class="relative rounded-2xl border bg-surface-container-low/50 p-5 transition-all hover:-translate-y-0.5 hover:shadow-card animate-slide-up-fade"
                         :style="`animation-delay:${i*0.05}s;border-color:${runningNow(s) ? 'rgba(18,217,227,0.5)' : s.is_active ? 'rgb(var(--ct-outline-variant)/0.5)' : 'rgb(var(--ct-outline-variant)/0.3)'};${runningNow(s) ? 'box-shadow:0 0 0 1px rgba(18,217,227,0.18), 0 8px 24px rgba(13, 20, 82,0.06);' : ''}`">

                        <!-- Status badge -->
                        <div class="absolute top-3.5 right-3.5 flex items-center gap-1.5">
                            <span v-if="runningNow(s)" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9.5px] font-black uppercase tracking-wide" style="background:rgba(18,217,227,0.14);color:#0e8a93">
                                <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span> Live
                            </span>
                            <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9.5px] font-black uppercase tracking-wide',
                                            s.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500']">
                                <span class="h-1.5 w-1.5 rounded-full" :class="s.is_active ? 'bg-green-500' : 'bg-slate-400'"></span>
                                {{ s.is_active ? 'Active' : 'Archived' }}
                            </span>
                        </div>

                        <!-- Header -->
                        <p class="text-[10px] font-mono text-on-surface-variant/60 mb-0.5 uppercase tracking-wider">{{ s.code }}</p>
                        <p class="text-[15px] font-black text-primary leading-tight pr-24">{{ s.name }}</p>

                        <!-- Time window -->
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-[11px] font-mono text-on-surface-variant mb-1.5">
                                <span class="font-bold text-primary">{{ s.start_time }}</span>
                                <span>→</span>
                                <span class="font-bold text-primary">{{ s.end_time }}</span>
                            </div>
                            <div class="relative h-2 rounded-full bg-outline-variant/20 overflow-hidden">
                                <div class="absolute top-0 h-full rounded-full transition-all duration-700"
                                     :style="{
                                        left:  shiftBarLeft(s)  + '%',
                                        width: shiftBarWidth(s) + '%',
                                        background: runningNow(s)
                                            ? 'linear-gradient(90deg,#12d9e3,#1a237e)'
                                            : s.is_active
                                                ? 'linear-gradient(90deg,#0d1452,#1a237e)'
                                                : '#94a3b8',
                                     }"></div>
                                <!-- now marker -->
                                <div class="absolute top-0 bottom-0 w-px" :style="`left:${nowPctOfDay}%;background:#ffd700`"></div>
                            </div>
                        </div>

                        <!-- Day chips -->
                        <div class="mt-3 flex flex-wrap gap-1">
                            <span v-for="d in allDays" :key="d"
                                  :class="['rounded-md px-1.5 py-0.5 text-[10px] font-black',
                                            (s.working_days ?? []).includes(d)
                                                ? 'bg-secondary/12 text-secondary'
                                                : 'bg-outline-variant/15 text-on-surface-variant/30']">
                                {{ dayLabels[d] }}
                            </span>
                        </div>

                        <!-- Meta -->
                        <div class="mt-4 flex items-center justify-between text-[11px] text-on-surface-variant border-t border-outline-variant/40 pt-3">
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px] text-amber-500">timer</span>
                                {{ s.grace_period_minutes }}m grace
                            </span>
                            <span v-if="s.department?.name" class="flex items-center gap-1 truncate" :title="s.department.name">
                                <span class="material-symbols-outlined text-[13px] text-secondary">corporate_fare</span>
                                <span class="truncate">{{ s.department.name }}</span>
                            </span>
                            <span class="flex items-center gap-1 font-mono">
                                <span class="material-symbols-outlined text-[13px]" style="color:#d912e3">people</span>
                                {{ (assignments ?? []).filter(a => a.shift?.id === s.id).length }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Assignments ── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                    <div>
                        <h3 class="text-[15px] font-black text-primary">Active assignments</h3>
                        <p class="mt-0.5 text-[11px] text-on-surface-variant">Staff whose schedule overrides the org default.</p>
                    </div>
                    <button @click="showAssign = true"
                            class="rounded-xl border border-outline-variant px-3.5 py-2 text-[12px] font-bold text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">add</span>
                        Assign
                    </button>
                </div>

                <div v-if="!assignments?.length" class="px-6 py-12">
                    <EmptyState title="No custom assignments yet"
                                description="Every employee is currently on the default schedule. Override individuals by assigning them to a specific shift." />
                </div>

                <div v-else class="canvas-scroll overflow-x-auto max-h-[420px]">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                            <tr>
                                <th class="px-6 py-3">Employee</th>
                                <th class="px-6 py-3">Shift</th>
                                <th class="px-6 py-3">Effective from</th>
                                <th class="px-6 py-3">Effective to</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="(a, idx) in assignments" :key="a.id"
                                class="hover:bg-surface-container-low/40 transition-colors"
                                :style="`animation:slideUpFade 0.35s ease both;animation-delay:${idx*0.015}s`">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[10.5px] font-black text-secondary flex-shrink-0">
                                            {{ initials(a.employee?.user?.name ?? a.employee?.employee_no) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-[12.5px] font-bold text-primary truncate">{{ a.employee?.user?.name ?? '—' }}</p>
                                            <p class="text-[10px] text-on-surface-variant truncate">{{ a.employee?.employee_no }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3">
                                    <p class="text-[12.5px] font-bold text-primary">{{ a.shift?.name ?? '—' }}</p>
                                    <p class="font-mono text-[10.5px] text-on-surface-variant uppercase tracking-wider">{{ a.shift?.code }}</p>
                                </td>
                                <td class="px-6 py-3 text-[12px] font-semibold text-on-surface-variant">{{ formatDate(a.effective_from) }}</td>
                                <td class="px-6 py-3">
                                    <span v-if="a.effective_to" class="text-[12px] font-semibold text-on-surface-variant">{{ formatDate(a.effective_to) }}</span>
                                    <span v-else class="inline-flex items-center gap-1 rounded-full bg-green-50 text-green-700 border border-green-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>Open-ended
                                    </span>
                                </td>
                                <td class="px-6 py-3">
                                    <template v-if="a.effective_to">
                                        <span v-if="daysRemaining(a.effective_to) >= 0"
                                              :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider',
                                                       daysRemaining(a.effective_to) <= 7 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-blue-50 text-blue-700 border border-blue-200']">
                                            {{ daysRemaining(a.effective_to) }}d left
                                        </span>
                                        <span v-else class="inline-flex items-center rounded-full bg-slate-100 text-slate-500 border border-slate-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
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

        <!-- ── Create Shift slide-panel ── -->
        <SlidePanel :open="showCreate" @close="showCreate = false" title="Create new shift" size="lg">
            <form @submit.prevent="createShift" class="space-y-5 p-6">

                <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                    <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                        Shifts define the working window the attendance engine compares against. Late thresholds use the grace period; overtime accrues past full-day hours.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Shift code <span class="text-rose-500">*</span></label>
                        <input v-model="newShift.code" maxlength="20" required placeholder="e.g. MORNING"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low font-mono uppercase text-[13px] focus:border-secondary focus:ring-secondary/20"
                               :class="{ 'border-rose-400': newShift.errors.code }"/>
                        <p v-if="newShift.errors.code" class="mt-1 text-[11px] text-rose-500">{{ newShift.errors.code }}</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Shift name <span class="text-rose-500">*</span></label>
                        <input v-model="newShift.name" maxlength="80" required placeholder="e.g. Morning Standard"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                               :class="{ 'border-rose-400': newShift.errors.name }"/>
                        <p v-if="newShift.errors.name" class="mt-1 text-[11px] text-rose-500">{{ newShift.errors.name }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Start time <span class="text-rose-500">*</span></label>
                        <input v-model="newShift.start_time" type="time" required
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">End time <span class="text-rose-500">*</span></label>
                        <input v-model="newShift.end_time" type="time" required
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-2">Working days</label>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="d in allDays" :key="d" type="button" @click="toggleDay(d)"
                                :class="['rounded-xl px-3.5 py-1.5 text-[12px] font-black border transition-all',
                                          newShift.working_days.includes(d)
                                            ? 'border-secondary bg-secondary text-white shadow-glow-sm'
                                            : 'border-outline-variant bg-surface-container-low text-on-surface-variant hover:border-secondary/40']">
                            {{ dayLabels[d] }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Grace (min)</label>
                        <input v-model.number="newShift.grace_period_minutes" type="number" min="0" max="120"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Full day (h)</label>
                        <input v-model.number="newShift.full_day_hours" type="number" step="0.25" min="1" max="24"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Half day (h)</label>
                        <input v-model.number="newShift.half_day_hours" type="number" step="0.25" min="0.5" max="12"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Department (optional)</label>
                    <select v-model="newShift.department_id"
                            class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20">
                        <option :value="null">— Any department —</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </div>

                <label class="flex items-center gap-3 cursor-pointer rounded-xl border border-outline-variant/60 bg-surface-container-low px-4 py-3">
                    <input v-model="newShift.is_active" aria-label="Active shift" type="checkbox" class="h-4 w-4 rounded accent-secondary"/>
                    <span class="text-[13px] font-bold text-on-surface">Active shift</span>
                    <span class="text-[11.5px] text-on-surface-variant/60">Inactive shifts are archived and won't accept new assignments.</span>
                </label>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showCreate = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="createShift" :disabled="newShift.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span v-if="newShift.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span class="material-symbols-outlined text-[16px]" v-else>check_circle</span>
                        Create shift
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── Assign slide-panel ── -->
        <SlidePanel :open="showAssign" @close="showAssign = false" title="Assign shift to employee" size="md">
            <form @submit.prevent="assignShift" class="space-y-5 p-6">

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Employee <span class="text-rose-500">*</span></label>
                    <select v-model="newAssignment.employee_id" required
                            class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                            :class="{ 'border-rose-400': newAssignment.errors.employee_id }">
                        <option value="" disabled>Select employee…</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.employee_no }} — {{ e.user?.name ?? e.position }}
                        </option>
                    </select>
                    <p v-if="newAssignment.errors.employee_id" class="mt-1 text-[11px] text-rose-500">{{ newAssignment.errors.employee_id }}</p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Shift <span class="text-rose-500">*</span></label>
                    <select v-model="newAssignment.shift_id" required
                            class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                            :class="{ 'border-rose-400': newAssignment.errors.shift_id }">
                        <option value="" disabled>Select shift…</option>
                        <option v-for="s in shifts?.data ?? []" :key="s.id" :value="s.id">
                            {{ s.code }} — {{ s.name }} ({{ s.start_time }} – {{ s.end_time }})
                        </option>
                    </select>
                    <p v-if="newAssignment.errors.shift_id" class="mt-1 text-[11px] text-rose-500">{{ newAssignment.errors.shift_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Effective from <span class="text-rose-500">*</span></label>
                        <input v-model="newAssignment.effective_from" type="date" required
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Effective to <span class="ml-1 font-normal text-on-surface-variant/50">(opt.)</span></label>
                        <input v-model="newAssignment.effective_to" type="date"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </div>

                <div class="rounded-xl border border-cyan-200/60 bg-cyan-50/40 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-cyan-600 text-[18px] mt-0.5">tips_and_updates</span>
                    <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                        Leave <span class="font-bold">Effective to</span> blank for an open-ended assignment — it stays active until you replace it.
                    </p>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showAssign = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="assignShift" :disabled="newAssignment.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span v-if="newAssignment.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span class="material-symbols-outlined text-[16px]" v-else>person_add</span>
                        Save assignment
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
