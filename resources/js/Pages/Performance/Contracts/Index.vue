<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    contracts:    Object, // paginated: { data: [], links: [], meta: {} }
    cycles:       Array,  // [{ id, name, status }]
    filters:      Object, // { cycle_id, status }
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('performance.manage');
});

// ── Filters ───────────────────────────────────────────────────────────────────
const localFilters = reactive({
    search:   props.filters?.search   ?? '',
    cycle_id: props.filters?.cycle_id ?? '',
    status:   props.filters?.status   ?? '',
});

const applyFilters = () => {
    router.get(route('performance.contracts.index'), {
        search:   localFilters.search   || undefined,
        cycle_id: localFilters.cycle_id || undefined,
        status:   localFilters.status   || undefined,
    }, { preserveState: true, replace: true });
};

let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const clearFilters = () => {
    localFilters.search = '';
    localFilters.cycle_id = '';
    localFilters.status = '';
    applyFilters();
};

const hasFilters = computed(() => localFilters.search || localFilters.cycle_id || localFilters.status);

// ── Stats ─────────────────────────────────────────────────────────────────────
const contractList = computed(() => props.contracts?.data ?? []);

const stats = computed(() => {
    const data = contractList.value;
    const total = props.contracts?.meta?.total ?? data.length;
    const inEval = data.filter(c => c.status === 'under_evaluation').length;
    const closed  = data.filter(c => c.status === 'achieved' || c.status === 'missed').length;

    const rated = data.filter(c => c.weighted_achievement !== null);
    const avgRating = rated.length
        ? (rated.reduce((s, c) => s + (c.weighted_achievement ?? 0), 0) / rated.length).toFixed(1)
        : null;

    return { total, inEval, closed, avgRating };
});

// Stat cards — Avg Achievement gets the 5% gold accent (institutional performance metric)
const statCards = computed(() => [
    { label: 'Total Contracts',     value: stats.value.total,                       icon: 'description',      rgb: '13, 20, 82'   },  // navy
    { label: 'In Evaluation',       value: stats.value.inEval,                      icon: 'rate_review',      rgb: '217,119,6'  },  // amber
    { label: 'Closed This Cycle',   value: stats.value.closed,                      icon: 'check_circle',     rgb: '5,150,105'  },  // green
    { label: 'Avg Achievement',     value: stats.value.avgRating ? `${stats.value.avgRating}%` : '—', icon: 'star', rgb: '255,215,0' },  // gold
]);

// ── New Contract slide-panel ──────────────────────────────────────────────────
const showAddPanel = ref(false);

const form = useForm({
    cycle_id:    '',
    employee_id: '',
    supervisor_id: '',
    kpis:        '',
});

const submitContract = () => {
    // kpis field is a JSON textarea; parse before submit
    const payload = {
        cycle_id:      form.cycle_id,
        employee_id:   form.employee_id,
        supervisor_id: form.supervisor_id || undefined,
        kpis:          (() => {
            try { return form.kpis ? JSON.parse(form.kpis) : []; }
            catch { return []; }
        })(),
    };
    router.post(route('performance.contracts.store'), payload, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showAddPanel.value = false;
        },
    });
};

// ── Status visual map ─────────────────────────────────────────────────────────
const statusTone = {
    draft:              'bg-slate-400/15 text-slate-600',
    pending_signature:  'bg-amber-400/15 text-amber-700',
    active:             'bg-cobalt-500/15 text-cobalt-700',
    under_evaluation:   'bg-violet-500/15 text-violet-700',
    achieved:           'bg-emerald-500/15 text-emerald-700',
    missed:             'bg-rose-500/15 text-rose-700',
    cancelled:          'bg-slate-400/15 text-slate-500',
};

const statusClass = (status) => statusTone[status] ?? 'bg-surface-container text-on-surface-variant';

// Avatar gradient pool — disciplined cool family (matches all other modules)
const gradients = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
const avatarGradient = (id) => gradients[(id ?? 0) % gradients.length];
const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

const currentCycleName = computed(() => {
    if (!localFilters.cycle_id) return null;
    return props.cycles?.find(c => String(c.id) === String(localFilters.cycle_id))?.name ?? null;
});

const signedDots = (contract) => [
    { label: 'Employee signed',   done: !!contract.employee_signed_at },
    { label: 'Supervisor signed', done: !!contract.supervisor_signed_at },
];
</script>

<template>
    <Head title="Performance Contracts" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- ── Header ───────────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                        <Link :href="route('modules.performance')" class="hover:text-secondary">Performance</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Contracts</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Performance Contracts</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Annual goal-setting agreements between manager and employee.
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ stats.total }} total
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="canManage"
                        @click="showAddPanel = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New Contract
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- ── Stat cards ─────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div
                    v-for="(card, i) in statCards"
                    :key="card.label"
                    class="group relative rounded-2xl border bg-surface-container-lowest p-4 shadow-card card-lift overflow-hidden"
                    :style="`border-color:rgba(${card.rgb},0.20);animation-delay:${i * 0.06}s`"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">{{ card.label }}</p>
                            <p class="mt-1.5 text-[1.6rem] font-black leading-none tracking-tight text-on-surface">{{ card.value }}</p>
                        </div>
                        <span
                            class="material-symbols-outlined flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-[20px]"
                            :style="`color:rgb(${card.rgb});background:rgba(${card.rgb},0.10);font-variation-settings:'FILL' 1`"
                        >{{ card.icon }}</span>
                    </div>
                    <div
                        class="absolute inset-x-0 bottom-0 h-[3px] rounded-b-2xl opacity-40"
                        :style="`background:rgb(${card.rgb})`"
                    ></div>
                </div>
            </div>

            <!-- ── Filter strip ───────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] max-w-xs">
                    <SearchInput v-model="localFilters.search" placeholder="Search employee name, no…" />
                </div>

                <select
                    v-model="localFilters.cycle_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Cycles</option>
                    <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="pending_signature">Pending Signature</option>
                    <option value="active">Active</option>
                    <option value="under_evaluation">Under Evaluation</option>
                    <option value="achieved">Achieved</option>
                    <option value="missed">Missed</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <button
                    v-if="hasFilters"
                    @click="clearFilters"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- ── Contract cards grid ────────────────────────────────────── -->
            <div v-if="contractList.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No contracts found"
                    description="Performance contracts will appear once HR drafts them for a review cycle."
                    icon="description"
                >
                    <template #action>
                        <button
                            v-if="canManage"
                            @click="showAddPanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            New Contract
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="contract in contractList"
                    :key="contract.id"
                    class="group relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden transition-all hover:shadow-lifted hover:-translate-y-0.5"
                >
                    <!-- Top accent bar based on status -->
                    <div
                        class="h-[3px] w-full"
                        :class="{
                            'bg-slate-400'   : contract.status === 'draft',
                            'bg-amber-500'   : contract.status === 'pending_signature',
                            'bg-blue-500'    : contract.status === 'active',
                            'bg-violet-500'  : contract.status === 'under_evaluation',
                            'bg-emerald-500' : contract.status === 'achieved',
                            'bg-rose-500'    : contract.status === 'missed',
                            'bg-slate-300'   : contract.status === 'cancelled',
                        }"
                    ></div>

                    <div class="p-5">
                        <!-- Employee row -->
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="h-9 w-9 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                    :style="`background:${avatarGradient(contract.employee?.id)}`"
                                >{{ initials(contract.employee?.name) }}</div>
                                <div class="min-w-0">
                                    <p class="text-[14px] font-bold text-on-surface leading-tight truncate">{{ contract.employee?.name ?? '—' }}</p>
                                    <p class="text-[11px] text-on-surface-variant/60 leading-tight">
                                        {{ contract.employee?.employee_no }}
                                        <span v-if="contract.employee?.department" class="ml-1">· {{ contract.employee.department }}</span>
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
                                :class="statusClass(contract.status)"
                            >{{ contract.status_label ?? contract.status }}</span>
                        </div>

                        <!-- Cycle pill -->
                        <div class="flex items-center gap-2 mb-3">
                            <span class="inline-flex items-center gap-1 rounded-lg bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                                <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                {{ contract.cycle?.name ?? '—' }}
                            </span>
                            <span v-if="contract.supervisor?.name" class="text-[11px] text-on-surface-variant/60">
                                Manager: <span class="font-semibold text-on-surface">{{ contract.supervisor.name }}</span>
                            </span>
                        </div>

                        <!-- Signature checkpoint dots -->
                        <div class="flex items-center gap-3 mb-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60">Signatures</p>
                            <div class="flex items-center gap-1.5">
                                <span
                                    v-for="dot in signedDots(contract)"
                                    :key="dot.label"
                                    class="flex items-center gap-1 text-[10px] font-semibold"
                                    :class="dot.done ? 'text-emerald-600' : 'text-on-surface-variant/40'"
                                    :title="dot.label"
                                >
                                    <span class="material-symbols-outlined text-[14px]" :style="dot.done ? 'font-variation-settings:\'FILL\' 1' : ''">
                                        {{ dot.done ? 'check_circle' : 'radio_button_unchecked' }}
                                    </span>
                                </span>
                            </div>
                        </div>

                        <!-- Achievement / KPIs row -->
                        <div class="flex items-center justify-between border-t border-outline-variant/40 pt-3">
                            <div class="flex items-center gap-4">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60">KPIs</p>
                                    <p class="text-[13px] font-bold text-on-surface">{{ contract.kpis?.length ?? 0 }}</p>
                                </div>
                                <div v-if="contract.weighted_achievement !== null">
                                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60">Achievement</p>
                                    <p
                                        class="text-[13px] font-bold"
                                        :class="contract.weighted_achievement >= 60 ? 'text-emerald-700' : 'text-rose-600'"
                                    >{{ contract.weighted_achievement.toFixed(1) }}%</p>
                                </div>
                                <div v-else>
                                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60">Achievement</p>
                                    <p class="text-[12px] text-on-surface-variant/40 italic">Pending</p>
                                </div>
                            </div>

                            <Link
                                :href="route('performance.contracts.show', contract.id)"
                                class="flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] font-bold text-primary hover:bg-surface-container-low transition-colors"
                            >
                                <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                                Open
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="contracts?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing
                    <span class="font-semibold text-on-surface">{{ contracts.meta?.from }}</span>
                    –
                    <span class="font-semibold text-on-surface">{{ contracts.meta?.to }}</span>
                    of
                    <span class="font-semibold text-on-surface">{{ contracts.meta?.total }}</span>
                </p>
                <Pagination :links="contracts.links" />
            </div>
        </div>

        <!-- ── New Contract SlidePanel ────────────────────────────────────── -->
        <SlidePanel
            :open="showAddPanel"
            title="New Performance Contract"
            size="lg"
            @close="showAddPanel = false"
        >
            <form @submit.prevent="submitContract" class="space-y-5 p-6">

                <div class="rounded-2xl border border-secondary/15 bg-secondary/5 p-4">
                    <p class="text-[11px] font-black uppercase tracking-[0.18em] text-secondary mb-1">
                        Performance Contract Setup
                    </p>
                    <p class="text-[12px] text-on-surface-variant/70">
                        A draft contract will be created and sent for signatures before becoming active.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Review Cycle <span class="text-red-500">*</span></label>
                        <select
                            v-model="form.cycle_id"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="" disabled>Select cycle</option>
                            <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee ID <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.employee_id"
                            type="number"
                            placeholder="Employee ID"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                        <p class="mt-1 text-[11px] text-on-surface-variant/60">Enter the employee's database ID</p>
                    </div>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Supervisor ID</label>
                    <input
                        v-model="form.supervisor_id"
                        type="number"
                        placeholder="Supervisor employee ID (optional)"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    />
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        KPIs <span class="text-on-surface-variant/50 font-normal">(JSON array)</span>
                    </label>
                    <textarea
                        v-model="form.kpis"
                        rows="6"
                        placeholder='[{"title":"Reduce report turnaround","weight":30,"target_value":3,"unit":"days"}]'
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[12px] font-mono text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showAddPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >Cancel</button>
                    <button
                        @click="submitContract"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[16px]">description</span>
                        Draft Contract
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
