<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LiveBars from '@/Components/charts/LiveBars.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    summaries:          Object,
    stats:              Object,
    dailyTrend:         { type: Array,  default: () => [] },
    statusDistribution: { type: Object, default: () => ({}) },
    month:              String,
    filters:            Object,
    activeModule:       String,
});

const monthValue  = ref(props.month);
const statusValue = ref(props.filters?.status ?? '');
const searchValue = ref(props.filters?.q ?? '');

const applyFilters = () => router.get(route('attendance.index'), {
    month: monthValue.value,
    status: statusValue.value || undefined,
    q:      searchValue.value || undefined,
}, { preserveState: true, replace: true });

const showManual = ref(false);
const manual = useForm({
    employee_id: '',
    event_at:    new Date().toISOString().slice(0, 16),
    direction:   'in',
    reason:      '',
});
const submitManual = () => manual.post(route('attendance.manual'), {
    preserveScroll: true,
    onSuccess: () => { showManual.value = false; manual.reset('reason'); },
});

// ── Visual helpers ──────────────────────────────────────────────
const monthLabel = computed(() => {
    if (!props.month) return '';
    const [y, m] = props.month.split('-');
    return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
});

// LiveBars data: daily total presence (present + late) — gives a single
// "showed up" series with the absent days standing out as low bars.
const trendBars = computed(() =>
    (props.dailyTrend ?? []).map(d => ({
        label: d.label,
        value: d.present + d.late,
    }))
);

const totalDays = computed(() => Object.values(props.statusDistribution ?? {}).reduce((s, v) => s + Number(v), 0));
const presentPct = computed(() => {
    const t = totalDays.value;
    if (!t) return 0;
    return Math.round(((props.statusDistribution?.present ?? 0) + (props.statusDistribution?.late ?? 0)) / t * 100);
});

// Composition donut — using stroke-dasharray on overlapping circles.
const donutSegments = computed(() => {
    const t = totalDays.value || 1;
    const seg = (key) => ((props.statusDistribution?.[key] ?? 0) / t) * 100;
    return {
        present:  seg('present'),
        late:     seg('late'),
        absent:   seg('absent'),
        on_leave: seg('on_leave'),
    };
});

const statusMeta = (s) => ({
    present:  { label: 'Present',  bg: 'bg-green-50 text-green-700 border-green-200',  dot: '#059669' },
    late:     { label: 'Late',     bg: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#d97706' },
    absent:   { label: 'Absent',   bg: 'bg-red-50 text-red-700 border-red-200',        dot: '#dc2626' },
    half_day: { label: 'Half day', bg: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#d97706' },
    on_leave: { label: 'On leave', bg: 'bg-cyan-50 text-cyan-700 border-cyan-200',     dot: '#12d9e3' },
    holiday:  { label: 'Holiday',  bg: 'bg-blue-50 text-blue-700 border-blue-200',     dot: '#1a237e' },
    weekend:  { label: 'Weekend',  bg: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b' },
}[s] ?? { label: s, bg: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b' });

const fmtHours = (h) => Number(h ?? 0).toFixed(2);
const initials = (name) => (name ?? 'NA').split(' ').slice(0, 2).map(s => s[0]?.toUpperCase() ?? '').join('');
</script>

<template>
    <Head title="Attendance — Org-wide" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Attendance · Org-wide</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Real-time presence telemetry · {{ monthLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5 rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1.5 dark:bg-cyan-900/20 dark:border-cyan-800/40">
                        <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-cyan-700 dark:text-cyan-300">Biometric live</span>
                    </div>
                    <button @click="showManual = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        Manual entry
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-8">

            <!-- Sub-nav: Daily / Corrections / Shifts -->
            <div class="flex flex-wrap items-center gap-1.5">
                <Link :href="route('attendance.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border bg-secondary/8 border-secondary/25 px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-secondary">
                    <span class="material-symbols-outlined text-[15px]">today</span> Daily
                </Link>
                <Link v-if="$page.props.auth.permissions?.includes('attendance.approve')"
                      :href="route('attendance.corrections.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-on-surface-variant hover:border-secondary/40 hover:text-secondary transition-colors">
                    <span class="material-symbols-outlined text-[15px]">fact_check</span> Corrections
                </Link>
                <Link v-if="$page.props.auth.permissions?.includes('attendance.shift_manage')"
                      :href="route('attendance.shifts.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3.5 py-2 text-[11.5px] font-black uppercase tracking-wide text-on-surface-variant hover:border-secondary/40 hover:text-secondary transition-colors">
                    <span class="material-symbols-outlined text-[15px]">schedule</span> Shifts
                </Link>
            </div>

            <!-- ── Hero banner ─────────────────────────────────────────── -->
            <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                <!-- Atmospheric blobs -->
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.18),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.06)"></div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(18,217,227,0.7)">Telemetry · {{ monthLabel }}</p>
                        <h2 class="text-3xl font-black leading-tight">
                            Today's pulse · <em class="not-italic" style="color:#12d9e3">{{ stats.present_today + stats.late_today }}</em>
                            <span class="text-base font-bold opacity-50">of {{ stats.workforce_size ?? '—' }} staff clocked in</span>
                        </h2>
                        <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                            {{ presentPct }}% attendance rate for {{ monthLabel }} ·
                            <span style="color:#ffd700">{{ fmtHours(stats.month_avg_hours) }}h</span> daily average.
                        </p>
                    </div>
                    <!-- Hero inline KPIs -->
                    <div class="flex items-center gap-8 flex-shrink-0">
                        <div v-for="kpi in [
                            { label: 'Present',  val: stats.present_today,  color: '#12d9e3' },
                            { label: 'Late',     val: stats.late_today,     color: '#ffd700' },
                            { label: 'Absent',   val: stats.absent_today,   color: '#dc2626' },
                            { label: 'On leave', val: stats.on_leave_today ?? 0, color: '#7986cb' },
                        ]" :key="kpi.label" class="text-center">
                            <p class="text-3xl font-black leading-none" :style="`color:${kpi.color}`">{{ kpi.val ?? 0 }}</p>
                            <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Stat tiles ──────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="(card, i) in [
                    { label: 'Present today', val: stats.present_today, sub: 'Clocked in',           cls: 'icon-cyan',    accent: '#12d9e3', icon: 'how_to_reg' },
                    { label: 'Late today',    val: stats.late_today,    sub: 'After grace',          cls: 'icon-gold',    accent: '#ffd700', icon: 'schedule' },
                    { label: 'Absent today',  val: stats.absent_today,  sub: 'No record',            cls: 'icon-danger',  accent: '#dc2626', icon: 'block' },
                    { label: 'Avg hrs / day', val: fmtHours(stats.month_avg_hours), sub: monthLabel, cls: 'icon-magenta', accent: '#d912e3', icon: 'timer' },
                ]" :key="card.label"
                     class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                     :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                    <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                        <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.accent}`"></span>
                    </div>
                    <div class="icon-tile" :class="card.cls">
                        <span class="material-symbols-outlined">{{ card.icon }}</span>
                    </div>
                    <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                    <p class="mt-1 text-[28px] font-black tabular-nums text-primary leading-none">{{ card.val ?? 0 }}</p>
                    <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── Visual band: trend bars + composition donut ─────────── -->
            <div class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                <!-- Trend (spans 2/3) -->
                <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-[15px] font-black text-primary">Daily attendance · {{ monthLabel }}</h3>
                            <p class="mt-0.5 text-[11px] text-on-surface-variant">Present + late counts per calendar day. Peak day highlighted in gold.</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded bg-secondary"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Showed up</span></div>
                            <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded" style="background:#ffd700"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Peak</span></div>
                            <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded" style="background:#12d9e3"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Live</span></div>
                        </div>
                    </div>

                    <div v-if="trendBars.length" class="mt-2">
                        <LiveBars :data="trendBars"
                                  :height="200"
                                  color="#1a237e"
                                  accent-color="#ffd700"
                                  second-color="#12d9e3"
                                  :show-median="true"
                                  :rounded="5"
                                  :format-value="v => `${v} staff`" />
                    </div>
                    <div v-else class="py-16 text-center text-[12px] font-medium text-on-surface-variant italic">No records yet for this period.</div>
                </div>

                <!-- Composition donut -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-[15px] font-black text-primary">Composition</h3>
                        <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">{{ monthLabel }}</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant mb-4">{{ totalDays }} attendance rows recorded.</p>

                    <!-- Donut SVG -->
                    <div class="flex items-center justify-center relative my-2">
                        <svg viewBox="0 0 100 100" width="180" height="180" class="-rotate-90">
                            <!-- Track -->
                            <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(var(--ct-surface-low))" stroke-width="10"/>
                            <!-- Segments computed with cumulative offset; circumference of r=42 ≈ 263.89 -->
                            <circle v-if="donutSegments.present > 0" cx="50" cy="50" r="42" fill="none" stroke="#12d9e3" stroke-width="10"
                                    :stroke-dasharray="`${donutSegments.present * 2.6389} ${263.89}`" stroke-dashoffset="0"/>
                            <circle v-if="donutSegments.late > 0" cx="50" cy="50" r="42" fill="none" stroke="#ffd700" stroke-width="10"
                                    :stroke-dasharray="`${donutSegments.late * 2.6389} ${263.89}`"
                                    :stroke-dashoffset="`${-donutSegments.present * 2.6389}`"/>
                            <circle v-if="donutSegments.absent > 0" cx="50" cy="50" r="42" fill="none" stroke="#dc2626" stroke-width="10"
                                    :stroke-dasharray="`${donutSegments.absent * 2.6389} ${263.89}`"
                                    :stroke-dashoffset="`${-(donutSegments.present + donutSegments.late) * 2.6389}`"/>
                            <circle v-if="donutSegments.on_leave > 0" cx="50" cy="50" r="42" fill="none" stroke="#7986cb" stroke-width="10"
                                    :stroke-dasharray="`${donutSegments.on_leave * 2.6389} ${263.89}`"
                                    :stroke-dashoffset="`${-(donutSegments.present + donutSegments.late + donutSegments.absent) * 2.6389}`"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Attendance</p>
                            <p class="text-3xl font-black tabular-nums text-primary leading-none">{{ presentPct }}%</p>
                            <p class="mt-0.5 text-[9.5px] font-bold text-on-surface-variant/70">showed up</p>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="mt-4 space-y-1.5">
                        <div v-for="row in [
                            { key: 'present',  label: 'Present',  color: '#12d9e3' },
                            { key: 'late',     label: 'Late',     color: '#ffd700' },
                            { key: 'absent',   label: 'Absent',   color: '#dc2626' },
                            { key: 'on_leave', label: 'On leave', color: '#7986cb' },
                        ]" :key="row.key"
                             class="flex items-center justify-between text-[11.5px]">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full" :style="`background:${row.color}`"></span>
                                <span class="font-semibold text-on-surface-variant">{{ row.label }}</span>
                            </div>
                            <span class="font-black tabular-nums text-primary">{{ statusDistribution[row.key] ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Records table ───────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                <!-- Filter row -->
                <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                    </div>
                    <input v-model="monthValue" type="month" @change="applyFilters"
                           class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20"/>
                    <select v-model="statusValue" @change="applyFilters"
                            class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                        <option value="">All statuses</option>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                        <option value="absent">Absent</option>
                        <option value="on_leave">On leave</option>
                        <option value="holiday">Holiday</option>
                    </select>
                    <div class="relative ml-auto w-full sm:w-72">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">search</span>
                        <input v-model="searchValue" @keyup.enter="applyFilters" placeholder="Search employee or staff ID…"
                               class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!summaries?.data?.length" class="px-6 py-16">
                    <EmptyState title="No attendance records yet"
                                description="Records arrive from biometric webhooks, self-service clock-in, or manual entry. Try a different month, or add a manual entry above." />
                </div>

                <!-- Table -->
                <div v-else class="canvas-scroll overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                            <tr>
                                <th class="px-6 py-3">Employee</th>
                                <th class="px-6 py-3">Date</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3 text-right">In</th>
                                <th class="px-6 py-3 text-right">Out</th>
                                <th class="px-6 py-3 text-right">Hours</th>
                                <th class="px-6 py-3 text-right">OT (premium)</th>
                                <th class="px-6 py-3">Source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="(s, idx) in summaries.data" :key="s.id"
                                class="hover:bg-surface-container-low/40 transition-colors"
                                :style="`animation:slideUpFade 0.35s ease both;animation-delay:${idx*0.015}s`">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[10.5px] font-black text-secondary flex-shrink-0">
                                            {{ initials(s.employee?.user?.name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-[12.5px] font-bold text-primary truncate">{{ s.employee?.user?.name ?? '—' }}</p>
                                            <p class="text-[10px] text-on-surface-variant truncate">{{ s.employee?.employee_no ?? '' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-[12px] font-semibold text-on-surface-variant">{{ s.date }}</td>
                                <td class="px-6 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                          :class="statusMeta(s.status).bg">
                                        <span class="h-1.5 w-1.5 rounded-full" :style="`background:${statusMeta(s.status).dot}`"></span>
                                        {{ statusMeta(s.status).label }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right font-mono text-[11.5px] tabular-nums text-on-surface">{{ s.first_in ?? '—' }}</td>
                                <td class="px-6 py-3 text-right font-mono text-[11.5px] tabular-nums text-on-surface">{{ s.last_out ?? '—' }}</td>
                                <td class="px-6 py-3 text-right font-mono text-[11.5px] tabular-nums font-bold text-primary">{{ fmtHours(s.hours_worked) }}</td>
                                <td class="px-6 py-3 text-right font-mono text-[11.5px] tabular-nums"
                                    :class="Number(s.overtime_hours) > 0 ? 'font-bold' : 'text-on-surface-variant/40'"
                                    :style="Number(s.overtime_hours) > 0 ? 'color:#b88a08' : ''">
                                    {{ fmtHours(s.overtime_hours) }}
                                </td>
                                <td class="px-6 py-3">
                                    <span v-if="s.source" class="inline-flex items-center gap-1 text-[10.5px] font-bold uppercase tracking-wide text-on-surface-variant">
                                        <span class="material-symbols-outlined text-[14px] text-secondary">{{ s.source === 'biometric' ? 'fingerprint' : s.source === 'manual' ? 'edit_note' : 'web' }}</span>
                                        {{ s.source }}
                                    </span>
                                    <span v-else class="text-[11px] text-on-surface-variant/40">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="summaries?.data?.length" class="px-6 py-3 border-t border-outline-variant/40">
                    <Pagination :links="summaries?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <!-- ── Manual entry slide-panel ─────────────────────────────── -->
        <SlidePanel v-model="showManual" title="Manual attendance entry">
            <form @submit.prevent="submitManual" class="space-y-4">
                <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                    <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                        Manual entries are audit-logged with your name and the reason you provide. Use only when biometric or self-service clock-in is unavailable.
                    </p>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Employee ID</label>
                    <input v-model="manual.employee_id" type="number"
                           class="w-full rounded-xl border-outline-variant text-[13.5px] focus:border-secondary focus:ring-secondary/20" required>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Event timestamp</label>
                    <input v-model="manual.event_at" type="datetime-local"
                           class="w-full rounded-xl border-outline-variant text-[13.5px] focus:border-secondary focus:ring-secondary/20" required>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Direction</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="cursor-pointer flex items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-[12.5px] font-bold transition-all"
                               :class="manual.direction === 'in' ? 'border-secondary bg-secondary/8 text-secondary' : 'border-outline-variant text-on-surface-variant hover:border-secondary/30'">
                            <input v-model="manual.direction" aria-label="Clock-in" type="radio" value="in" class="sr-only"/>
                            <span class="material-symbols-outlined text-[16px]">login</span> Clock-in
                        </label>
                        <label class="cursor-pointer flex items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-[12.5px] font-bold transition-all"
                               :class="manual.direction === 'out' ? 'border-secondary bg-secondary/8 text-secondary' : 'border-outline-variant text-on-surface-variant hover:border-secondary/30'">
                            <input v-model="manual.direction" aria-label="Clock-out" type="radio" value="out" class="sr-only"/>
                            <span class="material-symbols-outlined text-[16px]">logout</span> Clock-out
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Reason (audit-logged)</label>
                    <textarea v-model="manual.reason" rows="3"
                              placeholder="e.g. Biometric outage at HQ — manual entry by HR"
                              class="w-full rounded-xl border-outline-variant text-[13.5px] focus:border-secondary focus:ring-secondary/20" required></textarea>
                </div>
                <p v-if="manual.errors.reason" class="text-rose-600 text-[11.5px] font-bold">{{ manual.errors.reason }}</p>
                <button type="submit"
                        :disabled="manual.processing"
                        class="btn-shimmer w-full flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-[13px] font-black text-white shadow-glow-sm transition-all disabled:opacity-50 hover:-translate-y-px"
                        style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                    <span class="material-symbols-outlined text-[18px]">check_circle</span>
                    {{ manual.processing ? 'Recording…' : 'Record entry' }}
                </button>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>
