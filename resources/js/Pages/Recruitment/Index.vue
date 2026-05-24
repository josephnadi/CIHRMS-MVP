<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusPill, { STATUS_PILL_REGISTRY } from '@/Components/StatusPill.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    jobs:         Object,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('recruitment.manage');
});

// ── Stats (derived client-side from jobs payload) ──
const stats = computed(() => {
    const data = props.jobs?.data ?? [];
    const open      = data.filter(j => j.status === 'open');
    const filled    = data.filter(j => j.status === 'filled');
    const closed    = data.filter(j => j.status === 'closed' || j.status === 'filled');
    const totalApps = data.reduce((s, j) => s + (j.applicants_count ?? 0), 0);
    const openApps  = open.reduce((s, j) => s + (j.applicants_count ?? 0), 0);
    const urgent    = open.filter(j => {
        if (!j.closes_at) return false;
        const d = Math.ceil((new Date(j.closes_at).getTime() - Date.now()) / 86400000);
        return d >= 0 && d <= 7;
    });

    // Hires this month — count jobs marked "filled" whose updated_at lands in current month.
    const now = new Date();
    const monthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    const hiresThisMonth = filled.filter(j => {
        const stamp = j.updated_at ?? j.filled_at ?? j.closes_at;
        if (!stamp) return false;
        return String(stamp).slice(0, 7) === monthKey;
    }).length;

    return {
        total:         data.length,
        open:          open.length,
        closed:        closed.length,
        filled:        filled.length,
        applicants:    totalApps,
        openApps,
        urgentCount:   urgent.length,
        avgPerJob:     open.length > 0 ? Math.round(openApps / open.length) : 0,
        hiresThisMonth,
    };
});

// ── Filters ──
const filter = ref('all');
const search = ref('');

const filteredJobs = computed(() => {
    let data = props.jobs?.data ?? [];
    if (filter.value !== 'all') {
        data = data.filter(j => j.status === filter.value);
    }
    if (search.value.trim()) {
        const q = search.value.trim().toLowerCase();
        data = data.filter(j =>
            (j.title ?? '').toLowerCase().includes(q) ||
            (j.description ?? '').toLowerCase().includes(q)
        );
    }
    return data;
});

// ── Status meta ──
// Colours/labels live in the shared <StatusPill> registry. We only read the
// dot hex here to drive the card's left-border accent (the pill itself is
// rendered by <StatusPill>).
const statusDot = (s) => (STATUS_PILL_REGISTRY[s] ?? STATUS_PILL_REGISTRY.draft).dot;

// ── Compose form ──
const showCreatePanel = ref(false);
const form = useForm({ title: '', description: '', closes_at: '' });

const submit = () => {
    form.post(route('jobs.store'), {
        onSuccess: () => { form.reset(); showCreatePanel.value = false; },
    });
};

// ── Helpers ──
const formatDate = (d) => {
    if (!d) return 'Open until filled';
    return 'Closes ' + new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const closeWindow = (d) => {
    if (!d) return { text: 'Open until filled', tone: 'neutral' };
    const diff = Math.ceil((new Date(d).getTime() - Date.now()) / 86400000);
    if (diff < 0)   return { text: 'Closed',         tone: 'expired'  };
    if (diff === 0) return { text: 'Closes today',   tone: 'urgent'   };
    if (diff <= 7)  return { text: `${diff}d left`,  tone: 'urgent'   };
    if (diff <= 30) return { text: `${diff}d left`,  tone: 'soon'     };
    return            { text: `${diff}d left`,  tone: 'neutral'  };
};

const toneCls = (tone) => ({
    urgent:  'text-rose-600 font-black',
    soon:    'text-amber-700 font-bold',
    expired: 'text-slate-500 font-bold',
    neutral: 'text-on-surface-variant',
}[tone] ?? 'text-on-surface-variant');

// ── Editorial Sovereign edition label — broadsheet masthead ──
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023; // CIHRM-GH platform inception year
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date: d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

// Pipeline funnel — synthetic conversion rates from total applicants
const pipeline = computed(() => {
    const total = stats.value.applicants || 0;
    return [
        { stage: 'Applied',      count: total,                  accent: '#1a237e' },
        { stage: 'Shortlisted',  count: Math.round(total * 0.33), accent: '#7986cb' },
        { stage: '1st interview',count: Math.round(total * 0.14), accent: '#12d9e3' },
        { stage: '2nd interview',count: Math.round(total * 0.05), accent: '#d912e3' },
        { stage: 'Offer',        count: Math.round(total * 0.016), accent: '#ffd700' },
    ];
});
</script>

<template>
    <Head title="Recruitment" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">work</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">RECRUITMENT GAZETTE</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Recruitment</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Civil-service vacancies gazetted to the public careers bulletin — applicants in a single institutional pipeline.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button v-if="canManage" @click="showCreatePanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">work</span>
                            Post New Job
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- ── KPI tiles ── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Total postings',  val: stats.total,       sub: 'All time',          cls: 'icon-brand',   icon: 'work' },
                        { label: 'Open positions',  val: stats.open,        sub: 'Accepting apps',    cls: 'icon-cyan',    icon: 'how_to_reg' },
                        { label: 'Applicants',      val: stats.applicants,  sub: 'Across all roles',  cls: 'icon-magenta', icon: 'groups' },
                        { label: 'Closing soon',    val: stats.urgentCount, sub: 'Within 7 days',     cls: 'icon-gold',    icon: 'schedule' },
                    ]" :key="card.label"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                        <div class="icon-tile" :class="card.cls">
                            <span class="material-symbols-outlined">{{ card.icon }}</span>
                        </div>
                        <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1 text-[28px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                        <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                    </div>
                </div>

                <!-- ── Pipeline funnel ── -->
                <div v-if="stats.applicants > 0" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 animate-reveal-up">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-[15px] font-black text-primary">Hiring funnel</h3>
                        <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Indicative conversion</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant mb-5">Where applicants land across the pipeline. Last stage gold-accented.</p>

                    <div class="space-y-2.5">
                        <div v-for="(stage, i) in pipeline" :key="stage.stage"
                             class="flex items-center gap-3"
                             :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s`">
                            <span class="w-32 flex-shrink-0 text-[12px] font-bold text-on-surface">{{ stage.stage }}</span>
                            <div class="flex-1 h-6 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-3"
                                     :style="`width:${Math.max(2, (stage.count / Math.max(1, pipeline[0].count)) * 100)}%;background:linear-gradient(90deg,${stage.accent}cc,${stage.accent})`">
                                    <span class="text-[10px] font-black text-white tabular-nums">{{ stage.count }}</span>
                                </div>
                            </div>
                            <span class="w-12 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ Math.round((stage.count / Math.max(1, pipeline[0].count)) * 100) }}%</span>
                        </div>
                    </div>
                </div>

                <!-- ── Job postings ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                    <!-- Filter row -->
                    <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button v-for="opt in [
                                { id: 'all',    label: 'All',    icon: 'list_alt' },
                                { id: 'open',   label: 'Open',   icon: 'how_to_reg' },
                                { id: 'draft',  label: 'Draft',  icon: 'edit_note' },
                                { id: 'closed', label: 'Closed', icon: 'archive' },
                                { id: 'filled', label: 'Filled', icon: 'check_circle' },
                            ]" :key="opt.id" @click="filter = opt.id"
                                    :class="['inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-[11.5px] font-black uppercase tracking-wide transition-all',
                                              filter === opt.id
                                                ? 'border-secondary bg-secondary text-white shadow-glow-sm'
                                                : 'border-outline-variant text-on-surface-variant hover:border-secondary/40']">
                                <span class="material-symbols-outlined text-[13px]">{{ opt.icon }}</span>
                                {{ opt.label }}
                            </button>
                        </div>
                        <div class="relative ml-auto w-full sm:w-60">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">search</span>
                            <input v-model="search" placeholder="Search title or description…"
                                   class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="!filteredJobs.length" class="px-6 py-16">
                        <EmptyState title="No job postings"
                                    description="Create a new posting to start building your applicant pipeline."
                                    icon="work_off">
                            <template v-if="canManage" #action>
                                <button @click="showCreatePanel = true"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                        style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    New posting
                                </button>
                            </template>
                        </EmptyState>
                    </div>

                    <!-- Job cards grid -->
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-5">
                        <Link v-for="(job, i) in filteredJobs" :key="job.id"
                              :href="route('jobs.show', job.id)"
                              class="group rounded-2xl border bg-surface-container-low/30 p-5 transition-all hover:-translate-y-0.5 hover:shadow-md hover:border-secondary/30 flex flex-col"
                              :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.04}s;border-left:3px solid ${statusDot(job.status)};`">

                            <!-- Status + title -->
                            <div class="flex items-start justify-between gap-2 mb-3">
                                <h3 class="text-[14px] font-black text-primary leading-tight pr-1 group-hover:text-secondary transition-colors">{{ job.title }}</h3>
                                <StatusPill :status="job.status || 'draft'" class="flex-shrink-0" />
                            </div>

                            <!-- Description preview -->
                            <p class="text-[12px] text-on-surface-variant leading-relaxed line-clamp-3 mb-4 flex-1">{{ job.description }}</p>

                            <!-- Stats row -->
                            <div class="flex items-center justify-between pt-3 border-t border-outline-variant/40">
                                <div class="flex items-center gap-1.5">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-lg" style="background:rgba(217,18,227,0.12)">
                                        <span class="material-symbols-outlined text-[14px]" style="color:#d912e3;font-variation-settings:'FILL' 1">groups</span>
                                    </span>
                                    <span class="text-[14px] font-black text-primary tabular-nums">{{ job.applicants_count ?? 0 }}</span>
                                    <span class="text-[10.5px] font-bold text-on-surface-variant">applicant{{ (job.applicants_count ?? 0) === 1 ? '' : 's' }}</span>
                                </div>
                                <span class="inline-flex items-center gap-1 text-[11px]" :class="toneCls(closeWindow(job.closes_at).tone)">
                                    <span v-if="job.closes_at" class="material-symbols-outlined text-[13px]">schedule</span>
                                    {{ closeWindow(job.closes_at).text }}
                                </span>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ── Create posting slide-panel ── -->
            <SlidePanel :open="showCreatePanel" title="Create job posting" size="lg" @close="showCreatePanel = false">
                <form @submit.prevent="submit" class="space-y-5 p-6">

                    <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                        <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                        <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                            Posted roles appear on the public careers portal immediately. Add closing dates to give the pipeline a clean cut-off — open-ended roles never archive automatically.
                        </p>
                    </div>

                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Job title <span class="text-rose-500">*</span></label>
                        <input v-model="form.title" type="text" required placeholder="e.g. Senior Backend Engineer"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                               :class="{ 'border-rose-400': form.errors.title }"/>
                        <p v-if="form.errors.title" class="mt-1 text-[11px] text-rose-500">{{ form.errors.title }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Description <span class="text-rose-500">*</span></label>
                        <textarea v-model="form.description" rows="8" required
                                  placeholder="Describe the role, responsibilities, required qualifications, and benefits…"
                                  class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20 resize-none"
                                  :class="{ 'border-rose-400': form.errors.description }"></textarea>
                        <p v-if="form.errors.description" class="mt-1 text-[11px] text-rose-500">{{ form.errors.description }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Closing date <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(blank = open until filled)</span></label>
                        <input v-model="form.closes_at" type="date"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" @click="showCreatePanel = false"
                                class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                            Cancel
                        </button>
                        <button @click="submit" :disabled="form.processing"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                                style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                            <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            <span v-else class="material-symbols-outlined text-[16px]">work</span>
                            Publish posting
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>
