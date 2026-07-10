<script setup>
import { computed, ref, reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import SearchInput from '@/Components/SearchInput.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    matrix:       Object, // { employees:[], skills:[], matrix:{} }
    departments:  Array,  // optional: [{ id, name }] for dept filter
    activeModule: String,
});

// ── Data ──────────────────────────────────────────────────────────────────────
const employees = computed(() => props.matrix?.employees ?? []);
const skills    = computed(() => props.matrix?.skills    ?? []);
const matrix    = computed(() => props.matrix?.matrix    ?? {});

// ── Filters ───────────────────────────────────────────────────────────────────
const localFilters = reactive({
    search:      '',
    department:  '',
    skillSearch: '',
    gapsOnly:    false,
});

const filteredEmployees = computed(() => {
    let list = employees.value;
    const q = localFilters.search.trim().toLowerCase();
    if (q) {
        list = list.filter(e =>
            (e.name ?? '').toLowerCase().includes(q)
            || (e.position ?? '').toLowerCase().includes(q)
            || (e.department ?? '').toLowerCase().includes(q),
        );
    }
    if (localFilters.department) {
        list = list.filter(e => (e.department ?? '') === localFilters.department);
    }
    if (localFilters.gapsOnly) {
        list = list.filter(e => (e.skill_count ?? 0) < 3); // employees with < 3 skills flagged
    }
    return list;
});

const filteredSkills = computed(() => {
    let list = showOnlyTopSkills.value ? skills.value.slice(0, 15) : skills.value;
    const q = localFilters.skillSearch.trim().toLowerCase();
    if (q) list = list.filter(s => (s.name ?? '').toLowerCase().includes(q));
    return list;
});

const allDepartments = computed(() => {
    const depts = new Set(employees.value.map(e => e.department).filter(Boolean));
    return [...depts].sort();
});

const showOnlyTopSkills = ref(true);

// ── Stats ─────────────────────────────────────────────────────────────────────
const coveragePct = computed(() => {
    if (!employees.value.length) return 0;
    const covered = employees.value.filter(e => (e.skill_count ?? 0) > 0).length;
    return Math.round((covered / employees.value.length) * 100);
});

const gapCount = computed(() =>
    employees.value.filter(e => (e.skill_count ?? 0) === 0).length,
);

const criticalCoverage = computed(() => {
    // Skills where coverage is < 30% of total employees are "critical"
    if (!skills.value.length || !employees.value.length) return 0;
    const critCount = skills.value.filter(s => (s.count ?? 0) / employees.value.length < 0.3).length;
    return skills.value.length > 0 ? Math.round(((skills.value.length - critCount) / skills.value.length) * 100) : 0;
});

// ── Add Skill SlidePanel ──────────────────────────────────────────────────────
const showAddSkill = ref(false);
const skillForm = useForm({
    name:     '',
    category: '',
    description: '',
});

const submitSkill = () => {
    skillForm.post(route('learning.skills.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showAddSkill.value = false;
            skillForm.reset();
        },
    });
};

// ── Cell level rendering ──────────────────────────────────────────────────────
// Numeric level 1-5 (from skills relation) → colour intensity using cobalt
const numericLevel = (val) => {
    // Accept both string labels and numbers
    const map = { beginner: 1, novice: 1, intermediate: 2, proficient: 3, advanced: 4, expert: 5 };
    if (typeof val === 'number') return Math.min(5, Math.max(1, val));
    return map[String(val).toLowerCase()] ?? 1;
};

const cellStyle = (val) => {
    if (!val) return null;
    const level = numericLevel(val);
    const intensities = [
        'rgba(26, 35, 126,0.10)',  // 1 - barely visible
        'rgba(26, 35, 126,0.25)',  // 2
        'rgba(26, 35, 126,0.50)',  // 3
        'rgba(26, 35, 126,0.75)',  // 4
        'rgba(26, 35, 126,0.95)',  // 5 - full cobalt
    ];
    const textColors = [
        '#1a237e',
        '#1a237e',
        '#1a237e',
        '#eff6ff',
        '#ffffff',
    ];
    return {
        bg: intensities[level - 1],
        fg: textColors[level - 1],
        level,
    };
};

// Legacy string-abbr approach for backward compat
const levelCell = (level) => {
    const map = {
        beginner:     { bg: 'rgba(217,119,6,0.20)',   fg: '#92400e',   abbr: 'B', num: 1 },
        intermediate: { bg: 'rgba(26, 35, 126,0.20)',    fg: '#1e3a8a',   abbr: 'I', num: 2 },
        advanced:     { bg: 'rgba(124,58,237,0.20)',  fg: '#5b21b6',   abbr: 'A', num: 3 },
        expert:       { bg: 'rgba(5,150,105,0.25)',   fg: '#064e3b',   abbr: 'E', num: 4 },
    };
    return map[level] ?? null;
};

// Choose correct renderer based on what value the matrix holds
const renderCell = (val) => {
    if (!val) return null;
    // If it's one of the known string keys use legacy
    const legacy = levelCell(String(val).toLowerCase());
    if (legacy) return { bg: legacy.bg, fg: legacy.fg, label: legacy.abbr, title: val };
    // Otherwise use numeric intensity
    const c = cellStyle(val);
    if (!c) return null;
    return { bg: c.bg, fg: c.fg, label: String(c.level), title: val };
};

// ── Export (placeholder) ──────────────────────────────────────────────────────
const exportMatrix = () => {
    // Build CSV
    const headers = ['Employee', 'Department', 'Position', ...filteredSkills.value.map(s => s.name), 'Total Skills'];
    const rows = filteredEmployees.value.map(e => [
        e.name,
        e.department ?? '',
        e.position ?? '',
        ...filteredSkills.value.map(s => matrix.value[e.id]?.[s.name] ?? ''),
        e.skill_count ?? 0,
    ]);
    const csv = [headers, ...rows].map(r => r.map(v => `"${v}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'skills-matrix.csv';
    a.click();
    URL.revokeObjectURL(url);
};
</script>

<template>
    <Head title="Skills Matrix" />
    <div data-page-root="true">
            <!-- ── Header ─────────────────────────────────────────────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                            <Link :href="route('learning.catalog')" class="hover:text-secondary transition-colors">Learning</Link>
                            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                            <span>Skills Matrix</span>
                        </div>
                        <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Skills Matrix</h2>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Organisation-wide skill coverage heatmap. Identify gaps and plan targeted upskilling.
                        </p>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <button
                            @click="exportMatrix"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                        >
                            <span class="material-symbols-outlined text-[18px]">download</span>
                            Export CSV
                        </button>
                        <button
                            @click="showAddSkill = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Add Skill
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6 animate-reveal-up">

                <!-- ── Stat cards ─────────────────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div
                        v-for="(s, i) in [
                            { label: 'Skills Tracked',        value: skills.length,            icon: 'psychology',    color: '124,92,255' },
                            { label: 'Critical-skill Coverage',value: `${criticalCoverage}%`,  icon: 'verified_user', color: '5,150,105'  },
                            { label: 'Employees Tracked',     value: employees.length,          icon: 'people',        color: '26, 35, 126'   },
                            { label: 'Skill Gaps (0 skills)', value: gapCount,                 icon: 'warning',       color: '217,119,6'  },
                        ]"
                        :key="s.label"
                        class="rounded-2xl border bg-surface-container-lowest p-5 shadow-card card-lift"
                        :style="`border-color:rgba(${s.color},0.25);animation-delay:${i * 0.06}s`"
                    >
                        <div
                            class="h-10 w-10 rounded-xl flex items-center justify-center"
                            :style="`background:rgba(${s.color},0.12)`"
                        >
                            <span class="material-symbols-outlined text-[20px]" :style="`color:rgb(${s.color})`" style="font-variation-settings:'FILL' 1">{{ s.icon }}</span>
                        </div>
                        <p class="mt-4 text-[28px] font-black text-on-surface tracking-tight leading-none">{{ s.value }}</p>
                        <p class="mt-1.5 text-[12px] font-semibold text-on-surface-variant">{{ s.label }}</p>
                    </div>
                </div>

                <!-- ── Filter strip ───────────────────────────────────────────── -->
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[200px] max-w-sm">
                        <SearchInput v-model="localFilters.search" placeholder="Search employees by name, role…" />
                    </div>

                    <select aria-label="Department"
                        v-if="allDepartments.length"
                        v-model="localFilters.department"
                        class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Departments</option>
                        <option v-for="d in allDepartments" :key="d" :value="d">{{ d }}</option>
                    </select>

                    <div class="flex-1 min-w-[160px] max-w-xs">
                        <SearchInput v-model="localFilters.skillSearch" placeholder="Filter skills…" />
                    </div>

                    <!-- Show only top 15 toggle -->
                    <label class="flex items-center gap-2 rounded-xl border border-outline-variant/60 bg-surface-container-low px-3.5 py-2.5 text-[13px] cursor-pointer">
                        <input
                            type="checkbox"
                            v-model="showOnlyTopSkills"
                            class="h-3.5 w-3.5 accent-secondary"
                        />
                        <span class="font-semibold text-on-surface-variant">Top 15 skills</span>
                    </label>

                    <!-- Gaps-only toggle -->
                    <label class="flex items-center gap-2 rounded-xl border border-outline-variant/60 bg-surface-container-low px-3.5 py-2.5 text-[13px] cursor-pointer">
                        <input
                            type="checkbox"
                            v-model="localFilters.gapsOnly"
                            class="h-3.5 w-3.5 accent-amber-500"
                        />
                        <span class="font-semibold text-on-surface-variant">Show gaps only</span>
                    </label>

                    <button
                        v-if="localFilters.search || localFilters.department || localFilters.gapsOnly || localFilters.skillSearch"
                        @click="() => { localFilters.search = ''; localFilters.department = ''; localFilters.gapsOnly = false; localFilters.skillSearch = ''; }"
                        class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                    >
                        <span class="material-symbols-outlined text-[16px]">close</span>
                        Clear
                    </button>
                </div>

                <!-- ── Matrix + Legend layout ─────────────────────────────────── -->
                <div class="flex gap-5 items-start">

                    <!-- Matrix table -->
                    <div class="flex-1 min-w-0">
                        <div v-if="employees.length && skills.length"
                             class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden shadow-card">

                            <div class="overflow-x-auto">
                                <table class="w-full text-[12px]">
                                    <thead class="bg-surface-container-low/60 sticky top-0 z-20">
                                        <tr>
                                            <!-- Sticky employee column -->
                                            <th class="sticky left-0 z-30 bg-surface-container-low/90 backdrop-blur-sm px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.10em] text-on-surface-variant/70 min-w-[220px] border-r border-outline-variant/30">
                                                Employee
                                            </th>

                                            <!-- Skill columns — rotated headers -->
                                            <th
                                                v-for="s in filteredSkills"
                                                :key="s.name"
                                                class="px-1.5 py-3 text-center align-bottom whitespace-nowrap"
                                                :title="`${s.name} — ${s.count ?? 0} employee(s)`"
                                            >
                                                <div class="inline-block rotate-[-40deg] origin-bottom-left pb-1">
                                                    <span class="text-[10px] font-black uppercase tracking-[0.06em] text-on-surface-variant/70">{{ s.name }}</span>
                                                </div>
                                                <div class="text-[9px] text-on-surface-variant/40 mt-0.5 normal-case font-normal">{{ s.count ?? 0 }}</div>
                                            </th>

                                            <!-- Total skills column -->
                                            <th class="sticky right-0 bg-surface-container-low/90 backdrop-blur-sm px-3 py-3 text-right text-[10px] font-black uppercase tracking-[0.10em] text-on-surface-variant/70 border-l border-outline-variant/30">
                                                Total
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-outline-variant/25">
                                        <tr
                                            v-for="emp in filteredEmployees"
                                            :key="emp.id"
                                            class="hover:bg-surface-container/40 transition-colors"
                                            :class="{ 'bg-amber-50/40 dark:bg-amber-900/10': localFilters.gapsOnly || (emp.skill_count ?? 0) === 0 }"
                                        >
                                            <!-- Sticky employee info -->
                                            <td class="sticky left-0 z-10 bg-surface-container-lowest px-4 py-2.5 border-r border-outline-variant/20 min-w-[220px]">
                                                <p class="font-bold text-on-surface text-[12.5px] leading-tight">{{ emp.name }}</p>
                                                <p class="text-[10.5px] text-on-surface-variant/60 truncate">
                                                    {{ emp.position ?? '' }}
                                                    <span v-if="emp.department" class="text-on-surface-variant/40"> · {{ emp.department }}</span>
                                                </p>
                                            </td>

                                            <!-- Skill cells -->
                                            <td
                                                v-for="s in filteredSkills"
                                                :key="s.name"
                                                class="px-1.5 py-2 text-center"
                                            >
                                                <div v-if="renderCell(matrix[emp.id]?.[s.name])">
                                                    <span
                                                        class="inline-flex h-6 w-6 items-center justify-center rounded-md text-[10px] font-black transition-transform hover:scale-110"
                                                        :style="`background:${renderCell(matrix[emp.id][s.name]).bg};color:${renderCell(matrix[emp.id][s.name]).fg}`"
                                                        :title="`${emp.name} · ${s.name}: ${renderCell(matrix[emp.id][s.name]).title}`"
                                                    >{{ renderCell(matrix[emp.id][s.name]).label }}</span>
                                                </div>
                                                <span v-else class="text-on-surface-variant/15 text-[14px]">·</span>
                                            </td>

                                            <!-- Total skills (sticky right) -->
                                            <td class="sticky right-0 bg-surface-container-lowest px-3 py-2 text-right border-l border-outline-variant/20">
                                                <span
                                                    class="inline-flex items-center justify-center h-6 min-w-[24px] rounded-lg text-[11px] font-black"
                                                    :class="(emp.skill_count ?? 0) === 0
                                                        ? 'bg-amber-500/10 text-amber-700'
                                                        : (emp.skill_count ?? 0) >= 5
                                                            ? 'bg-emerald-500/10 text-emerald-700'
                                                            : 'bg-secondary/10 text-secondary'"
                                                >{{ emp.skill_count ?? 0 }}</span>
                                            </td>
                                        </tr>

                                        <!-- No results row -->
                                        <tr v-if="filteredEmployees.length === 0">
                                            <td :colspan="filteredSkills.length + 2" class="px-4 py-10 text-center">
                                                <span class="material-symbols-outlined text-[32px] text-on-surface-variant/30">search_off</span>
                                                <p class="mt-2 text-[13px] font-semibold text-on-surface-variant">No employees match your filters</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Table footer -->
                            <div class="flex items-center justify-between border-t border-outline-variant/40 px-5 py-3 bg-surface-container/30">
                                <p class="text-[11px] text-on-surface-variant/70">
                                    Showing <span class="font-bold text-on-surface">{{ filteredEmployees.length }}</span> of
                                    <span class="font-bold text-on-surface">{{ employees.length }}</span> employees ·
                                    <span class="font-bold text-on-surface">{{ filteredSkills.length }}</span> skills displayed
                                </p>
                                <button
                                    @click="exportMatrix"
                                    class="flex items-center gap-1.5 text-[11px] font-semibold text-on-surface-variant hover:text-secondary transition-colors"
                                >
                                    <span class="material-symbols-outlined text-[14px]">download</span>
                                    Export CSV
                                </button>
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div v-else class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-12">
                            <EmptyState
                                title="No skills tracked yet"
                                description="Skills are added when employees record them on their profile or complete tagged courses. Add a skill to the catalogue to get started."
                                icon="grid_on"
                            >
                                <template #action>
                                    <button
                                        @click="showAddSkill = true"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                    >
                                        <span class="material-symbols-outlined text-[18px]">add</span>
                                        Add First Skill
                                    </button>
                                </template>
                            </EmptyState>
                        </div>
                    </div>

                    <!-- ── Legend sidebar ─────────────────────────────────────── -->
                    <aside class="flex-shrink-0 w-52 space-y-4 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card sticky top-6">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Proficiency Scale</p>

                        <div class="space-y-2">
                            <div v-for="(item, level) in {
                                'Not assessed':  { bg: 'rgba(100,116,139,0.08)', fg: '#94a3b8', dot: '·' },
                                'Level 1 — Novice':       { bg: 'rgba(26, 35, 126,0.10)', fg: '#1a237e', dot: '1' },
                                'Level 2 — Developing':   { bg: 'rgba(26, 35, 126,0.25)', fg: '#1a237e', dot: '2' },
                                'Level 3 — Proficient':   { bg: 'rgba(26, 35, 126,0.50)', fg: '#1a237e', dot: '3' },
                                'Level 4 — Advanced':     { bg: 'rgba(26, 35, 126,0.75)', fg: '#eff6ff', dot: '4' },
                                'Level 5 — Expert':       { bg: 'rgba(26, 35, 126,0.95)', fg: '#ffffff', dot: '5' },
                            }" :key="level"
                                 class="flex items-center gap-2.5">
                                <span
                                    class="inline-flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-md text-[11px] font-black"
                                    :style="`background:${item.bg};color:${item.fg}`"
                                >{{ item.dot }}</span>
                                <span class="text-[11px] text-on-surface-variant leading-tight">{{ level }}</span>
                            </div>
                        </div>

                        <div class="border-t border-outline-variant/40 pt-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60 mb-2">Legacy labels</p>
                            <div class="space-y-1.5">
                                <div v-for="(meta, label) in {
                                    Beginner:     { bg: 'rgba(217,119,6,0.20)',  fg: '#92400e' },
                                    Intermediate: { bg: 'rgba(26, 35, 126,0.20)',   fg: '#1e3a8a' },
                                    Advanced:     { bg: 'rgba(124,58,237,0.20)', fg: '#5b21b6' },
                                    Expert:       { bg: 'rgba(5,150,105,0.25)',  fg: '#064e3b' },
                                }" :key="label"
                                     class="flex items-center gap-2">
                                    <span
                                        class="inline-flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-md text-[10px] font-black"
                                        :style="`background:${meta.bg};color:${meta.fg}`"
                                    >{{ label[0] }}</span>
                                    <span class="text-[11px] text-on-surface-variant">{{ label }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-outline-variant/40 pt-3">
                            <button
                                @click="exportMatrix"
                                class="w-full flex items-center justify-center gap-2 rounded-xl border border-outline-variant/60 px-3 py-2 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors"
                            >
                                <span class="material-symbols-outlined text-[14px]">download</span>
                                Export Matrix
                            </button>
                            <p class="mt-1.5 text-[10px] text-on-surface-variant/50 text-center">Downloads as CSV</p>
                        </div>
                    </aside>
                </div>

                <!-- ── Coverage summary bar ───────────────────────────────────── -->
                <div v-if="employees.length" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Overall Skill Coverage</p>
                            <p class="text-[12px] text-on-surface-variant mt-0.5">Employees with at least one skill recorded</p>
                        </div>
                        <span class="text-[28px] font-black text-on-surface">{{ coveragePct }}%</span>
                    </div>
                    <div class="h-3 rounded-full bg-surface-container overflow-hidden">
                        <div
                            class="h-full rounded-full transition-all duration-700"
                            :style="`width:${coveragePct}%;background:linear-gradient(90deg,#1a237e,#3949ab)`"
                        ></div>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-[11px] text-on-surface-variant/60">
                        <span>{{ employees.filter(e => (e.skill_count ?? 0) > 0).length }} mapped</span>
                        <span>{{ gapCount }} with no skills</span>
                    </div>
                </div>

            </div>

            <!-- ── Add Skill to Catalogue SlidePanel ─────────────────────────── -->
            <SlidePanel
                :open="showAddSkill"
                title="Add Skill to Catalogue"
                size="md"
                @close="showAddSkill = false"
            >
                <form @submit.prevent="submitSkill" class="space-y-5 p-6">

                    <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 flex items-start gap-2.5">
                        <span class="material-symbols-outlined text-[18px] text-amber-600 flex-shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1">info</span>
                        <p class="text-[12px] text-amber-700">
                            Skills added here will appear in the Skills Matrix and on employee profiles. Employees can self-assess their level when updating their profile.
                        </p>
                    </div>

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Skill Name <span class="text-red-500">*</span></label>
                        <input aria-label="Skill Name"
                            v-model="skillForm.name"
                            type="text"
                            required
                            maxlength="100"
                            placeholder="e.g. Financial Reporting, Python, Procurement"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': skillForm.errors?.name }"
                        />
                        <p v-if="skillForm.errors?.name" class="mt-1 text-[11px] text-red-500">{{ skillForm.errors.name }}</p>
                    </div>

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Category</label>
                        <select aria-label="Category"
                            v-model="skillForm.category"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="">No category</option>
                            <option value="technical">Technical</option>
                            <option value="leadership">Leadership</option>
                            <option value="compliance">Compliance</option>
                            <option value="soft_skills">Soft Skills</option>
                            <option value="domain">Domain Knowledge</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description</label>
                        <textarea aria-label="Description"
                            v-model="skillForm.description"
                            rows="3"
                            placeholder="Brief description of this skill and how it's assessed…"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                        />
                    </div>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="showAddSkill = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        >Cancel</button>
                        <button
                            @click="submitSkill"
                            :disabled="skillForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="skillForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            Add Skill
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>
