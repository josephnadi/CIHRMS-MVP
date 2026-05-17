<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    reportTypes:  Array,
    previews:     Object,
    activeModule: String,
});

const selected = ref(props.reportTypes?.[0]?.key ?? null);
const year     = ref(new Date().getFullYear());
const month    = ref(new Date().toISOString().slice(0, 7));

const iconFor = {
    headcount: 'groups',
    leave:     'beach_access',
    payroll:   'payments',
    tickets:   'confirmation_number',
    turnover:  'trending_down',
};

// Reports lives in the sidebar's flagship gold tier — Headcount is the
// headline report (institutional census), so it gets the 5% gold accent.
// Other reports keep their source-module semantic colors.
const accentFor = {
    headcount: '#b88a08',      // gold-deep (readable on light bg) — flagship
    leave:     '#059669',      // green (approved-leave semantic)
    payroll:   '#1a237e',      // cobalt (payroll module)
    tickets:   '#dc2626',      // red (service-desk alarm semantic)
    turnover:  '#d97706',      // amber (warning semantic)
};

const gradientFor = (key) => {
    const map = {
        headcount: 'linear-gradient(135deg,#b88a08,#ffd700)',  // gold deep → gold (flagship)
        leave:     'linear-gradient(135deg,#059669,#10b981)',
        payroll:   'linear-gradient(135deg,#0d1452,#1a237e)',  // navy → cobalt
        tickets:   'linear-gradient(135deg,#dc2626,#ef4444)',
        turnover:  'linear-gradient(135deg,#d97706,#f59e0b)',
    };
    return map[key] ?? 'linear-gradient(135deg,#0d1452,#1a237e)';
};

const exportUrl = computed(() => {
    if (!selected.value) return '';
    const params = new URLSearchParams({ type: selected.value });
    if (selected.value === 'leave')   params.set('year',  year.value);
    if (selected.value === 'payroll') params.set('month', month.value);
    return `${route('reports.export')}?${params.toString()}`;
});

const yearOptions = computed(() => {
    const cur = new Date().getFullYear();
    return [cur, cur - 1, cur - 2, cur - 3];
});

const previewFor = (key) => props.previews?.[key] ?? { metric: 0, metric_label: '', series: [] };

const seriesMax = (series) => Math.max(...(series ?? []).map(d => d.value ?? 0), 1);

const sparkPath = (series, width = 200, height = 50) => {
    const data = series ?? [];
    if (!data.length) return '';
    const max = seriesMax(data);
    const step = data.length > 1 ? width / (data.length - 1) : width;
    return data.map((d, i) => {
        const x = i * step;
        const y = height - (d.value / max) * (height - 4) - 2;
        return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
    }).join(' ');
};

const sparkAreaPath = (series, width = 200, height = 50) => {
    const line = sparkPath(series, width, height);
    if (!line) return '';
    return `${line} L ${width} ${height} L 0 ${height} Z`;
};

const formatMetric = (key, value) => {
    if (key === 'payroll') {
        return 'GHS ' + Number(value ?? 0).toLocaleString('en-GH', { maximumFractionDigits: 0 });
    }
    return Number(value ?? 0).toLocaleString('en-GH');
};

const selectedPreview = computed(() => selected.value ? previewFor(selected.value) : null);
const selectedReport = computed(() => props.reportTypes?.find(r => r.key === selected.value));

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
        month:   d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }),
        time:    d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
    };
});

// Distinct report categories — the source-module families surfaced as exports.
const reportCategories = computed(() => {
    const cats = new Set((props.reportTypes ?? []).map(r => r.key));
    return cats.size;
});
</script>

<template>
    <Head title="Reports" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="space-y-6">
                <!-- ─── Masthead strip ────────────────────────────────────── -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">REPORTS LEDGER</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · Filed on demand
                    </span>
                </div>

                <!-- ─── Broadsheet hero ───────────────────────────────────── -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Statutory &amp; operational reporting</p>
                        <h2 class="es-display text-[clamp(2.4rem,5.5vw,4.6rem)]">
                            Institutional reports,
                            <span class="es-display-italic block">filed.</span>
                        </h2>
                        <p class="es-display-sub">
                            The institutional reporting cadence — statutory returns lodged to the Public Services Commission,
                            the Auditor-General's annual pack, and ministerial briefing dashboards — compiled from live HR data
                            and exported as audit-trail XLSX on request.
                        </p>

                        <!-- Quick-action chips — most-used statutory exports -->
                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <button
                                v-for="(r, i) in (reportTypes ?? []).slice(0, 3)"
                                :key="r.key"
                                @click="selected = r.key"
                                class="es-chip"
                            >
                                <span class="material-symbols-outlined text-[15px]">{{ iconFor[r.key] ?? 'description' }}</span>
                                {{ r.label }}
                                <span v-if="i < Math.min(2, (reportTypes?.length ?? 0) - 1)" class="text-on-surface-variant/30 ml-4">·</span>
                            </button>
                        </div>
                    </div>

                    <!-- SIDEBAR column: feature KPI as magazine drop-cap stat -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Exports Available</p>
                            <p class="es-stat-hero-value">{{ (reportTypes?.length ?? 0).toString().padStart(2, '0') }}</p>
                            <p class="es-stat-hero-caption">
                                Statutory &amp; operational return{{ (reportTypes?.length ?? 0) === 1 ? '' : 's' }} on the gazette
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">verified</span>
                                Auditor-General pack ready
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ─── Stat strip ────────────────────────────────────────── -->
                <div class="es-stat-strip" v-if="(reportTypes?.length ?? 0) > 0">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Total reports</p>
                        <p class="es-stat-cell-value">{{ (reportTypes?.length ?? 0).toString().padStart(2, '0') }}</p>
                        <p class="es-stat-cell-caption">Returns on file</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Categories</p>
                        <p class="es-stat-cell-value">{{ reportCategories.toString().padStart(2, '0') }}</p>
                        <p class="es-stat-cell-caption">Source-module families</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Filing window</p>
                        <p class="es-stat-cell-value-sm">{{ editionLabel.month }}</p>
                        <p class="es-stat-cell-caption">Live data · generated on demand</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Last refresh</p>
                        <p class="es-stat-cell-value-sm">{{ editionLabel.time }}</p>
                        <p class="es-stat-cell-caption">Server-compiled this session</p>
                    </div>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- â”€â”€ Report cards with live preview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="report in reportTypes"
                    :key="report.key"
                    @click="selected = report.key"
                    :class="[
                        'group text-left rounded-2xl border bg-surface-container-lowest shadow-card p-5 transition-all hover:shadow-lifted hover:-translate-y-0.5 overflow-hidden relative',
                        selected === report.key
                            ? 'border-secondary ring-2 ring-secondary/20'
                            : 'border-outline-variant/50',
                    ]"
                >
                    <!-- Top accent bar -->
                    <div class="absolute top-0 left-0 right-0 h-1" :style="`background:${gradientFor(report.key)}`"></div>

                    <div class="flex items-start justify-between mb-3 mt-1">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl"
                            :style="`background:${accentFor[report.key]}1a;color:${accentFor[report.key]}`"
                        >
                            <span class="material-symbols-outlined text-[22px]" style="font-variation-settings:'FILL' 1">{{ iconFor[report.key] }}</span>
                        </div>
                        <span
                            v-if="selected === report.key"
                            class="flex h-5 w-5 items-center justify-center rounded-full bg-secondary text-white"
                        >
                            <span class="material-symbols-outlined text-[14px]">check</span>
                        </span>
                    </div>

                    <h3 class="text-[14px] font-bold text-on-surface leading-snug mb-1">{{ report.label }}</h3>
                    <p class="text-[11px] text-on-surface-variant leading-relaxed mb-4 line-clamp-2 min-h-[28px]">{{ report.description }}</p>

                    <!-- Preview metric + sparkline -->
                    <div class="flex items-end justify-between gap-3 pt-3 border-t border-outline-variant/40">
                        <div>
                            <p class="text-[9px] font-black uppercase tracking-[0.15em] text-on-surface-variant/60">{{ previewFor(report.key).metric_label }}</p>
                            <p class="mt-1 text-[20px] font-black font-mono leading-none" :style="`color:${accentFor[report.key]}`">
                                {{ formatMetric(report.key, previewFor(report.key).metric) }}
                            </p>
                        </div>

                        <svg
                            v-if="(previewFor(report.key).series ?? []).length > 1"
                            viewBox="0 0 200 50" preserveAspectRatio="none"
                            class="h-[44px] w-[120px] flex-shrink-0 -mb-1"
                        >
                            <defs>
                                <linearGradient :id="`spark-${report.key}`" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" :stop-color="accentFor[report.key]" stop-opacity="0.4"/>
                                    <stop offset="100%" :stop-color="accentFor[report.key]" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <path :d="sparkAreaPath(previewFor(report.key).series)" :fill="`url(#spark-${report.key})`" />
                            <path
                                :d="sparkPath(previewFor(report.key).series)"
                                fill="none"
                                :stroke="accentFor[report.key]"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>

                        <!-- Tiny bar chart fallback if 1 or fewer points -->
                        <div v-else-if="(previewFor(report.key).series ?? []).length === 1" class="flex items-end gap-1 h-10">
                            <div
                                class="w-3 rounded-t"
                                :style="`height:80%;background:${accentFor[report.key]}`"
                            ></div>
                        </div>
                    </div>
                </button>
            </div>

            <!-- â”€â”€ Detailed preview + export panel for selected report â”€â”€â”€â”€â”€ -->
            <div v-if="selectedReport" class="grid gap-4 lg:grid-cols-3">

                <!-- Visual preview -->
                <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-xl text-white"
                                :style="`background:${gradientFor(selected)}`"
                            >
                                <span class="material-symbols-outlined text-[22px]" style="font-variation-settings:'FILL' 1">{{ iconFor[selected] }}</span>
                            </div>
                            <div>
                                <h3 class="text-[16px] font-bold text-on-surface">{{ selectedReport.label }}</h3>
                                <p class="text-[11px] text-on-surface-variant">Preview Â· top breakdown</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black uppercase tracking-[0.15em] text-on-surface-variant/60">{{ selectedPreview?.metric_label }}</p>
                            <p class="mt-0.5 text-[22px] font-black font-mono" :style="`color:${accentFor[selected]}`">
                                {{ formatMetric(selected, selectedPreview?.metric) }}
                            </p>
                        </div>
                    </div>

                    <div v-if="!(selectedPreview?.series ?? []).length" class="py-12 text-center text-[12px] text-on-surface-variant/60 italic">
                        No data available for preview.
                    </div>

                    <div v-else class="space-y-2.5">
                        <div
                            v-for="row in selectedPreview.series"
                            :key="row.label"
                            class="grid grid-cols-12 items-center gap-3"
                        >
                            <div class="col-span-3">
                                <p class="text-[12px] font-semibold text-on-surface truncate">{{ row.label }}</p>
                            </div>
                            <div class="col-span-7 h-6 rounded-md bg-surface-container-low overflow-hidden relative">
                                <div
                                    class="h-full rounded-md transition-all"
                                    :style="`width:${(row.value / seriesMax(selectedPreview.series)) * 100}%;background:${gradientFor(selected)};transition-duration:0.8s`"
                                ></div>
                            </div>
                            <div class="col-span-2 text-right">
                                <span class="text-[13px] font-black font-mono tabular-nums" :style="`color:${accentFor[selected]}`">
                                    {{ formatMetric(selected, row.value) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export panel -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 flex flex-col">
                    <h3 class="text-[14px] font-bold text-on-surface mb-1">Configure Export</h3>
                    <p class="text-[11px] text-on-surface-variant mb-5">XLSX, generated from live data</p>

                    <div class="space-y-3 flex-1">
                        <div v-if="selected === 'leave'">
                            <label class="text-[11px] font-semibold text-on-surface-variant mb-1.5 block">Year</label>
                            <select
                                v-model="year"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10"
                            >
                                <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                            </select>
                        </div>

                        <div v-if="selected === 'payroll'">
                            <label class="text-[11px] font-semibold text-on-surface-variant mb-1.5 block">Month</label>
                            <input
                                v-model="month"
                                type="month"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10"
                            />
                        </div>

                        <div v-if="!['leave','payroll'].includes(selected)" class="text-[11px] text-on-surface-variant/60 italic py-2">
                            This report has no additional filters. Export captures the full current dataset.
                        </div>
                    </div>

                    <a
                        :href="exportUrl"
                        class="btn-shimmer mt-5 flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow"
                        :style="`background:${gradientFor(selected)}`"
                    >
                        <span class="material-symbols-outlined text-[18px]">download</span>
                        Download {{ selectedReport.label }}
                    </a>

                    <p class="mt-3 text-[10px] text-on-surface-variant/60 text-center flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[12px]">info</span>
                        Generated server-side. Large reports may take a moment.
                    </p>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
