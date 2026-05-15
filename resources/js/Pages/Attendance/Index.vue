<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';

defineProps({
    activeModule: { type: String, default: 'attendance' },
});

const { success, comingSoon } = useToast();

// ── Live clock ─────────────────────────────────────────────────────────
const now = ref(new Date());
let _tick = null;
onMounted(() => { _tick = setInterval(() => { now.value = new Date(); }, 1000); });
onBeforeUnmount(() => { if (_tick) clearInterval(_tick); });

const liveTime = computed(() => now.value.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
const today    = computed(() => now.value.toLocaleDateString('en-GH', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }));

// ── Mock attendance state — back-end wiring will replace this later ────
const status = ref('out');           // 'out' | 'in' | 'break'
const clockInAt = ref(null);

const stats = ref({ present: 1217, late: 8, onLeave: 47, absent: 12 });

const sessionHours = computed(() => {
    if (!clockInAt.value) return '—';
    const diffMs = now.value - clockInAt.value;
    const h = Math.floor(diffMs / 3_600_000);
    const m = Math.floor((diffMs % 3_600_000) / 60_000);
    return `${h}h ${m}m`;
});

function clockIn() {
    if (status.value === 'in') return;
    status.value = 'in';
    clockInAt.value = new Date();
    stats.value.present += 1;
    success(`Clocked in at ${liveTime.value}`);
}
function clockOut() {
    if (status.value === 'out') return;
    status.value = 'out';
    success(`Clocked out at ${liveTime.value} — session ${sessionHours.value}`);
    clockInAt.value = null;
}
function takeBreak() {
    status.value = status.value === 'break' ? 'in' : 'break';
    success(status.value === 'break' ? 'Break started' : 'Break ended');
}

// ── Weekday strip (Mon→Sun) ─────────────────────────────────────────────
const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const weekRates = [96, 94, 95, 92, 93, 28, 12];   // mock per-day attendance %
const todayIdx  = computed(() => (now.value.getDay() + 6) % 7); // Mon=0
</script>

<template>
    <Head title="Attendance — CIHRMS" />

    <AuthenticatedLayout :activeModule="activeModule">

        <!-- Header -->
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-[22px] font-black tracking-tight text-on-surface">Attendance &amp; Time Tracking</h1>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">{{ today }}</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 shadow-card">
                    <span class="material-symbols-outlined text-[18px] text-secondary">schedule</span>
                    <span class="font-mono text-[14px] font-black text-on-surface tabular-nums">{{ liveTime }}</span>
                </div>
                <button @click="status === 'out' ? clockIn() : clockOut()"
                        type="button"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow hover:-translate-y-px active:scale-[0.97] transition-all"
                        :style="status === 'out'
                            ? 'background:linear-gradient(135deg,#059669,#34d399)'
                            : 'background:linear-gradient(135deg,#dc2626,#ef4444)'">
                    <span class="material-symbols-outlined text-[17px]">{{ status === 'out' ? 'login' : 'logout' }}</span>
                    {{ status === 'out' ? 'Clock In' : 'Clock Out' }}
                </button>
            </div>
        </div>

        <!-- Stat strip -->
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="(s, i) in [
                { label: 'Present',  val: stats.present,  rgb: '5,150,105',   icon: 'check_circle', sub: 'on-site + remote' },
                { label: 'Late',     val: stats.late,     rgb: '217,119,6',   icon: 'schedule',     sub: 'after 09:00' },
                { label: 'On Leave', val: stats.onLeave,  rgb: '0,81,213',    icon: 'beach_access', sub: 'approved' },
                { label: 'Absent',   val: stats.absent,   rgb: '220,38,38',   icon: 'person_off',   sub: 'no record' },
            ]" :key="i"
                class="card-lift relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl"
                         :style="`background:rgba(${s.rgb},0.12);border:1px solid rgba(${s.rgb},0.2)`">
                        <span class="material-symbols-outlined text-[18px]"
                              :style="`color:rgb(${s.rgb});font-variation-settings:'FILL' 1`">{{ s.icon }}</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/40">{{ s.sub }}</span>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ s.label }}</p>
                <p class="mt-0.5 text-[26px] font-black tracking-tight text-on-surface">{{ s.val.toLocaleString() }}</p>
                <div class="absolute -right-4 -bottom-4 h-16 w-16 rounded-full opacity-[0.05]"
                     :style="`background:rgb(${s.rgb})`"></div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- LEFT: weekly view + log -->
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-[14px] font-bold text-on-surface">This Week</h3>
                        <span class="text-[11px] font-bold text-on-surface-variant/55">Avg 92.3% present</span>
                    </div>
                    <div class="grid grid-cols-7 gap-2">
                        <div v-for="(day, i) in weekdays" :key="day" class="text-center">
                            <p class="text-[10px] font-black uppercase text-on-surface-variant/55 mb-1.5">{{ day }}</p>
                            <div class="flex h-24 flex-col items-center justify-end rounded-xl p-2"
                                 :class="i === todayIdx
                                    ? 'bg-secondary/10 border border-secondary/30'
                                    : 'bg-surface-container-low'">
                                <div class="w-full rounded-md transition-all duration-500"
                                     :style="`height:${weekRates[i]}%;background:linear-gradient(to top,#0051d5,#316bf3)`"></div>
                                <span class="mt-1 text-[10px] font-black text-on-surface tabular-nums">{{ weekRates[i] }}%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's events log -->
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <h3 class="mb-4 text-[14px] font-bold text-on-surface">Today's Activity</h3>
                    <ul class="space-y-3 text-[13px]">
                        <li v-for="(e, i) in [
                            { icon: 'login',      label: 'Clock-in window opened',     time: '07:00', color: '#059669' },
                            { icon: 'group',      label: '1,217 staff have checked in', time: '08:42', color: '#0051d5' },
                            { icon: 'coffee',     label: 'Lunch hour starts',          time: '12:30', color: '#d97706' },
                            { icon: 'logout',     label: 'Office closing reminder',    time: '17:00', color: '#7c3aed' },
                        ]" :key="i" class="flex items-center gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl"
                                  :style="`background:${e.color}15`">
                                <span class="material-symbols-outlined text-[15px]" :style="`color:${e.color}`">{{ e.icon }}</span>
                            </span>
                            <span class="flex-1 text-on-surface">{{ e.label }}</span>
                            <span class="font-mono text-[11px] font-bold text-on-surface-variant/65 tabular-nums">{{ e.time }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- RIGHT: my session card -->
            <div class="rounded-2xl p-6 text-white shadow-xl"
                 style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06);">
                <h3 class="text-[11px] font-black uppercase tracking-widest text-white/55 mb-4">My Session</h3>
                <div class="space-y-4 text-[12.5px]">
                    <div class="flex items-center justify-between">
                        <span class="text-white/65">Status</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                              :class="status === 'in'   ? 'bg-green-500/15 text-green-400'
                                     : status === 'break' ? 'bg-amber-500/15 text-amber-400'
                                                          : 'bg-slate-500/20 text-slate-300'">
                            <span class="h-1.5 w-1.5 rounded-full live-dot"
                                  :class="status === 'in' ? 'bg-green-400' : status === 'break' ? 'bg-amber-400' : 'bg-slate-400'"></span>
                            {{ status === 'in' ? 'Clocked In' : status === 'break' ? 'On Break' : 'Off' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-white/65">Clock-in</span>
                        <span class="font-mono font-black text-white">
                            {{ clockInAt ? clockInAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '—' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-white/65">Session</span>
                        <span class="font-mono font-black text-white">{{ sessionHours }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-white/65">Week total</span>
                        <span class="font-mono font-black text-white">36h 24m</span>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-2">
                    <button @click="takeBreak" type="button"
                            :disabled="status === 'out'"
                            class="rounded-xl px-3 py-2 text-[12px] font-bold text-white border transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :style="status === 'break'
                                ? 'background:rgba(217,119,6,0.25);border-color:rgba(217,119,6,0.5);'
                                : 'background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.12);'">
                        <span class="material-symbols-outlined text-[14px] align-middle mr-1">coffee</span>
                        {{ status === 'break' ? 'End Break' : 'Take Break' }}
                    </button>
                    <button @click="comingSoon('Time-sheet correction')" type="button"
                            class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-[12px] font-bold text-white hover:bg-white/10 transition-colors">
                        <span class="material-symbols-outlined text-[14px] align-middle mr-1">edit_note</span>
                        Correct Log
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
