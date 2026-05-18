<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    enrolments:     Object, // { data: EnrolmentResource[] }
    certifications: Object, // { data: CertificationResource[] }
    stats:          Object, // { in_progress, completed, certs, expiring }
    activeModule:   String,
});

// â”€â”€ Auth â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const page = usePage();
const authUser = computed(() => page.props.auth?.user ?? null);
const userName = computed(() => authUser.value?.name ?? 'Learner');
const firstName = computed(() => userName.value.split(' ')[0]);

// â”€â”€ Data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const enrolments     = computed(() => props.enrolments?.data     ?? []);
const certifications = computed(() => props.certifications?.data ?? []);

const inProgress  = computed(() => enrolments.value.filter(e => e.status !== 'completed' && e.status_label?.toLowerCase() !== 'completed'));
const completed   = computed(() => enrolments.value.filter(e => e.status === 'completed' || e.status_label?.toLowerCase() === 'completed'));

// â”€â”€ Progress modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const progressEnrolment = ref(null);
const progressForm      = useForm({ progress_pct: 0, final_score: '' });

const openProgress = (e) => {
    progressEnrolment.value = e;
    progressForm.progress_pct = e.progress_pct ?? 0;
    progressForm.final_score  = e.final_score ?? '';
};

const submitProgress = () => {
    progressForm.patch(route('learning.enrolments.progress', progressEnrolment.value.id), {
        preserveScroll: true,
        onSuccess: () => { progressEnrolment.value = null; },
    });
};

// â”€â”€ Add-cert SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showAddCert = ref(false);
const certForm = useForm({
    employee_id:      null,
    course_id:        '',
    name:             '',
    issuer:           '',
    credential_id:    '',
    issued_at:        '',
    expires_at:       '',
    verification_url: '',
});

const myEmployeeId = computed(() =>
    enrolments.value[0]?.employee_id
    ?? certifications.value[0]?.employee_id
    ?? null,
);

const submitCert = () => {
    certForm.employee_id = myEmployeeId.value;
    certForm.post(route('learning.certifications.store'), {
        preserveScroll: true,
        onSuccess: () => { showAddCert.value = false; certForm.reset(); },
    });
};

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const progressColor = (pct) => {
    if ((pct ?? 0) >= 80) return '#059669';
    if ((pct ?? 0) >= 40) return '#1a237e';
    if ((pct ?? 0) > 0)   return '#d97706';
    return '#9ca3af';
};

const expiryTone = (days) => {
    if (days === null || days === undefined) return null;
    if (days < 0)   return { bg: 'rgba(220,38,38,0.10)',  fg: '#dc2626', label: 'Expired' };
    if (days <= 30) return { bg: 'rgba(217,119,6,0.10)',  fg: '#d97706', label: `${days}d left` };
    if (days <= 60) return { bg: 'rgba(217,119,6,0.06)',  fg: '#d97706', label: `${days}d left` };
    return { bg: 'rgba(5,150,105,0.10)', fg: '#059669', label: 'Valid' };
};

const formatDate = (d) => {
    if (!d) return 'â€“';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const categoryIcons = {
    technical:   'terminal',
    leadership:  'supervisor_account',
    compliance:  'gavel',
    wellness:    'self_improvement',
    onboarding:  'waving_hand',
    soft_skills: 'psychology',
    other:       'category',
};

// SVG progress ring helper
const ringProps = (pct, r = 28) => {
    const circ = 2 * Math.PI * r;
    const filled = circ * Math.min(100, Math.max(0, pct ?? 0)) / 100;
    return { circ, filled, dash: `${filled} ${circ}` };
};
</script>

<template>
    <Head title="My Learning" />
    <div data-page-root="true">
            <!-- â”€â”€ Header â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                            <span>Learning</span>
                            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                            <span>My Learning</span>
                        </div>
                        <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">My Learning</h2>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Track your enrolments, log progress, and store certifications.
                        </p>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <Link
                            :href="route('learning.catalog')"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                        >
                            <span class="material-symbols-outlined text-[18px]">menu_book</span>
                            Browse Catalogue
                        </Link>
                        <button
                            @click="showAddCert = true"
                            :disabled="!myEmployeeId"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97] disabled:opacity-50"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">verified</span>
                            Add Certification
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6 animate-reveal-up">

                <!-- â”€â”€ Hero card â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div
                    class="relative rounded-2xl overflow-hidden p-6 md:p-8"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e,#1e5f9c)"
                >
                    <!-- Background pattern -->
                    <div class="absolute inset-0 opacity-5"
                         style="background-image:radial-gradient(circle at 20% 50%, white 1px, transparent 1px),radial-gradient(circle at 80% 20%, white 1px, transparent 1px);background-size:40px 40px"></div>

                    <div class="relative z-10 flex flex-col md:flex-row md:items-center gap-6">
                        <div class="flex-1">
                            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-white/50 mb-1">Learning Dashboard</p>
                            <h3 class="text-[1.4rem] font-black text-white leading-tight">
                                Welcome back, {{ firstName }}
                            </h3>
                            <p class="mt-1 text-[13px] text-white/70">
                                Keep the momentum going â€” every course brings you closer to your next milestone.
                            </p>
                        </div>

                        <!-- Inline stats -->
                        <div class="flex items-center gap-6 flex-shrink-0">
                            <div class="text-center">
                                <p class="text-[28px] font-black text-white leading-none">{{ stats?.in_progress ?? 0 }}</p>
                                <p class="text-[10px] font-bold uppercase tracking-[0.10em] text-white/60 mt-1">In Progress</p>
                            </div>
                            <div class="w-px h-10 bg-white/20"></div>
                            <div class="text-center">
                                <p class="text-[28px] font-black text-white leading-none">{{ stats?.completed ?? 0 }}</p>
                                <p class="text-[10px] font-bold uppercase tracking-[0.10em] text-white/60 mt-1">Completed</p>
                            </div>
                            <div class="w-px h-10 bg-white/20"></div>
                            <div class="text-center">
                                <p class="text-[28px] font-black text-white leading-none">{{ stats?.certs ?? 0 }}</p>
                                <p class="text-[10px] font-bold uppercase tracking-[0.10em] text-white/60 mt-1">Certs Earned</p>
                            </div>
                        </div>
                    </div>

                    <!-- Expiry alert strip -->
                    <div
                        v-if="(stats?.expiring ?? 0) > 0"
                        class="relative z-10 mt-5 flex items-center gap-2.5 rounded-xl bg-amber-500/20 border border-amber-400/30 px-4 py-2.5"
                    >
                        <span class="material-symbols-outlined text-[18px] text-amber-300" style="font-variation-settings:'FILL' 1">warning</span>
                        <p class="text-[12px] font-semibold text-amber-100">
                            {{ stats.expiring }} certification{{ stats.expiring === 1 ? '' : 's' }} expiring within 60 days â€” renew soon.
                        </p>
                    </div>
                </div>

                <!-- â”€â”€ Stat cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div
                        v-for="(s, i) in [
                            { label: 'In Progress',       value: stats?.in_progress ?? 0, icon: 'play_arrow',  color: '26, 35, 126'    },
                            { label: 'Completed',         value: stats?.completed   ?? 0, icon: 'task_alt',    color: '5,150,105'   },
                            { label: 'Certifications',    value: stats?.certs       ?? 0, icon: 'verified',    color: '124,92,255'  },
                            { label: 'Expiring (60 days)',value: stats?.expiring    ?? 0, icon: 'schedule',    color: '217,119,6'   },
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

                <!-- â”€â”€ Active enrolments â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Active Enrolments</h3>
                        <span class="rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">{{ inProgress.length }}</span>
                    </div>

                    <div v-if="inProgress.length" class="grid gap-4 md:grid-cols-2">
                        <div
                            v-for="e in inProgress"
                            :key="e.id"
                            class="group rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 card-lift transition-all"
                        >
                            <div class="flex items-start gap-4">
                                <!-- Progress ring SVG -->
                                <div class="relative flex-shrink-0 h-16 w-16">
                                    <svg viewBox="0 0 68 68" class="h-16 w-16 -rotate-90">
                                        <circle cx="34" cy="34" r="28" fill="none" stroke="currentColor"
                                                class="text-outline-variant/30" stroke-width="5" />
                                        <circle
                                            cx="34" cy="34" r="28" fill="none"
                                            :stroke="progressColor(e.progress_pct)"
                                            stroke-width="5"
                                            stroke-linecap="round"
                                            :stroke-dasharray="ringProps(e.progress_pct).dash"
                                            style="transition:stroke-dasharray 0.6s ease"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-[13px] font-black text-on-surface">{{ e.progress_pct ?? 0 }}%</span>
                                    </div>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <!-- Status badge -->
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.08em] mb-1"
                                        :style="`background:${e.status_color}1a;color:${e.status_color}`"
                                    >{{ e.status_label }}</span>

                                    <h4 class="text-[14px] font-black text-on-surface leading-tight line-clamp-2">{{ e.course?.title ?? 'â€“' }}</h4>
                                    <p v-if="e.course?.provider" class="mt-0.5 font-mono text-[10.5px] text-on-surface-variant/60">{{ e.course.provider }}</p>

                                    <!-- Meta chips -->
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-on-surface-variant/70">
                                        <span v-if="e.course?.duration_label" class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[13px]">schedule</span>
                                            {{ e.course.duration_label }}
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[13px]">today</span>
                                            Enrolled {{ new Date(e.enrolled_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) }}
                                        </span>
                                        <span v-if="e.due_at" class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[13px]">event</span>
                                            Due {{ formatDate(e.due_at) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Progress bar -->
                            <div class="mt-4">
                                <div class="h-1.5 w-full rounded-full bg-outline-variant/30 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all duration-500"
                                        :style="`width:${e.progress_pct ?? 0}%;background:${progressColor(e.progress_pct)}`"
                                    ></div>
                                </div>
                            </div>

                            <!-- CTA footer -->
                            <div class="mt-4 flex items-center gap-2 border-t border-outline-variant/30 pt-3">
                                <button
                                    @click="openProgress(e)"
                                    class="btn-shimmer flex-1 flex items-center justify-center gap-2 rounded-xl py-2 text-[12px] font-bold text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                >
                                    <span class="material-symbols-outlined text-[15px]">play_arrow</span>
                                    Continue Learning
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-10">
                        <EmptyState
                            title="No active enrolments"
                            description="Browse the catalogue and enrol in a course to start your learning journey."
                            icon="play_lesson"
                        >
                            <template #action>
                                <Link
                                    :href="route('learning.catalog')"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                >
                                    <span class="material-symbols-outlined text-[18px]">menu_book</span>
                                    Browse Catalogue
                                </Link>
                            </template>
                        </EmptyState>
                    </div>
                </section>

                <!-- â”€â”€ Recommended for you (placeholder section) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Recommended for You</h3>
                        <Link :href="route('learning.catalog')" class="text-[12px] font-bold text-secondary hover:underline flex items-center gap-1">
                            View all <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                        </Link>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/40 bg-surface-container/40 border-dashed p-6 text-center">
                        <span class="material-symbols-outlined text-[32px] text-on-surface-variant/30" style="font-variation-settings:'FILL' 1">auto_awesome</span>
                        <p class="mt-2 text-[13px] font-semibold text-on-surface-variant">Personalised recommendations coming soon</p>
                        <p class="text-[12px] text-on-surface-variant/60 mt-1">Based on your skills inventory and role objectives.</p>
                        <Link :href="route('learning.catalog')" class="mt-3 inline-flex items-center gap-1.5 text-[12px] font-bold text-secondary hover:underline">
                            Explore the full catalogue
                            <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                        </Link>
                    </div>
                </section>

                <!-- â”€â”€ Certifications â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Certifications Earned</h3>
                        <span class="rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">{{ certifications.length }}</span>
                    </div>

                    <div v-if="certifications.length" class="grid gap-4 md:grid-cols-2">
                        <div
                            v-for="c in certifications"
                            :key="c.id"
                            class="group rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 card-lift"
                        >
                            <!-- Diploma-style header bar -->
                            <div
                                class="flex items-start gap-3 pb-3 mb-3 border-b border-outline-variant/30"
                                style="background:linear-gradient(90deg,rgba(124,92,255,0.05),transparent)"
                            >
                                <div class="h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(124,92,255,0.12)">
                                    <span class="material-symbols-outlined text-[20px]" style="color:#7c5cff;font-variation-settings:'FILL' 1">verified</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-[13.5px] font-black text-on-surface leading-tight line-clamp-1">{{ c.name }}</h4>
                                    <p v-if="c.issuer" class="text-[11px] text-on-surface-variant/70 mt-0.5">{{ c.issuer }}</p>
                                </div>
                                <!-- Expiry badge -->
                                <span
                                    v-if="expiryTone(c.days_to_expiry)"
                                    class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.08em] flex-shrink-0"
                                    :style="`background:${expiryTone(c.days_to_expiry).bg};color:${expiryTone(c.days_to_expiry).fg}`"
                                >{{ expiryTone(c.days_to_expiry).label }}</span>
                            </div>

                            <!-- Meta -->
                            <div class="grid grid-cols-2 gap-3 text-[11px]">
                                <div v-if="c.credential_id">
                                    <p class="text-on-surface-variant/60 font-semibold mb-0.5">Credential ID</p>
                                    <p class="font-mono font-bold text-on-surface truncate">{{ c.credential_id }}</p>
                                </div>
                                <div v-if="c.issued_at">
                                    <p class="text-on-surface-variant/60 font-semibold mb-0.5">Issued</p>
                                    <p class="font-bold text-on-surface">{{ c.issued_at }}</p>
                                </div>
                                <div v-if="c.expires_at">
                                    <p class="text-on-surface-variant/60 font-semibold mb-0.5">Expires</p>
                                    <p class="font-bold text-on-surface">{{ c.expires_at }}</p>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="mt-3 flex items-center gap-2">
                                <a
                                    v-if="c.verification_url"
                                    :href="c.verification_url"
                                    target="_blank"
                                    class="flex items-center gap-1.5 rounded-lg border border-outline-variant/60 px-2.5 py-1.5 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors"
                                >
                                    <span class="material-symbols-outlined text-[13px]">open_in_new</span>
                                    Verify
                                </a>
                            </div>
                        </div>
                    </div>

                    <div v-else class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-10">
                        <EmptyState
                            title="No certifications yet"
                            description="Complete a course or add an external certification to build your credential portfolio."
                            icon="verified"
                        >
                            <template #action>
                                <button
                                    v-if="myEmployeeId"
                                    @click="showAddCert = true"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                >
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    Add External Certification
                                </button>
                            </template>
                        </EmptyState>
                    </div>
                </section>

                <!-- â”€â”€ Completed courses (compact) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
                <section v-if="completed.length">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Completed Courses</h3>
                        <span class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700">{{ completed.length }}</span>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden">
                        <div class="divide-y divide-outline-variant/30">
                            <div
                                v-for="e in completed"
                                :key="e.id"
                                class="flex items-center gap-4 px-5 py-3.5 hover:bg-surface-container/40 transition-colors"
                            >
                                <div
                                    class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl"
                                    :style="`background:${e.course?.category_color ?? '#059669'}1a`"
                                >
                                    <span class="material-symbols-outlined text-[18px]" :style="`color:${e.course?.category_color ?? '#059669'}`" style="font-variation-settings:'FILL' 1">
                                        {{ categoryIcons[e.course?.category] ?? 'school' }}
                                    </span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-[13px] font-bold text-on-surface truncate">{{ e.course?.title ?? 'â€“' }}</h4>
                                    <p class="text-[11px] text-on-surface-variant/60 mt-0.5">
                                        {{ e.course?.provider ?? '' }}
                                        <span v-if="e.final_score" class="ml-2 text-emerald-600 font-bold">Score: {{ e.final_score }}%</span>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[10.5px] font-bold text-emerald-700">
                                        <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">task_alt</span>
                                        Completed
                                    </span>
                                    <a
                                        v-if="e.certificate_path"
                                        :href="`/storage/${e.certificate_path}`"
                                        target="_blank"
                                        class="flex items-center gap-1 rounded-lg border border-outline-variant/60 px-2.5 py-1.5 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors"
                                    >
                                        <span class="material-symbols-outlined text-[13px]">download</span>
                                        Certificate
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>

            <!-- â”€â”€ Update Progress SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <SlidePanel
                :open="!!progressEnrolment"
                :title="progressEnrolment ? `Update Progress Â· ${progressEnrolment.course?.title ?? 'Course'}` : 'Update Progress'"
                size="md"
                @close="progressEnrolment = null"
            >
                <form v-if="progressEnrolment" @submit.prevent="submitProgress" class="space-y-5 p-6">

                    <!-- Course info block -->
                    <div class="flex items-start gap-3 rounded-xl border border-outline-variant/60 bg-surface-container/40 p-3.5">
                        <div
                            class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl"
                            :style="`background:${progressEnrolment.course?.category_color ?? '#1a237e'}1a`"
                        >
                            <span class="material-symbols-outlined text-[20px]" :style="`color:${progressEnrolment.course?.category_color ?? '#1a237e'}`">school</span>
                        </div>
                        <div>
                            <p class="text-[13px] font-bold text-on-surface leading-tight">{{ progressEnrolment.course?.title }}</p>
                            <p v-if="progressEnrolment.course?.provider" class="mt-0.5 text-[11px] text-on-surface-variant/70">{{ progressEnrolment.course.provider }}</p>
                        </div>
                    </div>

                    <!-- Progress slider -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-[12px] font-semibold text-on-surface-variant">Progress</label>
                            <span class="text-[16px] font-black text-on-surface">{{ progressForm.progress_pct }}%</span>
                        </div>
                        <input
                            v-model.number="progressForm.progress_pct"
                            type="range"
                            min="0" max="100" step="1"
                            class="w-full accent-secondary"
                        />
                        <!-- Visual progress bar -->
                        <div class="mt-2 h-2 rounded-full bg-outline-variant/30 overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :style="`width:${progressForm.progress_pct}%;background:${progressColor(progressForm.progress_pct)}`"
                            ></div>
                        </div>
                        <!-- Quick-set buttons -->
                        <div class="mt-3 flex items-center gap-2">
                            <button
                                v-for="p in [25, 50, 75, 100]"
                                :key="p"
                                type="button"
                                @click="progressForm.progress_pct = p"
                                class="flex-1 rounded-lg border px-2 py-1.5 text-[11px] font-bold transition-all"
                                :class="progressForm.progress_pct === p
                                    ? 'border-secondary/50 bg-secondary/10 text-secondary'
                                    : 'border-outline-variant/60 text-on-surface-variant hover:bg-surface-container'"
                            >{{ p }}%</button>
                        </div>
                    </div>

                    <!-- Final score (only at 100%) -->
                    <div v-if="progressForm.progress_pct >= 100">
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Final Score (optional)</label>
                        <input
                            v-model="progressForm.final_score"
                            type="number"
                            step="0.01" min="0" max="100"
                            placeholder="e.g. 87.5"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                        <p class="mt-1.5 flex items-center gap-1.5 text-[11px] text-emerald-600">
                            <span class="material-symbols-outlined text-[14px]">check_circle</span>
                            Setting 100% will mark this course as completed and add its skills to your profile.
                        </p>
                    </div>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="progressEnrolment = null"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        >Cancel</button>
                        <button
                            @click="submitProgress"
                            :disabled="progressForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="progressForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            Save Progress
                        </button>
                    </div>
                </template>
            </SlidePanel>

            <!-- â”€â”€ Add External Certification SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <SlidePanel
                :open="showAddCert"
                title="Add External Certification"
                size="md"
                @close="showAddCert = false"
            >
                <form @submit.prevent="submitCert" class="space-y-5 p-6">

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Certification Name <span class="text-red-500">*</span></label>
                        <input
                            v-model="certForm.name"
                            type="text"
                            required
                            maxlength="200"
                            placeholder="e.g. Certified Public Accountant (CPA)"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': certForm.errors.name }"
                        />
                        <p v-if="certForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ certForm.errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Issuing Organisation</label>
                            <input
                                v-model="certForm.issuer"
                                type="text"
                                maxlength="120"
                                placeholder="e.g. ACCA"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            />
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Credential ID</label>
                            <input
                                v-model="certForm.credential_id"
                                type="text"
                                maxlength="120"
                                placeholder="Unique ID or license no."
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Issue Date</label>
                            <input
                                v-model="certForm.issued_at"
                                type="date"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            />
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Expiry Date</label>
                            <input
                                v-model="certForm.expires_at"
                                type="date"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Verification URL</label>
                        <input
                            v-model="certForm.verification_url"
                            type="url"
                            maxlength="255"
                            placeholder="https://verify.example.com/â€¦"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="showAddCert = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        >Cancel</button>
                        <button
                            @click="submitCert"
                            :disabled="certForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="certForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            Save Certification
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>
