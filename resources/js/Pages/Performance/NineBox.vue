<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    matrix:       Object,
    cycles:       Object,
    activeModule: String,
});

const cycleList = computed(() => props.cycles?.data ?? props.cycles ?? []);

const selectedCycle = ref(props.matrix?.cycle?.id ?? '');

watch(selectedCycle, (val) => {
    router.get(route('performance.nine-box'), {
        cycle_id: val || undefined,
    }, { preserveState: true, replace: true });
});

// Cells are returned ordered top-left first by service: high_low, high_medium, high_high, medium_low, ...
const cells = computed(() => props.matrix?.cells ?? []);

// Quadrant colour by intent: top-right = stars, bottom-left = risk.
const CELL_META = {
    high_low:     { tint: '#d97706', stripe: 'linear-gradient(135deg,#fde68a,#fbbf24)',   label: 'Enigma',                badge: 'AMBER',  description: 'High potential not yet delivering. Coach for performance.' },
    high_medium:  { tint: '#0051d5', stripe: 'linear-gradient(135deg,#bfdbfe,#3b82f6)',   label: 'Growth Employee',       badge: 'BLUE',   description: 'High potential, consistent. Invest in stretch assignments.' },
    high_high:    { tint: '#059669', stripe: 'linear-gradient(135deg,#bbf7d0,#10b981)',   label: 'Future Leader',         badge: 'GREEN',  description: 'High potential and high performance. Plan succession.' },
    medium_low:   { tint: '#d97706', stripe: 'linear-gradient(135deg,#fed7aa,#f97316)',   label: 'Inconsistent',          badge: 'AMBER',  description: 'Mixed signals. Clarify role and re-evaluate.' },
    medium_medium:{ tint: '#0051d5', stripe: 'linear-gradient(135deg,#dbeafe,#60a5fa)',   label: 'Core Player',           badge: 'BLUE',   description: 'Solid contributor. Retain and recognise.' },
    medium_high:  { tint: '#059669', stripe: 'linear-gradient(135deg,#d1fae5,#34d399)',   label: 'High Impact Performer', badge: 'GREEN',  description: 'Strong delivery, growth potential. Develop leadership.' },
    low_low:      { tint: '#dc2626', stripe: 'linear-gradient(135deg,#fecaca,#ef4444)',   label: 'Risk',                  badge: 'RED',    description: 'Low across both axes. Address performance plan.' },
    low_medium:   { tint: '#7c3aed', stripe: 'linear-gradient(135deg,#ddd6fe,#a78bfa)',   label: 'Effective',             badge: 'PURPLE', description: 'Steady performer in their current role.' },
    low_high:     { tint: '#059669', stripe: 'linear-gradient(135deg,#d1fae5,#34d399)',   label: 'Trusted Professional',  badge: 'GREEN',  description: 'Experienced specialist. Recognise and retain.' },
};

const meta = (key) => CELL_META[key] ?? CELL_META.medium_medium;

const cycleStatusColor = (status) => ({
    active:  '#059669',
    draft:   '#6b7280',
    closed:  '#9ca3af',
}[status] ?? '#6b7280');
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
                        <span>9-Box Talent Matrix</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">9-Box Talent Matrix</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Performance × Potential. Bucketed from submitted review ratings.
                        <span v-if="matrix?.cycle" class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ matrix.cycle.name }} · {{ matrix.total }} employees
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('performance.reviews.index')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">rate_review</span>
                        Reviews
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

        <div class="space-y-6">

            <!-- No data state -->
            <div v-if="!matrix?.cycle" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-16">
                <EmptyState
                    title="No active review cycle"
                    description="The 9-box matrix is computed from submitted reviews in an active cycle. Create a cycle and submit reviews to populate this view."
                    icon="grid_view"
                >
                    <template #action>
                        <Link
                            :href="route('performance.reviews.index')"
                            class="btn-shimmer inline-flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Go to Reviews
                        </Link>
                    </template>
                </EmptyState>
            </div>

            <template v-else>
                <!-- Axis labels + Matrix -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <div class="grid grid-cols-[auto_1fr] gap-x-3">
                        <!-- Y-axis vertical label -->
                        <div class="flex items-center justify-center">
                            <p class="text-[11px] font-black uppercase tracking-[0.25em] text-on-surface-variant/60" style="writing-mode:vertical-rl;transform:rotate(180deg)">
                                Potential →
                            </p>
                        </div>

                        <div class="space-y-3">
                            <!-- Potential axis labels above the grid -->
                            <div class="grid grid-cols-3 gap-3 mb-1 pl-12">
                                <p class="text-center text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Low Performance</p>
                                <p class="text-center text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Medium Performance</p>
                                <p class="text-center text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">High Performance</p>
                            </div>

                            <!-- Row by row: high potential (top), medium, low -->
                            <template v-for="row in ['high','medium','low']" :key="row">
                                <div class="grid grid-cols-[40px_1fr_1fr_1fr] gap-3">
                                    <div class="flex items-center justify-end">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 rotate-0">
                                            <template v-if="row==='high'">High</template>
                                            <template v-else-if="row==='medium'">Med</template>
                                            <template v-else>Low</template>
                                        </p>
                                    </div>

                                    <div
                                        v-for="cell in cells.filter(c => c.potential === row)"
                                        :key="cell.key"
                                        class="relative rounded-xl border border-outline-variant/60 bg-surface-container-low/40 p-3.5 min-h-[150px] overflow-hidden transition-all hover:shadow-card"
                                    >
                                        <!-- Top accent stripe -->
                                        <div class="absolute top-0 left-0 right-0 h-1" :style="`background:${meta(cell.key).stripe}`"></div>

                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-black uppercase tracking-wider truncate" :style="`color:${meta(cell.key).tint}`">{{ cell.label }}</p>
                                                <p class="text-[20px] font-black font-mono leading-none mt-0.5" :style="`color:${meta(cell.key).tint}`">
                                                    {{ cell.count }}
                                                </p>
                                            </div>
                                            <span v-if="cell.count > 0"
                                                  class="flex h-6 w-6 items-center justify-center rounded-full text-[9px] font-black text-white"
                                                  :style="`background:${meta(cell.key).tint}`">
                                                {{ Math.round((cell.count / Math.max(1, matrix.total)) * 100) }}%
                                            </span>
                                        </div>

                                        <p class="text-[10px] text-on-surface-variant/70 leading-snug line-clamp-2 mb-2">
                                            {{ meta(cell.key).description }}
                                        </p>

                                        <!-- Employee chips -->
                                        <div v-if="cell.employees?.length" class="space-y-1">
                                            <div
                                                v-for="emp in cell.employees.slice(0, 3)" :key="emp.id"
                                                class="flex items-center justify-between gap-2 rounded-md bg-surface-container/60 px-2 py-1 text-[10px]"
                                            >
                                                <p class="font-semibold text-on-surface truncate">{{ emp.name }}</p>
                                                <span class="font-mono text-on-surface-variant/60 whitespace-nowrap">
                                                    {{ emp.avg_perf.toFixed(1) }} · {{ emp.avg_pot.toFixed(1) }}
                                                </span>
                                            </div>
                                            <p v-if="cell.employees.length > 3" class="text-[10px] italic text-on-surface-variant/50 pl-2">
                                                + {{ cell.employees.length - 3 }} more
                                            </p>
                                        </div>
                                        <p v-else class="text-[10px] italic text-on-surface-variant/40 mt-1">No employees</p>
                                    </div>
                                </div>
                            </template>

                            <!-- X-axis horizontal label -->
                            <p class="text-center text-[11px] font-black uppercase tracking-[0.25em] text-on-surface-variant/60 pt-2 pl-12">
                                Performance →
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Legend + summary -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-2.5 w-2.5 rounded-full" style="background:#059669"></div>
                            <p class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Strong (Green)</p>
                        </div>
                        <p class="text-[20px] font-black font-mono text-on-surface">
                            {{ cells.filter(c => ['high_high','medium_high','low_high'].includes(c.key)).reduce((s, c) => s + c.count, 0) }}
                        </p>
                        <p class="mt-1 text-[11px] text-on-surface-variant/70">High-performance bucket. Retain, develop, and reward.</p>
                    </div>
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-2.5 w-2.5 rounded-full" style="background:#d97706"></div>
                            <p class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Develop (Amber)</p>
                        </div>
                        <p class="text-[20px] font-black font-mono text-on-surface">
                            {{ cells.filter(c => ['high_low','medium_low'].includes(c.key)).reduce((s, c) => s + c.count, 0) }}
                        </p>
                        <p class="mt-1 text-[11px] text-on-surface-variant/70">Potential without delivery. Coach into the green bucket.</p>
                    </div>
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-2.5 w-2.5 rounded-full" style="background:#dc2626"></div>
                            <p class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">At Risk (Red)</p>
                        </div>
                        <p class="text-[20px] font-black font-mono text-on-surface">
                            {{ cells.filter(c => c.key === 'low_low').reduce((s, c) => s + c.count, 0) }}
                        </p>
                        <p class="mt-1 text-[11px] text-on-surface-variant/70">Low on both axes. Address with performance plans.</p>
                    </div>
                </div>
            </template>
        </div>

    </AuthenticatedLayout>
</template>
