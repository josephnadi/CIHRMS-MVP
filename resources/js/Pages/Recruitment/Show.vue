<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import KanbanBoard from '@/Components/KanbanBoard.vue';

const props = defineProps({
    job:          Object,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('recruitment.manage');
});

// ── Send-offer flow ──────────────────────────────────────────────────────────
const offerApplicant = ref(null);
const offerForm = useForm({
    salary:     '',
    start_date: '',
    expires_in: 14,
});

const openOffer = (applicant) => {
    offerApplicant.value = applicant;
    offerForm.reset();
    offerForm.expires_in = 14;
};

const submitOffer = () => {
    if (!offerApplicant.value) return;
    offerForm.post(route('applicants.sendOffer', offerApplicant.value.id), {
        preserveScroll: true,
        onSuccess: () => { offerApplicant.value = null; },
    });
};

const envelopePill = (applicant) => {
    const map = {
        sent:      { bg: 'rgba(217,119,6,0.10)',  fg: '#d97706', label: 'Offer sent' },
        viewed:    { bg: 'rgba(0,81,213,0.10)',   fg: '#0051d5', label: 'Offer viewed' },
        completed: { bg: 'rgba(5,150,105,0.10)',  fg: '#059669', label: 'Offer signed' },
        declined:  { bg: 'rgba(220,38,38,0.10)',  fg: '#dc2626', label: 'Offer declined' },
        voided:    { bg: 'rgba(107,114,128,0.10)',fg: '#6b7280', label: 'Offer voided' },
    };
    return map[applicant.esign_status] ?? null;
};

const j = computed(() => props.job?.data ?? props.job);

const applicants = computed(() => j.value?.applicants?.data ?? j.value?.applicants ?? []);

const stages = [
    { id: 'applied',     label: 'Applied',     color: 'blue'   },
    { id: 'shortlisted', label: 'Shortlisted', color: 'violet' },
    { id: 'interviewed', label: 'Interviewed', color: 'amber'  },
    { id: 'offered',     label: 'Offered',     color: 'green'  },
    { id: 'hired',       label: 'Hired',       color: 'green'  },
    { id: 'rejected',    label: 'Rejected',    color: 'red'    },
];

const columns = computed(() =>
    stages.map(stage => ({
        ...stage,
        items: applicants.value.filter(a => a.status === stage.id),
    })),
);

const moveApplicant = ({ itemId, toColumnId }) => {
    router.patch(route('applicants.update', itemId), { status: toColumnId }, {
        preserveScroll: true,
    });
};

const formatDate = (d) => {
    if (!d) return 'Open until filled';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const daysAgo = (d) => {
    if (!d) return '';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 86400000);
    if (diff === 0) return 'today';
    if (diff === 1) return '1d ago';
    return `${diff}d ago`;
};

const initials = (name) => {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
};
</script>

<template>
    <Head :title="j.title" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                        <Link :href="route('jobs.index')" class="hover:text-secondary">Recruitment</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>{{ j.title }}</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">{{ j.title }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-[12px] text-on-surface-variant">
                        <StatusBadge :status="j.status" type="recruitment" />
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px]">event</span>
                            {{ formatDate(j.closes_at) }}
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px]">groups</span>
                            {{ applicants.length }} applicants
                        </span>
                    </div>
                </div>
                <Link
                    :href="route('jobs.index')"
                    class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                >
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                    Back
                </Link>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Description -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-3">Job Description</h3>
                <p class="text-[13px] text-on-surface whitespace-pre-line leading-relaxed">{{ j.description }}</p>
            </div>

            <!-- Applicant pipeline -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 flex flex-col">
                <div class="flex items-center justify-between mb-4 flex-shrink-0">
                    <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70">Applicant Pipeline</h3>
                    <span class="text-[11px] text-on-surface-variant/60">{{ applicants.length }} total</span>
                </div>

                <div v-if="applicants.length === 0" class="py-12 text-center">
                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/20">person_off</span>
                    <p class="mt-2 text-[13px] text-on-surface-variant">No applicants yet for this posting.</p>
                </div>

                <div v-else class="max-h-[calc(100vh-440px)] min-h-[320px] overflow-auto">
                <KanbanBoard
                    :columns="columns"
                    @move="moveApplicant"
                >
                    <template #card="{ item }">
                        <div class="flex items-start gap-2.5">
                            <div
                                class="h-8 w-8 flex-shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                                style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                            >
                                {{ initials(item.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] font-semibold text-on-surface leading-tight truncate">{{ item.name }}</p>
                                <p class="text-[11px] text-on-surface-variant/60 truncate">{{ item.email }}</p>
                                <div class="mt-1.5 flex items-center gap-2 flex-wrap">
                                    <span class="text-[10px] text-on-surface-variant/60">{{ daysAgo(item.created_at) }}</span>
                                    <a
                                        v-if="item.cv_path"
                                        :href="`/storage/${item.cv_path}`"
                                        target="_blank"
                                        @click.stop
                                        class="text-[10px] font-semibold text-secondary hover:underline flex items-center gap-0.5"
                                    >
                                        <span class="material-symbols-outlined text-[12px]">description</span>
                                        CV
                                    </a>
                                    <span
                                        v-if="envelopePill(item)"
                                        class="rounded-full px-1.5 py-0.5 text-[9.5px] font-black uppercase tracking-[0.10em]"
                                        :style="`background:${envelopePill(item).bg};color:${envelopePill(item).fg}`"
                                    >
                                        {{ envelopePill(item).label }}
                                    </span>
                                </div>
                                <div v-if="canManage && ['shortlisted','interviewed','offered'].includes(item.status) && !item.esign_envelope_id" class="mt-2">
                                    <button
                                        @click.stop="openOffer(item)"
                                        class="rounded-md border border-secondary/30 bg-secondary/5 px-2 py-1 text-[10px] font-bold text-secondary hover:bg-secondary/10 transition-colors flex items-center gap-1"
                                    >
                                        <span class="material-symbols-outlined text-[12px]">draw</span>
                                        Send offer for e-sign
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </KanbanBoard>
                </div>

                <p v-if="!canManage" class="mt-4 text-[11px] text-on-surface-variant/60 text-center italic">
                    You can view this pipeline but cannot change applicant status.
                </p>
            </div>
        </div>

        <!-- ── Send-offer modal ── -->
        <Teleport to="body">
            <div
                v-if="offerApplicant"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                @click.self="offerApplicant = null"
            >
                <div class="w-full max-w-md rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-2xl overflow-hidden">
                    <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                        <div>
                            <h3 class="text-[15px] font-black text-on-surface">Send offer letter</h3>
                            <p class="text-[12px] text-on-surface-variant">to {{ offerApplicant.name }}</p>
                        </div>
                        <button @click="offerApplicant = null" class="rounded-lg p-1 hover:bg-surface-container-low">
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <form @submit.prevent="submitOffer" class="space-y-4 p-5">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Annual gross salary (GHS)</label>
                            <input
                                v-model="offerForm.salary"
                                type="number" step="0.01" min="0"
                                class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]"
                                placeholder="e.g. 60000"
                            />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Proposed start date</label>
                            <input
                                v-model="offerForm.start_date"
                                type="date"
                                class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]"
                            />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Offer valid for (days)</label>
                            <input
                                v-model.number="offerForm.expires_in"
                                type="number" min="1" max="30"
                                class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]"
                            />
                        </div>

                        <p class="text-[11px] text-on-surface-variant/70 leading-relaxed">
                            The offer letter will be rendered as PDF and dispatched through your active e-sign provider.
                            The candidate will receive an email with the signing link.
                        </p>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" @click="offerApplicant = null" class="rounded-xl border border-outline-variant/60 px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low">Cancel</button>
                            <button
                                type="submit"
                                :disabled="offerForm.processing"
                                class="rounded-xl px-4 py-2 text-[12px] font-bold text-white shadow-glow-sm hover:-translate-y-px transition-all disabled:opacity-60 flex items-center gap-1.5"
                                style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                            >
                                <span class="material-symbols-outlined text-[14px]">send</span>
                                {{ offerForm.processing ? 'Sending…' : 'Send for signature' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>
