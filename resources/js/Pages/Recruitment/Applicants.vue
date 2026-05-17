<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KanbanBoard         from '@/Components/KanbanBoard.vue';
import StatusBadge         from '@/Components/StatusBadge.vue';
import EmptyState          from '@/Components/EmptyState.vue';
import SlidePanel          from '@/Components/SlidePanel.vue';
import TabBar              from '@/Components/TabBar.vue';

const props = defineProps({
    job:          Object,
    applicants:   Object,
    activeModule: String,
});

// Stage palette — disciplined. Fixes two pre-existing color/accent
// mismatch bugs: shortlisted was 'violet' with cobalt accent, and offered
// was 'green' with cyan accent. Now shortlisted = magenta (people-side
// selection), offered = cobalt (action), hired = green (success).
const STAGES = [
    { id: 'applied',     label: 'Applied',     color: 'blue',    accent: '#1a237e' },
    { id: 'shortlisted', label: 'Shortlisted', color: 'magenta', accent: '#d912e3' },
    { id: 'interviewed', label: 'Interviewed', color: 'amber',   accent: '#d97706' },
    { id: 'offered',     label: 'Offered',     color: 'blue',    accent: '#1a237e' },
    { id: 'hired',       label: 'Hired',       color: 'green',   accent: '#059669' },
    { id: 'rejected',    label: 'Rejected',    color: 'red',     accent: '#dc2626' },
];

const view = ref('pipeline');
const viewTabs = [
    { value: 'pipeline', label: 'Pipeline', icon: 'view_kanban' },
    { value: 'list',     label: 'List',     icon: 'view_list' },
];

const list = computed(() => props.applicants?.data ?? []);

const stats = computed(() => {
    const all = list.value;
    return {
        total:       all.length,
        active:      all.filter(a => !['hired','rejected'].includes(a.status)).length,
        shortlisted: all.filter(a => a.status === 'shortlisted').length,
        interviewed: all.filter(a => a.status === 'interviewed').length,
        offered:     all.filter(a => a.status === 'offered').length,
        hired:       all.filter(a => a.status === 'hired').length,
        rejected:    all.filter(a => a.status === 'rejected').length,
    };
});

// ── Editorial Sovereign edition label — broadsheet masthead ──
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

const conversionRate = computed(() => {
    const total = stats.value.total;
    if (total === 0) return 0;
    return Math.round((stats.value.hired / total) * 100);
});

const columns = computed(() =>
    STAGES.filter(s => s.id !== 'rejected').map(s => ({
        id: s.id,
        label: s.label,
        color: s.color,
        items: list.value.filter(a => a.status === s.id),
    }))
);

function onMove({ itemId, toColumnId }) {
    router.patch(route('applicants.update', itemId), { status: toColumnId }, {
        preserveScroll: true,
        preserveState: true,
    });
}

// Applicant avatar gradient pool — disciplined cool family (matches all other modules)
const AVATAR_GRADIENTS = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < (name?.length ?? 0); i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return AVATAR_GRADIENTS[Math.abs(h) % AVATAR_GRADIENTS.length];
}
function initials(name) {
    if (!name) return '?';
    return name.trim().split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

function fmtDate(d) {
    if (!d) return 'â€”';
    return new Date(d).toLocaleDateString('en-GH', { day: '2-digit', month: 'short', year: 'numeric' });
}

function timeAgo(d) {
    if (!d) return '';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 86400000);
    if (diff === 0) return 'today';
    if (diff === 1) return '1d ago';
    if (diff < 7) return `${diff}d ago`;
    if (diff < 30) return `${Math.floor(diff/7)}w ago`;
    return `${Math.floor(diff/30)}mo ago`;
}

// Detail panel
const selected = ref(null);
const showDetail = computed({
    get: () => !!selected.value,
    set: (v) => { if (!v) selected.value = null; },
});

function moveStage(applicant, status) {
    router.patch(route('applicants.update', applicant.id), { status }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { selected.value = null; },
    });
}

// Filter for list view
const filterStatus = ref('');
const filteredList = computed(() => {
    if (!filterStatus.value) return list.value;
    return list.value.filter(a => a.status === filterStatus.value);
});
</script>

<template>
    <Head :title="`Applicants â€” ${job?.title ?? 'Job'}`" />

    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="space-y-6">
                <!-- Breadcrumbs -->
                <nav class="flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60" aria-label="Breadcrumb">
                    <Link :href="route('jobs.index')" class="hover:text-secondary transition-colors">Recruitment</Link>
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                    <Link :href="route('jobs.show', job?.id)" class="hover:text-secondary transition-colors truncate max-w-[280px]">{{ job?.title }}</Link>
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                    <span class="text-on-surface" aria-current="page">Applicants</span>
                </nav>

                <!-- ─── Masthead strip ────────────────────────────────────── -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">RECRUITMENT — APPLICANT PIPELINE</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · {{ stats.active }} in flight
                    </span>
                </div>

                <!-- ─── Broadsheet hero ───────────────────────────────────── -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Applicant pipeline · {{ job?.title ?? 'Open role' }}</p>
                        <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                            Candidates,
                            <span class="es-display-italic block">in review.</span>
                        </h2>
                        <p class="es-display-sub">
                            Every applicant for <em class="not-italic font-semibold text-primary">{{ job?.title ?? 'this role' }}</em> moves through one
                            disciplined pipeline — applied to shortlist, interview to offer, offer to appointment under the Registrar's seal.
                            <span v-if="job?.closes_at" class="block mt-1 text-on-surface-variant/70">Bulletin closes {{ fmtDate(job.closes_at) }}.</span>
                        </p>

                        <!-- Quick-action chips -->
                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <Link :href="route('jobs.show', job?.id)" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">work</span>
                                Job details
                            </Link>
                            <span class="es-chip-divider">·</span>
                            <Link :href="route('jobs.index')" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">arrow_back</span>
                                All postings
                            </Link>
                        </div>
                    </div>

                    <!-- SIDEBAR column: headline KPI -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Total applicants</p>
                            <p class="es-stat-hero-value">{{ stats.total.toLocaleString() }}</p>
                            <p class="es-stat-hero-caption">
                                {{ stats.active.toLocaleString() }} in flight ·
                                {{ stats.hired.toLocaleString() }} appointed ·
                                {{ conversionRate }}% conversion
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">groups</span>
                                Pipeline under review
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ─── Supporting metrics strip ─────────────────────────── -->
                <div class="es-stat-strip rounded-none">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Total applicants</p>
                        <p class="es-stat-cell-value">{{ stats.total.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">All stages on record</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Interviewed</p>
                        <p class="es-stat-cell-value">{{ stats.interviewed.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Panel reviewed</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Offers extended</p>
                        <p class="es-stat-cell-value">{{ stats.offered.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Letters under seal</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Accepted</p>
                        <p class="es-stat-cell-value">{{ stats.hired.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Appointments confirmed</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- View switcher -->
        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-outline-variant/40 px-4 py-3">
                <div class="px-1 pt-1">
                    <TabBar :tabs="viewTabs" v-model="view" />
                </div>
                <div class="flex-1"></div>
                <select v-if="view === 'list'" v-model="filterStatus" aria-label="Filter by applicant stage" class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-semibold text-on-surface-variant focus:outline-none focus:border-secondary/50">
                    <option value="">All stages</option>
                    <option v-for="s in STAGES" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
            </div>

            <!-- PIPELINE VIEW -->
            <div v-if="view === 'pipeline'" class="p-5">
                <KanbanBoard :columns="columns" @move="onMove">
                    <template #card="{ item }">
                        <div @click="selected = item" class="cursor-pointer">
                            <!-- Avatar + name -->
                            <div class="flex items-center gap-2.5 mb-2">
                                <div class="h-9 w-9 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                     :style="`background:${avatarColor(item.name)}`">
                                    {{ initials(item.name) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13px] font-bold text-on-surface truncate">{{ item.name }}</p>
                                    <p class="text-[11px] text-on-surface-variant/65 truncate">{{ item.email }}</p>
                                </div>
                            </div>

                            <!-- Footer: applied + cv -->
                            <div class="mt-2.5 pt-2 border-t border-outline-variant/30 flex items-center justify-between">
                                <div class="flex items-center gap-1 text-[10.5px] font-semibold text-on-surface-variant/50">
                                    <span class="material-symbols-outlined text-[12px]">schedule</span>
                                    {{ timeAgo(item.created_at) }}
                                </div>
                                <a v-if="item.cv_path"
                                   :href="`/storage/${item.cv_path}`"
                                   target="_blank"
                                   @click.stop
                                   class="inline-flex items-center gap-1 rounded-md bg-secondary/10 px-1.5 py-0.5 text-[10px] font-bold text-secondary hover:bg-secondary/20 transition-colors">
                                    <span class="material-symbols-outlined text-[12px]">attach_file</span>
                                    CV
                                </a>
                            </div>
                        </div>
                    </template>
                </KanbanBoard>

                <EmptyState
                    v-if="list.length === 0"
                    title="No applicants yet"
                    description="Once candidates apply for this position they will appear in the pipeline."
                    icon="person_search"
                />
            </div>

            <!-- LIST VIEW -->
            <div v-else class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <thead class="sticky top-0 z-10">
                        <tr class="bg-surface-container-low/95 backdrop-blur-sm border-b border-outline-variant/40">
                            <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Applicant</th>
                            <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Stage</th>
                            <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 hidden md:table-cell">CV</th>
                            <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Applied</th>
                            <th class="px-5 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="a in filteredList" :key="a.id"
                            class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                            @click="selected = a"
                        >
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="h-9 w-9 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                         :style="`background:${avatarColor(a.name)}`">
                                        {{ initials(a.name) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-bold text-on-surface truncate max-w-[200px]">{{ a.name }}</p>
                                        <p class="text-[11px] text-on-surface-variant/60 truncate max-w-[200px]">{{ a.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <StatusBadge :status="a.status" type="recruitment" />
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell">
                                <a v-if="a.cv_path"
                                   :href="`/storage/${a.cv_path}`"
                                   target="_blank"
                                   @click.stop
                                   class="inline-flex items-center gap-1 rounded-lg bg-secondary/10 px-2.5 py-1 text-[11px] font-bold text-secondary hover:bg-secondary/20">
                                    <span class="material-symbols-outlined text-[13px]">picture_as_pdf</span>
                                    Download
                                </a>
                                <span v-else class="text-[11px] text-on-surface-variant/40">â€”</span>
                            </td>
                            <td class="px-5 py-3.5 text-on-surface-variant/70 text-[12px]">
                                {{ fmtDate(a.created_at) }}
                                <span class="block text-[10px] text-on-surface-variant/40">{{ timeAgo(a.created_at) }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1">
                                    <button @click.stop="moveStage(a, 'shortlisted')"
                                            v-if="a.status === 'applied'"
                                            class="flex items-center gap-1 rounded-lg border border-transparent px-2.5 py-1 text-[11px] font-bold transition-all hover:-translate-y-px"
                                            style="background:rgba(217,18,227,0.08);color:#a30db0"
                                            title="Shortlist applicant"
                                    >
                                        <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">star</span>
                                        Shortlist
                                    </button>
                                    <button @click.stop="moveStage(a, 'rejected')"
                                            v-if="!['hired','rejected'].includes(a.status)"
                                            class="flex items-center gap-1 rounded-lg border border-red-200/60 dark:border-red-700/40 bg-red-50 dark:bg-red-900/20 px-2.5 py-1 text-[11px] font-bold text-red-700 dark:text-red-400 hover:bg-red-100 hover:border-red-400/60 dark:hover:bg-red-900/40 transition-all hover:-translate-y-px"
                                            title="Reject applicant"
                                    >
                                        <span class="material-symbols-outlined text-[13px]">cancel</span>
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <EmptyState
                    v-if="filteredList.length === 0"
                    title="No applicants in this stage"
                    description="Try a different filter or wait for new candidates."
                    icon="person_search"
                />
            </div>
        </div>

        <!-- Detail Slide Panel -->
        <SlidePanel
            :open="showDetail"
            :title="selected?.name ?? 'Applicant'"
            :subtitle="selected?.email ?? ''"
            size="md"
            @close="selected = null"
        >
            <div v-if="selected" class="space-y-5">
                <!-- Hero strip -->
                <div class="flex items-center gap-4 rounded-2xl border border-outline-variant/40 bg-surface-container-low p-4">
                    <div class="h-14 w-14 flex-shrink-0 rounded-2xl flex items-center justify-center text-[16px] font-black text-white shadow-glow-sm"
                         :style="`background:${avatarColor(selected.name)}`">
                        {{ initials(selected.name) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[16px] font-bold text-on-surface truncate">{{ selected.name }}</p>
                        <p class="text-[12px] text-on-surface-variant/70 truncate">{{ selected.email }}</p>
                        <div class="mt-1.5">
                            <StatusBadge :status="selected.status" type="recruitment" />
                        </div>
                    </div>
                </div>

                <!-- Details grid -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-surface-container-low p-3">
                        <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-1">Applied</p>
                        <p class="text-[13.5px] font-bold text-on-surface">{{ fmtDate(selected.created_at) }}</p>
                        <p class="text-[10.5px] text-on-surface-variant/50">{{ timeAgo(selected.created_at) }}</p>
                    </div>
                    <div class="rounded-xl bg-surface-container-low p-3">
                        <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-1">CV / Resume</p>
                        <a v-if="selected.cv_path"
                           :href="`/storage/${selected.cv_path}`"
                           target="_blank"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-secondary/10 px-2.5 py-1 text-[11px] font-bold text-secondary hover:bg-secondary/20 transition-colors">
                            <span class="material-symbols-outlined text-[14px]">download</span>
                            Download
                        </a>
                        <span v-else class="text-[12px] text-on-surface-variant/40">No CV attached</span>
                    </div>
                </div>

                <!-- Stage actions -->
                <div>
                    <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-2">Move to stage</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="s in STAGES" :key="s.id"
                            @click="moveStage(selected, s.id)"
                            :disabled="s.id === selected.status"
                            class="rounded-xl border px-3 py-2.5 text-[12px] font-bold text-left transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="s.id === selected.status
                                ? 'border-secondary/50 bg-secondary/8 text-secondary'
                                : 'border-outline-variant text-on-surface-variant hover:border-secondary/30 hover:bg-surface-container'"
                        >
                            <span class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full" :style="`background:${s.accent}`"></span>
                                {{ s.label }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </SlidePanel>
    </AuthenticatedLayout>
</template>
