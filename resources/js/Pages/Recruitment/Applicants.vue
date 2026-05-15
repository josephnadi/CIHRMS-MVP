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

const STAGES = [
    { id: 'applied',     label: 'Applied',     color: 'blue',   accent: '#0051d5' },
    { id: 'shortlisted', label: 'Shortlisted', color: 'violet', accent: '#7c3aed' },
    { id: 'interviewed', label: 'Interviewed', color: 'amber',  accent: '#d97706' },
    { id: 'offered',     label: 'Offered',     color: 'green',  accent: '#0891b2' },
    { id: 'hired',       label: 'Hired',       color: 'green',  accent: '#059669' },
    { id: 'rejected',    label: 'Rejected',    color: 'red',    accent: '#dc2626' },
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
        hired:       all.filter(a => a.status === 'hired').length,
        rejected:    all.filter(a => a.status === 'rejected').length,
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

function avatarColor(name) {
    const colors = ['#0051d5','#7c3aed','#059669','#d97706','#dc2626','#0891b2'];
    let h = 0;
    for (let i = 0; i < (name?.length ?? 0); i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return colors[Math.abs(h) % colors.length];
}
function initials(name) {
    if (!name) return '?';
    return name.trim().split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

function fmtDate(d) {
    if (!d) return '—';
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
    <Head :title="`Applicants — ${job?.title ?? 'Job'}`" />

    <AuthenticatedLayout :activeModule="activeModule">

        <!-- Breadcrumbs -->
        <nav class="mb-3 flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60">
            <Link :href="route('jobs.index')" class="hover:text-secondary transition-colors">Recruitment</Link>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <Link :href="route('jobs.show', job?.id)" class="hover:text-secondary transition-colors truncate max-w-[280px]">{{ job?.title }}</Link>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="text-on-surface">Applicants</span>
        </nav>

        <!-- Header -->
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-[22px] font-black tracking-tight text-on-surface">{{ job?.title ?? 'Job' }}</h1>
                    <StatusBadge :status="job?.status" type="recruitment" />
                </div>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">
                    {{ stats.total }} applicants in the pipeline
                    <span v-if="job?.closes_at" class="ml-2 text-on-surface-variant/50">· closes {{ fmtDate(job.closes_at) }}</span>
                </p>
            </div>
            <Link
                :href="route('jobs.show', job?.id)"
                class="flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors"
            >
                <span class="material-symbols-outlined text-[17px]">work</span>
                Job details
            </Link>
        </div>

        <!-- Stats -->
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="(s, i) in [
                { label: 'Total Applicants', val: stats.total,    rgb: '0,81,213',   icon: 'group',          sub: 'all stages' },
                { label: 'In Pipeline',      val: stats.active,   rgb: '124,92,255', icon: 'autorenew',      sub: 'active' },
                { label: 'Hired',            val: stats.hired,    rgb: '5,150,105',  icon: 'check_circle',   sub: 'completed' },
                { label: 'Conversion Rate',  val: conversionRate + '%', rgb: '14,116,144', icon: 'trending_up', sub: 'hire ratio' },
            ]" :key="i"
                class="card-lift relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card"
            >
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl"
                         :style="`background:rgba(${s.rgb},0.12);border:1px solid rgba(${s.rgb},0.2)`">
                        <span class="material-symbols-outlined text-[18px]"
                              :style="`color:rgb(${s.rgb});font-variation-settings:'FILL' 1`">{{ s.icon }}</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/40">{{ s.sub }}</span>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ s.label }}</p>
                <p class="mt-0.5 text-[26px] font-black tracking-tight text-on-surface">{{ s.val }}</p>
            </div>
        </div>

        <!-- View switcher -->
        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-outline-variant/40 px-4 py-3">
                <div class="px-1 pt-1">
                    <TabBar :tabs="viewTabs" v-model="view" />
                </div>
                <div class="flex-1"></div>
                <select v-if="view === 'list'" v-model="filterStatus" class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-semibold text-on-surface-variant focus:outline-none focus:border-secondary/50">
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
                    <thead>
                        <tr class="bg-surface-container-low border-b border-outline-variant/40">
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Applicant</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Stage</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60 hidden md:table-cell">CV</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Applied</th>
                            <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in filteredList" :key="a.id"
                            class="border-b border-outline-variant/25 hover:bg-surface-container/40 cursor-pointer transition-colors"
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
                                <span v-else class="text-[11px] text-on-surface-variant/40">—</span>
                            </td>
                            <td class="px-5 py-3.5 text-on-surface-variant/70 text-[12px]">
                                {{ fmtDate(a.created_at) }}
                                <span class="block text-[10px] text-on-surface-variant/40">{{ timeAgo(a.created_at) }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-2">
                                    <button @click.stop="moveStage(a, 'shortlisted')"
                                            v-if="a.status === 'applied'"
                                            class="rounded-lg bg-violet-50 dark:bg-violet-900/20 px-2.5 py-1 text-[11px] font-bold text-violet-700 dark:text-violet-400 hover:bg-violet-100">
                                        Shortlist
                                    </button>
                                    <button @click.stop="moveStage(a, 'rejected')"
                                            v-if="!['hired','rejected'].includes(a.status)"
                                            class="rounded-lg bg-red-50 dark:bg-red-900/20 px-2.5 py-1 text-[11px] font-bold text-red-700 dark:text-red-400 hover:bg-red-100">
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
