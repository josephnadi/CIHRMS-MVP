<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    period:  Object,
    summary: Object,
    days:    Object,
});

// ── Live clock ──────────────────────────────────────────────────────────────
const now = ref(new Date());
let clockInterval = null;
onMounted(() => { clockInterval = setInterval(() => { now.value = new Date(); }, 1000); });
onUnmounted(() => { clearInterval(clockInterval); });

const liveTime = computed(() =>
    now.value.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
);
const liveDate = computed(() =>
    now.value.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
);

// ── Month navigation ─────────────────────────────────────────────────────────
const monthValue = ref(props.period?.label ?? '');
const changeMonth = () => router.get(route('attendance.me'), { month: monthValue.value }, {
    preserveState: true, replace: true,
});

// ── Clock in/out ─────────────────────────────────────────────────────────────
const clockForm = useForm({ direction: 'in', geo_lat: null, geo_lng: null });
const geoStatus = ref('idle'); // idle | resolving | captured | denied

const tryGeo = () => new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null);
    geoStatus.value = 'resolving';
    navigator.geolocation.getCurrentPosition(
        p => {
            geoStatus.value = 'captured';
            resolve({ lat: p.coords.latitude, lng: p.coords.longitude });
        },
        () => {
            geoStatus.value = 'denied';
            resolve(null);
        },
        { timeout: 4000 },
    );
});

const clockSelf = async (direction) => {
    clockForm.direction = direction;
    const geo = await tryGeo();
    if (geo) { clockForm.geo_lat = geo.lat; clockForm.geo_lng = geo.lng; }
    clockForm.post(route('attendance.clock'), { preserveScroll: true });
};

// Determine today's status from days data
const todayStatus = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    const d = props.days?.data?.find(row => row.date === today);
    return d?.status ?? null;
});

const isClockedIn = computed(() => {
    const d = props.days?.data?.find(row => row.date === new Date().toISOString().slice(0, 10));
    return d?.first_in && !d?.last_out;
});

// ── Correction slide-panel ────────────────────────────────────────────────────
const showCorrection = ref(false);
const correctionForm = useForm({
    requested_event_at: '',
    requested_direction: 'in',
    reason: '',
});

function submitCorrection() {
    correctionForm.post(route('attendance.corrections.store'), {
        preserveScroll: true,
        onSuccess: () => { showCorrection.value = false; correctionForm.reset(); },
    });
}

// ── Stats ────────────────────────────────────────────────────────────────────
const stats = computed(() => {
    const s = props.summary ?? {};
    return {
        daysWorked:  s.days_worked    ?? 0,
        totalHours:  s.total_hours    ?? (s.days_worked ? (s.days_worked * 8).toFixed(1) : 0),
        daysLate:    s.days_late      ?? 0,
        overtime:    s.overtime_hours ?? 0,
    };
});

// ── Calendar grid ─────────────────────────────────────────────────────────────
const calendarDays = computed(() => {
    if (!props.days?.data?.length) return [];
    // Build a lookup
    const lookup = {};
    for (const d of props.days.data) {
        lookup[d.date] = d;
    }
    // Figure out month from period.label (format YYYY-MM)
    const label = props.period?.label ?? new Date().toISOString().slice(0, 7);
    const [year, month] = label.split('-').map(Number);
    const firstDay = new Date(year, month - 1, 1);
    const lastDay  = new Date(year, month, 0);

    // Pad to start on Mon (weekday 1)
    const startPad = (firstDay.getDay() + 6) % 7; // 0=Mon
    const cells = [];
    for (let i = 0; i < startPad; i++) cells.push(null);
    for (let d = 1; d <= lastDay.getDate(); d++) {
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        cells.push({ date: d, dateStr, record: lookup[dateStr] ?? null });
    }
    return cells;
});

const todayStr = new Date().toISOString().slice(0, 10);

const dayBg = (cell) => {
    if (!cell) return '';
    const status = cell.record?.status;
    if (!status) {
        // future or weekend
        return 'bg-surface-container-low text-on-surface-variant/40';
    }
    return {
        present:  'bg-emerald-50 text-emerald-800',
        late:     'bg-amber-50  text-amber-800',
        half_day: 'bg-amber-50  text-amber-800',
        absent:   'bg-rose-50   text-rose-700',
        on_leave: 'bg-violet-50 text-violet-700',
        holiday:  'bg-violet-50 text-violet-700',
        weekend:  'bg-slate-50  text-slate-400',
    }[status] ?? 'bg-surface-container-low text-on-surface-variant';
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const formatTs = (ts) => {
    if (!ts) return '—';
    return new Date(ts).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
};

const statusToneClass = (s) => ({
    present:  'bg-emerald-100 text-emerald-800',
    late:     'bg-amber-100   text-amber-800',
    half_day: 'bg-amber-100   text-amber-800',
    absent:   'bg-rose-100    text-rose-700',
    on_leave: 'bg-violet-100  text-violet-700',
    holiday:  'bg-violet-100  text-violet-700',
    weekend:  'bg-slate-100   text-slate-500',
}[s] ?? 'bg-surface-container text-on-surface-variant');

const attendancePct = computed(() => {
    const r = props.summary?.attendance_ratio ?? 0;
    return (r * 100).toFixed(0);
});
</script>

<template>
    <Head title="My Attendance" />
    <AuthenticatedLayout active-module="attendance">

        <!-- ── Header ────────────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">My Attendance</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Track your clock-in, clock-out, and monthly attendance summary.
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ attendancePct }}% this month
                        </span>
                    </p>
                </div>
                <button
                    @click="showCorrection = true"
                    class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low transition-colors flex items-center gap-2"
                >
                    <span class="material-symbols-outlined text-[17px]">edit_note</span>
                    Request Correction
                </button>
            </div>
        </template>

        <div class="p-6 space-y-6 animate-reveal-up">

            <!-- Hero clock card — gold hairline (single 5% accent moment) + disciplined navy->cobalt->magenta gradient -->
            <div
                class="relative overflow-hidden rounded-2xl p-6 text-white shadow-glow"
                style="background: linear-gradient(135deg, #0d1452 0%, #1a237e 60%, #d912e3 100%)"
            >
                <div class="pointer-events-none absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.6),transparent)"></div>
                <!-- subtle grid texture -->
                <div class="absolute inset-0 opacity-10" style="background-image: repeating-linear-gradient(0deg,transparent,transparent 24px,rgba(255,255,255,.15) 24px,rgba(255,255,255,.15) 25px),repeating-linear-gradient(90deg,transparent,transparent 24px,rgba(255,255,255,.15) 24px,rgba(255,255,255,.15) 25px)"></div>

                <div class="relative flex flex-wrap items-center justify-between gap-6">
                    <!-- Clock display -->
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-[0.18em] text-white/60 mb-1">Current Time</p>
                        <p class="font-mono text-[3rem] font-black leading-none tracking-tight tabular-nums">{{ liveTime }}</p>
                        <p class="mt-1 text-[14px] font-medium text-white/80">{{ liveDate }}</p>

                        <!-- Today status pill -->
                        <div class="mt-3 inline-flex items-center gap-2">
                            <span
                                v-if="isClockedIn"
                                class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-[12px] font-black text-emerald-300 border border-emerald-400/30"
                            >
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                                </span>
                                CLOCKED IN
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-[12px] font-black text-white/70 border border-white/20"
                            >
                                <span class="h-2 w-2 rounded-full bg-white/40"></span>
                                CLOCKED OUT
                            </span>
                        </div>
                    </div>

                    <!-- Clock in/out actions -->
                    <div class="flex flex-col items-end gap-3">
                        <!-- GPS status -->
                        <div class="flex items-center gap-1.5 text-[11px] text-white/60">
                            <span class="material-symbols-outlined text-[14px]">location_on</span>
                            <span v-if="geoStatus === 'idle'">GPS available</span>
                            <span v-else-if="geoStatus === 'resolving'" class="animate-pulse">Capturing GPS…</span>
                            <span v-else-if="geoStatus === 'captured'" class="text-emerald-300">GPS captured</span>
                            <span v-else class="text-white/40">GPS unavailable</span>
                        </div>

                        <div class="flex items-center gap-3">
                            <button
                                v-if="!isClockedIn"
                                @click="clockSelf('in')"
                                :disabled="clockForm.processing"
                                class="btn-shimmer inline-flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-[14px] font-black text-primary shadow-lg transition-all hover:-translate-y-px hover:shadow-xl active:scale-[0.97] disabled:opacity-60"
                            >
                                <span v-if="clockForm.processing" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
                                <span v-else class="material-symbols-outlined text-[18px]">login</span>
                                Clock In
                            </button>
                            <button
                                v-else
                                @click="clockSelf('out')"
                                :disabled="clockForm.processing"
                                class="btn-shimmer inline-flex items-center gap-2 rounded-xl bg-white/20 border border-white/40 px-6 py-3 text-[14px] font-black text-white shadow-lg transition-all hover:-translate-y-px hover:bg-white/30 active:scale-[0.97] disabled:opacity-60"
                            >
                                <span v-if="clockForm.processing" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
                                <span v-else class="material-symbols-outlined text-[18px]">logout</span>
                                Clock Out
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stat cards — Total Hours gets the gold accent (the institutional time-on-the-clock metric) -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                    :value="stats.daysWorked"
                    label="Days Worked"
                    icon="event_available"
                    color="green"
                />
                <StatCard
                    :value="typeof stats.totalHours === 'number' ? stats.totalHours.toFixed(1) : stats.totalHours"
                    label="Total Hours"
                    icon="schedule"
                    color="gold"
                />
                <StatCard
                    :value="stats.daysLate"
                    label="Late Arrivals"
                    icon="alarm"
                    color="amber"
                />
                <StatCard
                    :value="typeof stats.overtime === 'number' ? stats.overtime.toFixed(1) : stats.overtime"
                    label="Overtime Hrs"
                    icon="more_time"
                    color="magenta"
                />
            </div>

            <!-- ── Month calendar grid + table side-by-side ─────────────────── -->
            <div class="grid gap-6 lg:grid-cols-[auto_1fr]">

                <!-- Calendar -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 card-lift min-w-[300px]">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">
                            {{ period?.label ?? '' }}
                        </p>
                        <input
                            v-model="monthValue"
                            type="month"
                            @change="changeMonth"
                            class="rounded-lg border border-outline-variant bg-surface-container-low px-2.5 py-1 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50 transition-all"
                        />
                    </div>

                    <!-- Legend -->
                    <div class="flex flex-wrap gap-2 mb-4">
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold"><span class="h-2 w-2 rounded-sm bg-emerald-200"></span>Present</span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold"><span class="h-2 w-2 rounded-sm bg-amber-200"></span>Late</span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold"><span class="h-2 w-2 rounded-sm bg-rose-200"></span>Absent</span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold"><span class="h-2 w-2 rounded-sm bg-violet-200"></span>Leave</span>
                    </div>

                    <!-- Day-of-week headers -->
                    <div class="grid grid-cols-7 gap-1 mb-1">
                        <div v-for="d in ['M','T','W','T','F','S','S']" :key="d"
                             class="text-center text-[10px] font-black text-on-surface-variant/60 py-1">{{ d }}</div>
                    </div>

                    <!-- Calendar cells -->
                    <div class="grid grid-cols-7 gap-1">
                        <template v-for="(cell, idx) in calendarDays" :key="idx">
                            <!-- Empty padding -->
                            <div v-if="!cell" class="h-9 rounded-lg"></div>
                            <!-- Day cell -->
                            <div
                                v-else
                                :class="[
                                    'h-9 rounded-lg flex items-center justify-center text-[12px] font-bold transition-all',
                                    dayBg(cell),
                                    cell.dateStr === todayStr
                                        ? 'ring-2 ring-offset-1 ring-primary shadow-sm'
                                        : ''
                                ]"
                                :title="cell.record?.status_label ?? cell.dateStr"
                            >
                                {{ cell.date }}
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Attendance table -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
                    <div class="px-5 py-3.5 border-b border-outline-variant/40">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Daily Records</p>
                    </div>

                    <div v-if="!days?.data?.length" class="p-8">
                        <EmptyState title="No attendance records for this period." class="py-4" />
                    </div>

                    <div v-else class="overflow-auto max-h-[480px]">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10">
                                <tr>
                                    <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Date</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Status</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 text-right">In</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 text-right">Out</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 text-right">Hours</th>
                                    <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 text-right">OT</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/40">
                                <tr
                                    v-for="d in days.data"
                                    :key="d.id ?? d.date"
                                    :class="[
                                        'hover:bg-surface-container/40 transition-colors',
                                        d.date === todayStr ? 'bg-secondary/5' : ''
                                    ]"
                                >
                                    <td class="px-4 py-3">
                                        <span class="text-[13px] font-semibold text-on-surface">{{ formatDate(d.date) }}</span>
                                        <span v-if="d.date === todayStr" class="ml-1.5 text-[10px] font-black text-secondary uppercase">Today</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold', statusToneClass(d.status)]">
                                            {{ d.status_label ?? d.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-[12px] text-on-surface-variant tabular-nums">
                                        {{ d.first_in ? formatTs(d.date + ' ' + d.first_in) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-[12px] text-on-surface-variant tabular-nums">
                                        {{ d.last_out ? formatTs(d.date + ' ' + d.last_out) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-[13px] font-semibold text-on-surface tabular-nums">
                                        {{ d.hours_worked != null ? Number(d.hours_worked).toFixed(2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-[13px] tabular-nums"
                                        :class="Number(d.overtime_hours) > 0 ? 'text-violet-700 font-bold' : 'text-on-surface-variant/50'">
                                        {{ d.overtime_hours != null ? Number(d.overtime_hours).toFixed(2) : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="days?.links?.length > 3" class="border-t border-outline-variant/40 px-4 py-3">
                        <Pagination :links="days.links" />
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Request Correction slide-panel ─────────────────────────────────── -->
        <SlidePanel :open="showCorrection" @close="showCorrection = false" title="Request Attendance Correction" size="md">
            <form @submit.prevent="submitCorrection" class="space-y-5 p-6">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-[20px] text-amber-600 mt-0.5">info</span>
                    <p class="text-[12px] text-amber-800 leading-relaxed">
                        Submit a correction if your clock-in or clock-out was missed or recorded incorrectly. An HR administrator will review and approve or reject your request.
                    </p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Event Date & Time <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="correctionForm.requested_event_at"
                        type="datetime-local"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': correctionForm.errors.requested_event_at }"
                    />
                    <p v-if="correctionForm.errors.requested_event_at" class="mt-1 text-[11px] text-red-500">{{ correctionForm.errors.requested_event_at }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Direction <span class="text-red-500">*</span>
                    </label>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            @click="correctionForm.requested_direction = 'in'"
                            :class="[
                                'flex-1 rounded-xl border px-4 py-2.5 text-[13px] font-bold transition-all flex items-center justify-center gap-2',
                                correctionForm.requested_direction === 'in'
                                    ? 'border-primary bg-primary text-white shadow-glow-sm'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
                            ]"
                        >
                            <span class="material-symbols-outlined text-[16px]">login</span>
                            Clock-In
                        </button>
                        <button
                            type="button"
                            @click="correctionForm.requested_direction = 'out'"
                            :class="[
                                'flex-1 rounded-xl border px-4 py-2.5 text-[13px] font-bold transition-all flex items-center justify-center gap-2',
                                correctionForm.requested_direction === 'out'
                                    ? 'border-primary bg-primary text-white shadow-glow-sm'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
                            ]"
                        >
                            <span class="material-symbols-outlined text-[16px]">logout</span>
                            Clock-Out
                        </button>
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Reason <span class="text-red-500">*</span>
                        <span class="ml-1 font-normal text-on-surface-variant/60">(min 8 characters)</span>
                    </label>
                    <textarea
                        v-model="correctionForm.reason"
                        required
                        minlength="8"
                        maxlength="500"
                        rows="4"
                        placeholder="Explain why a correction is needed…"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                        :class="{ 'border-red-400': correctionForm.errors.reason }"
                    />
                    <div class="mt-1 flex items-center justify-between">
                        <p v-if="correctionForm.errors.reason" class="text-[11px] text-red-500">{{ correctionForm.errors.reason }}</p>
                        <span class="ml-auto text-[11px] text-on-surface-variant/50">{{ correctionForm.reason?.length ?? 0 }}/500</span>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showCorrection = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitCorrection"
                        :disabled="correctionForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span v-if="correctionForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Submit Request</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
