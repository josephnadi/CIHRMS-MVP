<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    jobs:         Object,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('recruitment.manage');
});

const stats = computed(() => {
    const data = props.jobs?.data ?? [];
    return {
        total:      data.length,
        open:       data.filter(j => j.status === 'open').length,
        applicants: data.reduce((sum, j) => sum + (j.applicants_count ?? 0), 0),
        closed:     data.filter(j => j.status === 'closed').length,
    };
});

const filter = ref('all');

const filteredJobs = computed(() => {
    const data = props.jobs?.data ?? [];
    if (filter.value === 'all') return data;
    return data.filter(j => j.status === filter.value);
});

const showCreatePanel = ref(false);

const form = useForm({
    title:       '',
    description: '',
    closes_at:   '',
});

const submit = () => {
    form.post(route('jobs.store'), {
        onSuccess: () => {
            form.reset();
            showCreatePanel.value = false;
        },
    });
};

const formatDate = (d) => {
    if (!d) return 'Open until filled';
    return 'Closes ' + new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const daysUntilClose = (d) => {
    if (!d) return null;
    const diff = Math.ceil((new Date(d).getTime() - Date.now()) / 86400000);
    if (diff < 0) return { text: 'Closed', color: 'text-slate-500' };
    if (diff === 0) return { text: 'Closes today', color: 'text-red-600 font-bold' };
    if (diff <= 7) return { text: `${diff}d left`, color: 'text-red-600 font-semibold' };
    if (diff <= 30) return { text: `${diff}d left`, color: 'text-amber-700 font-semibold' };
    return { text: `${diff}d left`, color: 'text-on-surface-variant' };
};

// Job posting card top-stripe pool — disciplined cool family (matches Departments cards + avatar pools elsewhere)
const gradients = [
    'linear-gradient(135deg,#0a2647,#205295)',
    'linear-gradient(135deg,#205295,#7cb6e8)',
    'linear-gradient(135deg,#06192f,#0a2647)',
    'linear-gradient(135deg,#205295,#2c74b3)',
    'linear-gradient(135deg,#0a2647,#205295,#d912e3)',
    'linear-gradient(135deg,#205295,#12d9e3)',
];
const cardGradient = (id) => gradients[id % gradients.length];
</script>

<template>
    <Head title="Recruitment" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Recruitment</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Manage active job postings and applicant pipelines.
                    </p>
                </div>
                <button
                    v-if="canManage"
                    @click="showCreatePanel = true"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                    style="background:linear-gradient(135deg,#0a2647,#205295)"
                >
                    <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">work</span>
                    New Posting
                </button>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stats — Applicants gets gold (institutional pipeline metric the page exists to grow) -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard :value="stats.total" label="Total Postings" icon="work" color="navy" />
                <StatCard :value="stats.open" label="Open" icon="how_to_reg" color="green" />
                <StatCard :value="stats.applicants" label="Applicants" icon="groups" color="gold" />
                <StatCard :value="stats.closed" label="Closed" icon="archive" color="magenta" />
            </div>

            <!-- Filter pills -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="opt in [
                        { id: 'all',    label: 'All Postings', icon: 'list_alt' },
                        { id: 'open',   label: 'Open',         icon: 'how_to_reg' },
                        { id: 'draft',  label: 'Draft',        icon: 'edit_note' },
                        { id: 'closed', label: 'Closed',       icon: 'archive' },
                        { id: 'filled', label: 'Filled',       icon: 'check_circle' },
                    ]"
                    :key="opt.id"
                    @click="filter = opt.id"
                    :class="[
                        'flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-bold transition-all',
                        filter === opt.id
                            ? 'bg-secondary text-white shadow-glow-sm hover:-translate-y-px'
                            : 'border border-outline-variant text-on-surface-variant hover:bg-surface-container hover:border-secondary/30 hover:text-secondary',
                    ]"
                >
                    <span class="material-symbols-outlined text-[15px]" :style="filter === opt.id ? `font-variation-settings:'FILL' 1` : ''">{{ opt.icon }}</span>
                    {{ opt.label }}
                </button>
            </div>

            <!-- Jobs grid -->
            <div v-if="filteredJobs.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No job postings"
                    description="Create a new posting to start building your applicant pipeline."
                    icon="work_off"
                >
                    <template v-if="canManage" #action>
                        <button
                            @click="showCreatePanel = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0a2647,#205295)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            New Posting
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="job in filteredJobs"
                    :key="job.id"
                    :href="route('jobs.show', job.id)"
                    class="group rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card hover:shadow-lifted hover:-translate-y-0.5 transition-all overflow-hidden block"
                >
                    <div class="h-1.5 w-full" :style="`background:${cardGradient(job.id)}`"></div>
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <h3 class="text-[14px] font-bold text-on-surface leading-snug group-hover:text-secondary transition-colors">
                                {{ job.title }}
                            </h3>
                            <StatusBadge :status="job.status" type="recruitment" />
                        </div>

                        <p class="text-[12px] text-on-surface-variant line-clamp-3 mb-4 leading-relaxed">
                            {{ job.description }}
                        </p>

                        <div class="flex items-center justify-between pt-3 border-t border-outline-variant/40">
                            <div class="flex items-center gap-1.5">
                                <span class="flex h-5 w-5 items-center justify-center rounded" style="background:rgba(255,215,0,0.14)">
                                    <span class="material-symbols-outlined text-[13px]" style="color:#b88a08;font-variation-settings:'FILL' 1">groups</span>
                                </span>
                                <span class="text-[13px] font-black text-on-surface tabular-nums">{{ job.applicants_count ?? 0 }}</span>
                                <span class="text-[11px] text-on-surface-variant/60">applicants</span>
                            </div>
                            <span :class="['flex items-center gap-1 text-[11px]', daysUntilClose(job.closes_at)?.color ?? 'text-on-surface-variant']">
                                <span v-if="job.closes_at" class="material-symbols-outlined text-[13px]">schedule</span>
                                {{ daysUntilClose(job.closes_at)?.text ?? formatDate(job.closes_at) }}
                            </span>
                        </div>
                    </div>
                </Link>
            </div>
        </div>

        <!-- Create Posting -->
        <SlidePanel :open="showCreatePanel" title="Create Job Posting" size="lg" @close="showCreatePanel = false">
            <form @submit.prevent="submit" class="space-y-5 p-6">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Job Title <span class="text-red-500">*</span></label>
                    <input
                        v-model="form.title"
                        type="text"
                        placeholder="e.g. Senior Backend Engineer"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.title }"
                    />
                    <p v-if="form.errors.title" class="mt-1 text-[11px] text-red-500">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description <span class="text-red-500">*</span></label>
                    <textarea
                        v-model="form.description"
                        rows="8"
                        placeholder="Describe the role, responsibilities, required qualifications, and benefitsâ€¦"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                        :class="{ 'border-red-400': form.errors.description }"
                    ></textarea>
                    <p v-if="form.errors.description" class="mt-1 text-[11px] text-red-500">{{ form.errors.description }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Closing Date</label>
                    <input
                        v-model="form.closes_at"
                        type="date"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    />
                    <p v-if="form.errors.closes_at" class="mt-1 text-[11px] text-red-500">{{ form.errors.closes_at }}</p>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showCreatePanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submit"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0a2647,#205295)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>Publish Posting</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
