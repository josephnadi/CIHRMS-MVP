<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LiveBars from '@/Components/charts/LiveBars.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    analytics:    Object,
    activeModule: String,
});

const a = computed(() => props.analytics ?? {});
const kpis = computed(() => a.value.kpis ?? {});

// â”€â”€ Live sync: random 15â€“20s Inertia partial reload of analytics â”€â”€â”€â”€â”€â”€â”€â”€â”€
const lastSync  = ref(Date.now());
const isSyncing = ref(false);
const nowTick   = ref(Date.now());
const syncAgoLabel = computed(() => {
    const s = Math.max(0, Math.floor((nowTick.value - lastSync.value) / 1000));
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    return Math.floor(s / 3600) + 'h';
});

const _intervals = [];
let   _reloadTimer = null;
const nextReloadMs = () => 15000 + Math.floor(Math.random() * 5001);

function scheduleServerReload() {
    _reloadTimer = setTimeout(() => {
        isSyncing.value = true;
        router.reload({
            only: ['analytics'],
            preserveScroll: true,
            preserveState:  true,
            onFinish: () => {
                isSyncing.value = false;
                lastSync.value  = Date.now();
                scheduleServerReload();
            },
        });
    }, nextReloadMs());
}

onMounted(() => {
    _intervals.push(setInterval(() => { nowTick.value = Date.now(); }, 1000));
    scheduleServerReload();
});

onBeforeUnmount(() => {
    _intervals.forEach(clearInterval);
    if (_reloadTimer) clearTimeout(_reloadTimer);
});

// â”€â”€ SVG line/area chart helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const w = 720, h = 220, pad = 30;

const linePath = (data, key = 'value') => {
    if (!data?.length) return '';
    const values = data.map(d => d[key] ?? 0);
    const max = Math.max(...values, 1);
    const stepX = (w - pad * 2) / Math.max(data.length - 1, 1);
    return data.map((d, i) => {
        const x = pad + i * stepX;
        const y = h - pad - ((d[key] ?? 0) / max) * (h - pad * 2);
        return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
    }).join(' ');
};

const areaPath = (data, key = 'value') => {
    if (!data?.length) return '';
    const values = data.map(d => d[key] ?? 0);
    const max = Math.max(...values, 1);
    const stepX = (w - pad * 2) / Math.max(data.length - 1, 1);
    const top = data.map((d, i) => {
        const x = pad + i * stepX;
        const y = h - pad - ((d[key] ?? 0) / max) * (h - pad * 2);
        return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
    }).join(' ');
    return `${top} L ${(w - pad).toFixed(1)} ${(h - pad).toFixed(1)} L ${pad.toFixed(1)} ${(h - pad).toFixed(1)} Z`;
};

const pointPositions = (data, key = 'value') => {
    if (!data?.length) return [];
    const values = data.map(d => d[key] ?? 0);
    const max = Math.max(...values, 1);
    const stepX = (w - pad * 2) / Math.max(data.length - 1, 1);
    return data.map((d, i) => ({
        x: pad + i * stepX,
        y: h - pad - ((d[key] ?? 0) / max) * (h - pad * 2),
        label: d.label,
        value: d.value,
    }));
};

// â”€â”€ Donut chart helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const donutSegments = (data) => {
    if (!data?.length) return [];
    const total = data.reduce((s, d) => s + (d.value ?? 0), 0);
    if (!total) return [];
    let offset = 0;
    const c = 2 * Math.PI * 42;
    return data.map((d, i) => {
        const pct = (d.value ?? 0) / total;
        const len = pct * c;
        const seg = {
            ...d,
            dashArray: `${len} ${c - len}`,
            dashOffset: -offset,
            color: donutColor(i),
            pct: (pct * 100).toFixed(1),
        };
        offset += len;
        return seg;
    });
};

// Disciplined donut palette — fixes a duplicate #1a237e at index 2 (the
// original list had cobalt twice). Sovereign Precision blue family + cyan
// + magenta + semantic green/amber/red for outcome categories.
const donutColor = (i) => {
    const palette = ['#0d1452', '#1a237e', '#7986cb', '#0e8a93', '#d912e3', '#059669', '#d97706', '#dc2626'];
    return palette[i % palette.length];
};

const totalLeave = computed(() => (a.value.leaveTypeSplit ?? []).reduce((s, d) => s + d.value, 0));

// â”€â”€ Horizontal bar (department efficiency) helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const efficiencyColor = (score) => {
    if (score >= 80) return '#059669';
    if (score >= 60) return '#3949ab';
    if (score >= 40) return '#d97706';
    return '#dc2626';
};

// â”€â”€ Vertical bar (headcount / hires / tickets) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const barMax = (data, key = 'value') => Math.max(...(data ?? []).map(d => d[key] ?? 0), 1);

const formatNum = (n) => (n ?? 0).toLocaleString('en-GH');

// ── Editorial-Sovereign masthead ────────────────────────────────
// Volume = year offset from CIHRM-GH platform inception (2023). Issue = day-of-year.
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
</script>

<template>
    <Head title="Performance Analytics" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">monitoring</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">PERFORMANCE LEDGER</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Performance Management</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Performance contracts, mid-cycle check-ins, calibration sessions, PIPs.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('performance.reviews.index')"
                              class="flex items-center gap-2 rounded-xl border border-outline-variant/80 px-4 py-2 text-[13px] font-bold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary transition-all">
                            <span class="material-symbols-outlined text-[17px]">rate_review</span>
                            Reviews
                        </Link>
                        <Link :href="route('performance.goals.index')"
                              class="flex items-center gap-2 rounded-xl border border-outline-variant/80 px-4 py-2 text-[13px] font-bold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary transition-all">
                            <span class="material-symbols-outlined text-[17px]">flag</span>
                            Goals
                        </Link>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- â”€â”€ Row 1: Hires trend + Department efficiency â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="grid gap-6 lg:grid-cols-3">

                    <!-- Hires trend (line+area) -->
                    <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-[14px] font-bold text-on-surface">Hires & Headcount Velocity</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">New hires per month, trailing 12 months</p>
                            </div>
                            <div class="flex items-center gap-3 text-[10px] font-semibold">
                                <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-full" style="background:linear-gradient(90deg,#0d1452,#1a237e)"></span>Hires</span>
                            </div>
                        </div>

                        <svg :viewBox="`0 0 ${w} ${h}`" preserveAspectRatio="xMidYMid meet" class="w-full h-[220px]">
                            <defs>
                                <linearGradient id="hiresFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#1a237e" stop-opacity="0.32"/>
                                    <stop offset="100%" stop-color="#1a237e" stop-opacity="0"/>
                                </linearGradient>
                            </defs>

                            <!-- gridlines -->
                            <g stroke="currentColor" class="text-outline-variant/40" stroke-dasharray="3 4">
                                <line :x1="pad" :y1="pad"           :x2="w - pad" :y2="pad" />
                                <line :x1="pad" :y1="(h+pad)/2"     :x2="w - pad" :y2="(h+pad)/2" />
                                <line :x1="pad" :y1="h - pad"       :x2="w - pad" :y2="h - pad" />
                            </g>

                            <path :d="areaPath(a.hiresByMonth)" fill="url(#hiresFill)" />
                            <path :d="linePath(a.hiresByMonth)" fill="none" stroke="#1a237e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

                            <g v-for="(p, i) in pointPositions(a.hiresByMonth)" :key="i">
                                <circle :cx="p.x" :cy="p.y" r="3.5" fill="#fff" stroke="#1a237e" stroke-width="2" />
                                <text :x="p.x" :y="h - pad + 18" class="fill-current text-on-surface-variant" text-anchor="middle" font-size="10" font-weight="600">{{ p.label }}</text>
                            </g>
                        </svg>
                    </div>

                    <!-- Tenure distribution (vertical bars) -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Tenure Distribution</h3>
                        <p class="text-[11px] text-on-surface-variant mb-5">Active staff by years of service</p>

                        <div class="space-y-3">
                            <div v-for="(bucket, i) in a.tenureBuckets ?? []" :key="i" class="space-y-1.5">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-semibold text-on-surface-variant">{{ bucket.label }}</span>
                                    <span class="font-bold text-on-surface">{{ bucket.value }}</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-surface-container-low overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :style="`width:${(bucket.value / barMax(a.tenureBuckets)) * 100}%;background:linear-gradient(90deg,#0d1452,#1a237e);transition-duration:0.8s`"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- â”€â”€ Row 2: Department efficiency + Leave type donut â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="grid gap-6 lg:grid-cols-3">

                    <!-- Department efficiency (horizontal bars) -->
                    <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <div class="flex items-start justify-between mb-5">
                            <div>
                                <h3 class="text-[14px] font-bold text-on-surface">Department Efficiency</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">Ticket resolution rate by department</p>
                            </div>
                            <div class="text-[11px] text-on-surface-variant">
                                <span class="font-bold text-on-surface">{{ a.avgResolveHours ?? 0 }}h</span> avg resolution
                            </div>
                        </div>

                        <div v-if="(a.deptEfficiency ?? []).length === 0" class="py-8 text-center text-[12px] text-on-surface-variant/60 italic">
                            No ticket activity to compute efficiency yet.
                        </div>

                        <div v-else class="space-y-3.5">
                            <div v-for="dept in a.deptEfficiency" :key="dept.code" class="grid grid-cols-12 items-center gap-3">
                                <div class="col-span-3">
                                    <p class="text-[12px] font-semibold text-on-surface leading-tight">{{ dept.name }}</p>
                                    <p class="text-[10px] text-on-surface-variant/60">{{ dept.staff }} staff</p>
                                </div>
                                <div class="col-span-7 relative h-6 rounded-md bg-surface-container-low overflow-hidden">
                                    <div
                                        class="absolute inset-y-0 left-0 rounded-md transition-all"
                                        :style="`width:${dept.score}%;background:linear-gradient(90deg,${efficiencyColor(dept.score)},${efficiencyColor(dept.score)}cc);transition-duration:0.9s`"
                                    ></div>
                                </div>
                                <div class="col-span-2 text-right">
                                    <span class="text-[14px] font-black tabular-nums" :style="`color:${efficiencyColor(dept.score)}`">{{ dept.score }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Leave type breakdown (donut) -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Leave Mix</h3>
                        <p class="text-[11px] text-on-surface-variant mb-4">Approved this year by type</p>

                        <div v-if="totalLeave === 0" class="py-12 text-center text-[12px] text-on-surface-variant/60 italic">
                            No approved leave this year yet.
                        </div>

                        <div v-else class="flex items-center gap-4">
                            <svg viewBox="0 0 100 100" class="h-32 w-32 -rotate-90 flex-shrink-0">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" class="text-surface-container-low" stroke-width="14" />
                                <circle
                                    v-for="(seg, i) in donutSegments(a.leaveTypeSplit)"
                                    :key="i"
                                    cx="50" cy="50" r="42"
                                    fill="none"
                                    :stroke="seg.color"
                                    stroke-width="14"
                                    :stroke-dasharray="seg.dashArray"
                                    :stroke-dashoffset="seg.dashOffset"
                                    stroke-linecap="butt"
                                    style="transition: stroke-dasharray 0.8s ease, stroke-dashoffset 0.8s ease;"
                                />
                                <text
                                    x="50" y="50"
                                    text-anchor="middle"
                                    dominant-baseline="central"
                                    transform="rotate(90 50 50)"
                                    font-size="16"
                                    font-weight="900"
                                    class="fill-current text-on-surface"
                                >{{ totalLeave }}</text>
                            </svg>

                            <div class="flex-1 space-y-1.5 min-w-0">
                                <div v-for="(seg, i) in donutSegments(a.leaveTypeSplit)" :key="i" class="flex items-center justify-between text-[11px]">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="h-2 w-2 rounded-full flex-shrink-0" :style="`background:${seg.color}`"></span>
                                        <span class="font-semibold text-on-surface truncate">{{ seg.label }}</span>
                                    </div>
                                    <span class="font-mono font-bold text-on-surface-variant flex-shrink-0">{{ seg.pct }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- â”€â”€ Row 3: Headcount by dept + Top performers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="grid gap-6 lg:grid-cols-3">

                    <!-- Headcount by department (bars) -->
                    <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Headcount by Department</h3>
                        <p class="text-[11px] text-on-surface-variant mb-5">Active employees only</p>

                        <LiveBars
                            :data="(a.headcountByDept ?? []).map(d => ({ label: d.label, value: d.value }))"
                            :height="180"
                            color="#1a237e"
                            accent-color="#ffd700"
                            second-color="#12d9e3"
                            :show-median="true"
                            :rounded="6"
                        />
                    </div>

                    <!-- Top performers -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 flex flex-col">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Top Resolvers</h3>
                        <p class="text-[11px] text-on-surface-variant mb-4">Most tickets closed</p>

                        <div v-if="(a.topPerformers ?? []).length === 0" class="py-8 text-center text-[12px] text-on-surface-variant/60 italic">
                            No resolution data yet.
                        </div>

                        <div v-else class="canvas-scroll max-h-[300px] overflow-y-auto space-y-2.5 -mr-2 pr-2">
                            <div
                                v-for="(emp, i) in a.topPerformers"
                                :key="emp.id"
                                class="group flex items-center gap-3 rounded-xl bg-surface-container-low/50 p-2.5 hover:bg-secondary/[0.05] transition-colors"
                            >
                                <div class="relative flex-shrink-0">
                                    <div
                                        class="flex h-9 w-9 items-center justify-center rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm text-[12px] font-black text-white transition-transform group-hover:scale-105"
                                        :style="`background:${['linear-gradient(135deg,#0d1452,#1a237e)','linear-gradient(135deg,#1a237e,#7986cb)','linear-gradient(135deg,#070b3a,#0d1452)','linear-gradient(135deg,#0d1452,#1a237e,#d912e3)','linear-gradient(135deg,#1a237e,#12d9e3)'][i % 5]}`"
                                    >
                                        {{ emp.name?.charAt(0) ?? '?' }}
                                    </div>
                                    <!-- Top 3 rank dot: gold for #1 (5% accent), silver/bronze for #2/#3 -->
                                    <span
                                        v-if="i < 3"
                                        class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full text-[8px] font-black shadow-sm ring-2 ring-white dark:ring-surface-container-lowest"
                                        :style="`background:${['#ffd700','#94a3b8','#b88a08'][i]};color:${i === 0 ? '#7a5400' : '#fff'}`"
                                    >{{ i + 1 }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[12px] font-bold text-on-surface truncate">{{ emp.name }}</p>
                                    <p class="text-[10px] text-on-surface-variant/70 truncate">{{ emp.position ?? '—' }}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-[15px] font-black text-secondary tabular-nums">{{ emp.resolved }}</p>
                                    <p class="text-[9px] uppercase tracking-[0.12em] font-black text-on-surface-variant/60">closed</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- â”€â”€ Row 4: Ticket trend + Leave volume side by side â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="grid gap-6 lg:grid-cols-2">

                    <!-- Ticket trend -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-[14px] font-bold text-on-surface">Ticket Volume Trend</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">Service desk activity, monthly</p>
                            </div>
                            <Link :href="route('tickets.index')" class="text-[11px] font-semibold text-secondary hover:underline">
                                View tickets â†’
                            </Link>
                        </div>

                        <svg :viewBox="`0 0 ${w} 180`" preserveAspectRatio="xMidYMid meet" class="w-full h-[180px]">
                            <defs>
                                <linearGradient id="ticketFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#0e8a93" stop-opacity="0.30"/>
                                    <stop offset="100%" stop-color="#0e8a93" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <g stroke="currentColor" class="text-outline-variant/40" stroke-dasharray="3 4">
                                <line :x1="pad" y1="20" :x2="w - pad" y2="20" />
                                <line :x1="pad" y1="100" :x2="w - pad" y2="100" />
                                <line :x1="pad" y1="160" :x2="w - pad" y2="160" />
                            </g>

                            <g v-if="(a.ticketTrend ?? []).length">
                                <path
                                    :d="(() => {
                                        const data = a.ticketTrend;
                                        const max = Math.max(...data.map(d => d.value), 1);
                                        const stepX = (w - pad * 2) / Math.max(data.length - 1, 1);
                                        const innerH = 160 - 20;
                                        const top = data.map((d, i) => {
                                            const x = pad + i * stepX;
                                            const y = 160 - (d.value / max) * innerH;
                                            return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
                                        }).join(' ');
                                        return `${top} L ${(w - pad).toFixed(1)} 160 L ${pad.toFixed(1)} 160 Z`;
                                    })()"
                                    fill="url(#ticketFill)"
                                />
                                <path
                                    :d="(() => {
                                        const data = a.ticketTrend;
                                        const max = Math.max(...data.map(d => d.value), 1);
                                        const stepX = (w - pad * 2) / Math.max(data.length - 1, 1);
                                        const innerH = 160 - 20;
                                        return data.map((d, i) => {
                                            const x = pad + i * stepX;
                                            const y = 160 - (d.value / max) * innerH;
                                            return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
                                        }).join(' ');
                                    })()"
                                    fill="none" stroke="#0e8a93" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                />
                            </g>

                            <g v-for="(p, i) in a.ticketTrend ?? []" :key="i">
                                <text :x="pad + i * ((w - pad * 2) / Math.max((a.ticketTrend?.length ?? 1) - 1, 1))" y="175" class="fill-current text-on-surface-variant" text-anchor="middle" font-size="9.5" font-weight="600">{{ p.label }}</text>
                            </g>
                        </svg>
                    </div>

                    <!-- Leave volume trend -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-[14px] font-bold text-on-surface">Approved Leave Volume</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">Monthly requests, trailing 12 months</p>
                            </div>
                            <Link :href="route('leave.index')" class="text-[11px] font-semibold text-secondary hover:underline">
                                View leave â†’
                            </Link>
                        </div>

                        <LiveBars
                            :data="(a.leaveByMonth ?? []).map(p => ({ label: p.label, value: p.value }))"
                            :height="160"
                            color="#1a237e"
                            accent-color="#ffd700"
                            second-color="#12d9e3"
                            :show-median="true"
                            :rounded="6"
                        />
                    </div>
                </div>
            </div>

    </div>
</template>

<style scoped>
.live-dot {
    animation: liveDot 1.6s ease-in-out infinite;
}
@keyframes liveDot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0.3; transform: scale(0.75); }
}

.canvas-scroll::-webkit-scrollbar { width: 8px; }
.canvas-scroll::-webkit-scrollbar-track { background: transparent; }
.canvas-scroll::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, 0.25);
    border-radius: 8px;
    border: 2px solid transparent;
    background-clip: padding-box;
}
.canvas-scroll::-webkit-scrollbar-thumb:hover {
    background-color: rgba(100, 116, 139, 0.45);
    background-clip: padding-box;
}
</style>
