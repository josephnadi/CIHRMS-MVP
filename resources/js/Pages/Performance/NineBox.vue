<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    matrix:       Object,
    cycles:       Object,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

const cycleList = computed(() => props.cycles?.data ?? props.cycles ?? []);

const selectedCycle = ref(props.matrix?.cycle?.id ?? '');

watch(selectedCycle, (val) => {
    router.get(route('performance.nine-box'), {
        cycle_id: val || undefined,
    }, { preserveState: true, replace: true });
});

// Cells are returned ordered by service: potential (highâ†’low) Ã— performance (lowâ†’high)
const cells = computed(() => props.matrix?.cells ?? []);

// â”€â”€ Cell metadata â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// The grid renders as a 3Ã—3 matrix: rows = potential (high, medium, low), cols = performance (low, medium, high)
const CELL_META = {
    high_low:      {
        label:       'Enigma',
        description: 'High potential, not yet delivering. Assign a coach to unlock performance.',
        tint:        '217,119,6',
        bg:          'rgba(217,119,6,0.06)',
        border:      'rgba(217,119,6,0.25)',
        zone:        'develop',
    },
    high_medium:   {
        label:       'Growth Employee',
        description: 'High potential, consistent output. Stretch with visible assignments.',
        tint:        '32,82,149',
        bg:          'rgba(32,82,149,0.06)',
        border:      'rgba(32,82,149,0.2)',
        zone:        'invest',
    },
    high_high:     {
        label:       'Future Leader',
        description: 'Stars: highest potential AND highest performance. Plan succession.',
        tint:        '5,150,105',
        bg:          'rgba(5,150,105,0.08)',
        border:      'rgba(5,150,105,0.3)',
        zone:        'star',
    },
    medium_low:    {
        label:       'Inconsistent',
        description: 'Mixed signals. Clarify role expectations and re-evaluate in 90 days.',
        tint:        '217,119,6',
        bg:          'rgba(217,119,6,0.05)',
        border:      'rgba(217,119,6,0.2)',
        zone:        'watch',
    },
    medium_medium: {
        label:       'Core Player',
        description: 'Solid, reliable contributor. Retain, recognise, and prevent disengagement.',
        tint:        '32,82,149',
        bg:          'rgba(32,82,149,0.05)',
        border:      'rgba(32,82,149,0.15)',
        zone:        'core',
    },
    medium_high:   {
        label:       'High Performer',
        description: 'Strong delivery, developing potential. Build towards a leadership path.',
        tint:        '5,150,105',
        bg:          'rgba(5,150,105,0.07)',
        border:      'rgba(5,150,105,0.25)',
        zone:        'invest',
    },
    low_low:       {
        label:       'Risk',
        description: 'Low on both dimensions. Initiate a performance improvement plan promptly.',
        tint:        '220,38,38',
        bg:          'rgba(220,38,38,0.07)',
        border:      'rgba(220,38,38,0.3)',
        zone:        'risk',
    },
    low_medium:    {
        label:       'Effective',
        description: 'Steady performer in current role. Recognise contribution; watch for stagnation.',
        tint:        '32,82,149',
        bg:          'rgba(32,82,149,0.04)',
        border:      'rgba(32,82,149,0.12)',
        zone:        'core',
    },
    low_high:      {
        label:       'Trusted Professional',
        description: 'Expert specialist with strong delivery. Retain expertise; design expert career track.',
        tint:        '5,150,105',
        bg:          'rgba(5,150,105,0.06)',
        border:      'rgba(5,150,105,0.22)',
        zone:        'retain',
    },
};

const meta = (key) => CELL_META[key] ?? CELL_META.medium_medium;

// â”€â”€ Grid layout â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Rows: potential (high â†’ medium â†’ low)
// Cols: performance (low â†’ medium â†’ high)
const GRID_ROWS = ['high', 'medium', 'low'];
const GRID_COLS = ['low', 'medium', 'high'];

const cellAt = (potential, performance) =>
    cells.value.find(c => c.potential === potential && c.performance === performance)
    ?? { key: `${potential}_${performance}`, potential, performance, count: 0, employees: [] };

// â”€â”€ Cell detail slide panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const activeCellKey  = ref(null);
const showCellPanel  = ref(false);

const activeCell = computed(() =>
    activeCellKey.value ? cells.value.find(c => c.key === activeCellKey.value) : null
);
const activeMeta = computed(() => meta(activeCellKey.value ?? 'medium_medium'));

const openCell = (cell) => {
    if (!cell.count) return;
    activeCellKey.value  = cell.key;
    showCellPanel.value  = true;
};

// â”€â”€ Bucket totals for legend â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const buckets = computed(() => {
    const star  = ['high_high', 'medium_high', 'low_high'];
    const inv   = ['high_medium', 'high_low', 'medium_medium'];
    const risk  = ['low_low', 'medium_low', 'low_medium'];
    const sum   = (keys) => cells.value.filter(c => keys.includes(c.key)).reduce((s, c) => s + c.count, 0);
    // High Performers gets the 5% gold — the institutional "star talent"
    // bucket is the most valuable surface on the matrix. Growth Pool stays
    // cobalt (action), Needs Attention stays red (alarm semantic).
    return [
        { label: 'High Performers',  count: sum(star), rgb: '255,215,0',  icon: 'star',        description: 'Retain, develop, reward.' },
        { label: 'Growth Pool',      count: sum(inv),  rgb: '32,82,149',  icon: 'trending_up', description: 'Invest in stretch and coaching.' },
        { label: 'Needs Attention',  count: sum(risk), rgb: '220,38,38',  icon: 'warning',     description: 'Address with targeted plans.' },
    ];
});

// â”€â”€ Avatar helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Avatar gradient pool — disciplined cool family
const GRADIENTS = [
    'linear-gradient(135deg,#0a2647,#205295)',
    'linear-gradient(135deg,#205295,#7cb6e8)',
    'linear-gradient(135deg,#06192f,#0a2647)',
    'linear-gradient(135deg,#205295,#2c74b3)',
    'linear-gradient(135deg,#0a2647,#205295,#d912e3)',
    'linear-gradient(135deg,#205295,#12d9e3)',
];
const avatarGrad = (id) => GRADIENTS[(id ?? 0) % GRADIENTS.length];

const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return (p.length >= 2 ? p[0][0] + p[p.length - 1][0] : name.slice(0, 2)).toUpperCase();
};
</script>

<template>
    <Head title="9-Box Talent Matrix" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                        <Link :href="route('modules.performance')" class="hover:text-secondary">Performance</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>9-Box Matrix</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">9-Box Talent Matrix</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Performance Ã— Potential. Bucketed from submitted review ratings.
                        <span v-if="matrix?.cycle" class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ matrix.cycle.name }} Â· {{ matrix.total }} placed
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('performance.reviews.index')"
                        class="flex items-center gap-2 rounded-xl border border-outline-variant/80 px-4 py-2.5 text-[13px] font-semibold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary hover:border-secondary/30 transition-all"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="color:#205295">rate_review</span>
                        Reviews
                    </Link>
                    <!-- Calibration hint for HR — magenta (people-management) -->
                    <Link
                        v-if="canManage && matrix?.cycle"
                        :href="route('performance.calibration.index')"
                        class="flex items-center gap-2 rounded-xl border px-4 py-2.5 text-[13px] font-semibold transition-all hover:-translate-y-px"
                        style="border-color:rgba(217,18,227,0.32);background:rgba(217,18,227,0.06);color:#a30db0"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">tune</span>
                        Calibration
                    </Link>
                    <select
                        v-model="selectedCycle"
                        class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">Active cycle</option>
                        <option v-for="c in cycleList" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.status }})
                        </option>
                    </select>
                </div>
            </div>
        </template>

        <div class="p-6 space-y-6 animate-reveal-up">

            <!-- â”€â”€ No data state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="!matrix?.cycle" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-16">
                <EmptyState
                    title="No active review cycle"
                    description="The 9-box matrix is computed from submitted reviews in an active cycle. Create a cycle and submit reviews to populate this view."
                    icon="grid_view"
                >
                    <template #action>
                        <Link
                            :href="route('performance.reviews.index')"
                            class="btn-shimmer inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow"
                            style="background:linear-gradient(135deg,#0a2647,#205295)"
                        >
                            <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">rate_review</span>
                            Go to Reviews
                        </Link>
                    </template>
                </EmptyState>
            </div>

            <template v-else>

                <!-- â”€â”€ Bucket summary cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="grid grid-cols-3 gap-4">
                    <div
                        v-for="(bkt, i) in buckets"
                        :key="bkt.label"
                        class="card-lift rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden p-5"
                        :style="`border-left: 3px solid rgba(${bkt.rgb},0.7); animation-delay: ${i * 0.06}s`"
                    >
                        <div class="mb-3 inline-flex h-9 w-9 items-center justify-center rounded-xl" :style="`background:rgba(${bkt.rgb},0.12)`">
                            <span class="material-symbols-outlined text-[20px]" :style="`color:rgb(${bkt.rgb});font-variation-settings:'FILL' 1`">{{ bkt.icon }}</span>
                        </div>
                        <p class="text-[2rem] font-black leading-none tabular-nums" :style="`color:rgb(${bkt.rgb})`">{{ bkt.count }}</p>
                        <p class="mt-1 text-[11px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">{{ bkt.label }}</p>
                        <p class="mt-1 text-[11px] text-on-surface-variant/60">{{ bkt.description }}</p>
                    </div>
                </div>

                <!-- â”€â”€ The 9-box grid â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden shadow-card">
                    <!-- Column headers: Performance axis -->
                    <div class="border-b border-outline-variant/40 bg-surface-container/40 px-5 py-3">
                        <p class="text-center text-[11px] font-black uppercase tracking-[0.2em] text-on-surface-variant/60">
                            Performance â†’
                        </p>
                    </div>

                    <div class="p-5">
                        <div class="grid grid-cols-[36px_1fr_1fr_1fr] gap-3">
                            <!-- Top-left empty corner -->
                            <div></div>
                            <!-- Performance column labels -->
                            <div class="text-center">
                                <span class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50">Low</span>
                            </div>
                            <div class="text-center">
                                <span class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50">Solid</span>
                            </div>
                            <div class="text-center">
                                <span class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50">High</span>
                            </div>

                            <!-- 3 rows (potential: high â†’ medium â†’ low) -->
                            <template v-for="(potRow, ri) in GRID_ROWS" :key="potRow">
                                <!-- Y-axis potential label -->
                                <div class="flex items-center justify-center">
                                    <span
                                        class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50"
                                        style="writing-mode: vertical-rl; transform: rotate(180deg); letter-spacing: 0.12em"
                                    >
                                        <template v-if="potRow === 'high'">High</template>
                                        <template v-else-if="potRow === 'medium'">Med</template>
                                        <template v-else>Low</template>
                                    </span>
                                </div>

                                <!-- 3 cells in this row -->
                                <div
                                    v-for="(perfCol) in GRID_COLS"
                                    :key="`${potRow}_${perfCol}`"
                                    class="relative rounded-2xl border overflow-hidden transition-all min-h-[160px]"
                                    :style="`background:${meta(cellAt(potRow, perfCol).key).bg};border-color:${meta(cellAt(potRow, perfCol).key).border}`"
                                    :class="cellAt(potRow, perfCol).count > 0 ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-lifted' : ''"
                                    @click="openCell(cellAt(potRow, perfCol))"
                                >
                                    <!-- Star corner highlight for top-right star cell -->
                                    <div
                                        v-if="potRow === 'high' && perfCol === 'high'"
                                        class="absolute inset-0 pointer-events-none"
                                        style="background:radial-gradient(ellipse at 100% 0%, rgba(5,150,105,0.15) 0%, transparent 60%)"
                                    ></div>
                                    <!-- Risk corner highlight for bottom-left risk cell -->
                                    <div
                                        v-if="potRow === 'low' && perfCol === 'low'"
                                        class="absolute inset-0 pointer-events-none"
                                        style="background:radial-gradient(ellipse at 0% 100%, rgba(220,38,38,0.12) 0%, transparent 60%)"
                                    ></div>

                                    <div class="p-3.5">
                                        <!-- Cell label -->
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <p
                                                class="text-[10px] font-black uppercase tracking-wider leading-snug"
                                                :style="`color:rgb(${meta(cellAt(potRow, perfCol).key).tint})`"
                                            >{{ meta(cellAt(potRow, perfCol).key).label }}</p>
                                            <!-- Count badge -->
                                            <span
                                                v-if="cellAt(potRow, perfCol).count > 0"
                                                class="inline-flex items-center justify-center h-6 min-w-[24px] rounded-full text-[11px] font-black text-white tabular-nums px-1.5"
                                                :style="`background:rgb(${meta(cellAt(potRow, perfCol).key).tint})`"
                                            >{{ cellAt(potRow, perfCol).count }}</span>
                                        </div>

                                        <!-- Description -->
                                        <p class="text-[10px] text-on-surface-variant/65 leading-snug line-clamp-2 mb-2.5">
                                            {{ meta(cellAt(potRow, perfCol).key).description }}
                                        </p>

                                        <!-- Employee avatar stack (up to 6) -->
                                        <div v-if="cellAt(potRow, perfCol).employees?.length" class="space-y-1">
                                            <!-- First 4: stacked avatar row -->
                                            <div class="flex items-center -space-x-1.5 mb-1">
                                                <div
                                                    v-for="emp in cellAt(potRow, perfCol).employees.slice(0, 5)"
                                                    :key="emp.id"
                                                    class="h-6 w-6 rounded-full flex items-center justify-center text-[8px] font-black text-white ring-2 ring-surface-container-lowest"
                                                    :style="`background:${avatarGrad(emp.id)}`"
                                                    :title="emp.name"
                                                >{{ initials(emp.name) }}</div>
                                                <div
                                                    v-if="cellAt(potRow, perfCol).count > 5"
                                                    class="h-6 w-6 rounded-full flex items-center justify-center text-[8px] font-black text-white ring-2 ring-surface-container-lowest"
                                                    :style="`background:rgb(${meta(cellAt(potRow, perfCol).key).tint})`"
                                                >+{{ cellAt(potRow, perfCol).count - 5 }}</div>
                                            </div>
                                            <!-- "View all" affordance -->
                                            <button
                                                type="button"
                                                class="flex items-center gap-1 text-[10px] font-bold transition-colors"
                                                :style="`color:rgb(${meta(cellAt(potRow, perfCol).key).tint})`"
                                                @click.stop="openCell(cellAt(potRow, perfCol))"
                                            >
                                                <span class="material-symbols-outlined text-[12px]">group</span>
                                                View all ({{ cellAt(potRow, perfCol).count }})
                                            </button>
                                        </div>
                                        <p v-else class="text-[10px] italic text-on-surface-variant/35">No employees</p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Potential Y-axis label (vertical, below the grid rows label) -->
                        <div class="mt-3 flex justify-start pl-9">
                            <p class="text-[11px] font-black uppercase tracking-[0.2em] text-on-surface-variant/50">
                                â†‘ Potential
                            </p>
                        </div>
                    </div>
                </div>

            </template>
        </div>

        <!-- â”€â”€ Cell Detail SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showCellPanel"
            :title="activeMeta.label"
            size="md"
            @close="showCellPanel = false; activeCellKey = null"
        >
            <div v-if="activeCell" class="p-6 space-y-5">

                <!-- Cell description banner -->
                <div
                    class="rounded-xl border p-4"
                    :style="`background:${activeMeta.bg};border-color:${activeMeta.border}`"
                >
                    <div class="flex items-center gap-2 mb-2">
                        <div
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg"
                            :style="`background:rgba(${activeMeta.tint},0.2)`"
                        >
                            <span class="material-symbols-outlined text-[18px]" :style="`color:rgb(${activeMeta.tint});font-variation-settings:'FILL' 1`">
                                {{ activeMeta.zone === 'star' ? 'star' : activeMeta.zone === 'risk' ? 'warning' : 'person' }}
                            </span>
                        </div>
                        <p class="text-[13px] font-black" :style="`color:rgb(${activeMeta.tint})`">{{ activeMeta.label }}</p>
                    </div>
                    <p class="text-[12px] text-on-surface-variant/80 leading-relaxed">{{ activeMeta.description }}</p>
                    <div class="mt-2 flex items-center gap-3 text-[11px] text-on-surface-variant/60">
                        <span>Performance: <strong class="text-on-surface capitalize">{{ activeCell.performance }}</strong></span>
                        <span class="text-outline-variant">Â·</span>
                        <span>Potential: <strong class="text-on-surface capitalize">{{ activeCell.potential }}</strong></span>
                        <span class="text-outline-variant">Â·</span>
                        <span class="font-bold" :style="`color:rgb(${activeMeta.tint})`">{{ activeCell.count }} employees</span>
                    </div>
                </div>

                <!-- Employee list -->
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Employees in this cell</p>

                    <div v-if="activeCell.employees?.length === 0" class="rounded-xl border border-dashed border-outline-variant/50 py-8 text-center">
                        <p class="text-[12px] italic text-on-surface-variant/40">No employees assigned to this cell</p>
                    </div>

                    <div
                        v-for="emp in activeCell.employees"
                        :key="emp.id"
                        class="flex items-center gap-3 rounded-xl border border-outline-variant/40 bg-surface-container/30 px-4 py-3 hover:bg-surface-container/60 transition-colors"
                    >
                        <!-- Avatar -->
                        <div
                            class="h-9 w-9 rounded-full flex items-center justify-center text-[11px] font-black text-white flex-shrink-0"
                            :style="`background:${avatarGrad(emp.id)}`"
                        >{{ initials(emp.name) }}</div>

                        <!-- Details -->
                        <div class="flex-1 min-w-0">
                            <p class="text-[13px] font-bold text-on-surface truncate">{{ emp.name }}</p>
                            <p class="text-[11px] text-on-surface-variant/60 truncate">{{ emp.manager_name ? `Reports to ${emp.manager_name}` : 'Manager not set' }}</p>
                        </div>

                        <!-- Ratings -->
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <div class="text-center">
                                <p class="text-[9px] font-black uppercase tracking-wider text-on-surface-variant/50">Perf</p>
                                <p
                                    class="text-[16px] font-black font-mono tabular-nums"
                                    :style="`color:rgb(${activeMeta.tint})`"
                                >{{ emp.avg_perf?.toFixed(1) ?? 'â€”' }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] font-black uppercase tracking-wider text-on-surface-variant/50">Pot</p>
                                <p
                                    class="text-[16px] font-black font-mono tabular-nums"
                                    :style="`color:rgb(${activeMeta.tint})`"
                                >{{ emp.avg_pot?.toFixed(1) ?? 'â€”' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Suggested action footer -->
                <div class="rounded-xl border border-outline-variant/40 bg-surface-container/40 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1.5">Suggested Action</p>
                    <p class="text-[12px] text-on-surface-variant/80 leading-relaxed">{{ activeMeta.description }}</p>
                    <div v-if="canManage" class="mt-3">
                        <Link
                            :href="route('performance.calibration.index')"
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] font-bold transition-colors"
                            :style="`color:rgb(${activeMeta.tint});background:rgba(${activeMeta.tint},0.1)`"
                        >
                            <span class="material-symbols-outlined text-[14px]">tune</span>
                            Open Calibration
                        </Link>
                    </div>
                </div>
            </div>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
