<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    enrolments:     Object,
    certifications: Object,
    stats:          Object,
    activeModule:   String,
});

const enrolments     = computed(() => props.enrolments?.data ?? []);
const certifications = computed(() => props.certifications?.data ?? []);

// ── Progress modal ───────────────────────────────────────────────────────────
const progressEnrolment = ref(null);
const progressForm = useForm({ progress_pct: 0, final_score: '' });

const openProgress = (e) => {
    progressEnrolment.value = e;
    progressForm.progress_pct = e.progress_pct;
    progressForm.final_score = e.final_score ?? '';
};
const submitProgress = () => {
    progressForm.patch(route('learning.enrolments.progress', progressEnrolment.value.id), {
        preserveScroll: true,
        onSuccess: () => { progressEnrolment.value = null; },
    });
};

// ── Add-cert modal ───────────────────────────────────────────────────────────
const showAddCert = ref(false);
const certForm = useForm({
    employee_id:      null, // filled by controller-side fallback if blank? no — server requires it
    course_id:        '',
    name:             '',
    issuer:           '',
    credential_id:    '',
    issued_at:        '',
    expires_at:       '',
    verification_url: '',
});

// Read employee_id from one of the existing enrolments (My Learning is always self-scoped).
const myEmployeeId = computed(() => enrolments.value[0]?.employee_id ?? certifications.value[0]?.employee_id ?? null);

const submitCert = () => {
    certForm.employee_id = myEmployeeId.value;
    certForm.post(route('learning.certifications.store'), {
        preserveScroll: true,
        onSuccess: () => { showAddCert.value = false; certForm.reset(); },
    });
};

const expiryTone = (days) => {
    if (days === null || days === undefined) return null;
    if (days < 0)   return { bg: 'rgba(220,38,38,0.10)',  fg: '#dc2626', label: 'Expired' };
    if (days <= 30) return { bg: 'rgba(217,119,6,0.10)',  fg: '#d97706', label: `${days}d left` };
    if (days <= 60) return { bg: 'rgba(217,119,6,0.06)',  fg: '#d97706', label: `${days}d left` };
    return { bg: 'rgba(5,150,105,0.10)', fg: '#059669', label: 'Valid' };
};
</script>

<template>
    <Head title="My Learning" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
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
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('learning.catalog')"
                        class="rounded-xl border border-outline-variant/60 px-4 py-2.5 text-[13px] font-bold text-on-surface-variant hover:bg-surface-container-low flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[16px]">menu_book</span>
                        Browse catalogue
                    </Link>
                    <button
                        @click="showAddCert = true"
                        :disabled="!myEmployeeId"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:-translate-y-px hover:shadow-glow transition-all disabled:opacity-50"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">verified</span>
                        Add certification
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stat strip -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="s in [
                    { label: 'In progress',     value: stats.in_progress, icon: 'play_arrow',  color: '#0051d5' },
                    { label: 'Completed',       value: stats.completed,   icon: 'task_alt',    color: '#059669' },
                    { label: 'Certifications',  value: stats.certs,       icon: 'verified',    color: '#7c3aed' },
                    { label: 'Expiring (60d)',  value: stats.expiring,    icon: 'schedule',    color: '#d97706' },
                ]" :key="s.label" class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" :style="`background:${s.color}1a`">
                            <span class="material-symbols-outlined text-[20px]" :style="`color:${s.color}`">{{ s.icon }}</span>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">{{ s.label }}</p>
                            <p class="text-[22px] font-black text-on-surface leading-none">{{ s.value }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enrolments -->
            <section class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest overflow-hidden">
                <div class="border-b border-outline-variant/40 px-5 py-4 flex items-center justify-between">
                    <h3 class="text-[14px] font-black tracking-tight text-on-surface">Enrolments</h3>
                    <span class="rounded-full bg-surface-container-low/60 px-2.5 py-1 text-[11px] font-bold text-on-surface-variant">{{ enrolments.length }} courses</span>
                </div>
                <div v-if="enrolments.length" class="divide-y divide-outline-variant/30">
                    <div v-for="e in enrolments" :key="e.id"
                         class="flex items-center gap-4 px-5 py-4 hover:bg-surface-container-low/40">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl"
                             :style="`background:${e.course?.category_color}1a`">
                            <span class="material-symbols-outlined text-[20px]" :style="`color:${e.course?.category_color}`">school</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-[13.5px] font-black text-on-surface truncate">{{ e.course?.title ?? '—' }}</h4>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.10em]"
                                    :style="`background:${e.status_color}1a;color:${e.status_color}`"
                                >{{ e.status_label }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-[11px] text-on-surface-variant/70 mb-1.5">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">{{ e.course?.category }}</span>
                                    {{ e.course?.category_label }}
                                </span>
                                <span v-if="e.course?.duration_label" class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">schedule</span>
                                    {{ e.course.duration_label }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">today</span>
                                    Enrolled {{ new Date(e.enrolled_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short' }) }}
                                </span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-outline-variant/30">
                                <div class="h-full rounded-full transition-all"
                                     :style="`width:${e.progress_pct}%;background:${e.status_color}`"></div>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                            <span class="text-[12px] font-black text-on-surface">{{ e.progress_pct }}%</span>
                            <button
                                v-if="e.status !== 'completed'"
                                @click="openProgress(e)"
                                class="rounded-lg border border-secondary/30 px-2.5 py-1 text-[11px] font-bold text-secondary hover:bg-secondary/8 flex items-center gap-1"
                            >
                                <span class="material-symbols-outlined text-[12px]">timeline</span>
                                Update
                            </button>
                            <a v-else-if="e.certificate_path" :href="`/storage/${e.certificate_path}`" target="_blank"
                               class="rounded-lg border border-emerald-500/30 px-2.5 py-1 text-[11px] font-bold text-emerald-600 hover:bg-emerald-500/8 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[12px]">download</span>
                                Cert
                            </a>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-12 text-center">
                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">play_lesson</span>
                    <p class="mt-2 text-[13px] font-semibold text-on-surface-variant">You haven't enrolled in any courses yet.</p>
                    <Link :href="route('learning.catalog')" class="mt-2 inline-block text-[12px] font-bold text-secondary hover:underline">Browse the catalogue →</Link>
                </div>
            </section>

            <!-- Certifications -->
            <section class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest overflow-hidden">
                <div class="border-b border-outline-variant/40 px-5 py-4 flex items-center justify-between">
                    <h3 class="text-[14px] font-black tracking-tight text-on-surface">Certifications</h3>
                    <span class="rounded-full bg-surface-container-low/60 px-2.5 py-1 text-[11px] font-bold text-on-surface-variant">{{ certifications.length }} total</span>
                </div>
                <div v-if="certifications.length" class="divide-y divide-outline-variant/30">
                    <div v-for="c in certifications" :key="c.id"
                         class="flex items-start gap-4 px-5 py-4 hover:bg-surface-container-low/40">
                        <span class="material-symbols-outlined text-[22px] text-amber-500 mt-0.5">verified</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-0.5">
                                <h4 class="text-[13.5px] font-black text-on-surface truncate">{{ c.name }}</h4>
                                <span v-if="expiryTone(c.days_to_expiry)"
                                      class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.10em]"
                                      :style="`background:${expiryTone(c.days_to_expiry).bg};color:${expiryTone(c.days_to_expiry).fg}`">
                                    {{ expiryTone(c.days_to_expiry).label }}
                                </span>
                            </div>
                            <p v-if="c.issuer" class="text-[11.5px] text-on-surface-variant/70">Issued by {{ c.issuer }}<span v-if="c.credential_id"> · ID: {{ c.credential_id }}</span></p>
                            <div class="mt-1 flex items-center gap-3 text-[11px] text-on-surface-variant/70">
                                <span v-if="c.issued_at">Issued {{ c.issued_at }}</span>
                                <span v-if="c.expires_at">· Expires {{ c.expires_at }}</span>
                                <a v-if="c.verification_url" :href="c.verification_url" target="_blank" class="text-secondary hover:underline ml-auto">Verify ↗</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-12 text-center">
                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">verified</span>
                    <p class="mt-2 text-[13px] font-semibold text-on-surface-variant">No certifications recorded yet.</p>
                    <button v-if="myEmployeeId" @click="showAddCert = true" class="mt-2 inline-block text-[12px] font-bold text-secondary hover:underline">Add your first one →</button>
                </div>
            </section>

        </div>

        <!-- Progress modal -->
        <Teleport to="body">
            <div v-if="progressEnrolment" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 backdrop-blur-sm p-4 pt-10" @click.self="progressEnrolment = null">
                <div class="w-full max-w-md rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-2xl overflow-hidden">
                    <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                        <div>
                            <h3 class="text-[15px] font-black text-on-surface">Update progress</h3>
                            <p class="text-[12px] text-on-surface-variant truncate">{{ progressEnrolment.course?.title }}</p>
                        </div>
                        <button @click="progressEnrolment = null" class="rounded-lg p-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[18px]">close</span></button>
                    </div>
                    <form @submit.prevent="submitProgress" class="space-y-4 p-5">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Progress: {{ progressForm.progress_pct }}%</label>
                            <input v-model.number="progressForm.progress_pct" type="range" min="0" max="100" step="1" class="w-full" />
                            <div class="mt-2 flex items-center gap-2">
                                <button type="button" v-for="p in [25, 50, 75, 100]" :key="p"
                                        @click="progressForm.progress_pct = p"
                                        class="rounded-lg border border-outline-variant/60 px-2.5 py-1 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container-low">
                                    {{ p }}%
                                </button>
                            </div>
                        </div>
                        <div v-if="progressForm.progress_pct >= 100">
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Final score (optional)</label>
                            <input v-model="progressForm.final_score" type="number" step="0.01" min="0" max="100" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            <p class="mt-1 text-[11px] text-emerald-600">Setting 100% will mark this course as completed and add its skills to your profile.</p>
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" @click="progressEnrolment = null" class="rounded-xl border border-outline-variant/60 px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low">Cancel</button>
                            <button type="submit" :disabled="progressForm.processing" class="rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60" style="background:linear-gradient(135deg,#0051d5,#316bf3)">{{ progressForm.processing ? 'Saving…' : 'Save progress' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Add-cert modal -->
        <Teleport to="body">
            <div v-if="showAddCert" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 backdrop-blur-sm p-4 pt-10" @click.self="showAddCert = false">
                <div class="w-full max-w-md rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-2xl overflow-hidden">
                    <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                        <h3 class="text-[15px] font-black text-on-surface">Add certification</h3>
                        <button @click="showAddCert = false" class="rounded-lg p-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[18px]">close</span></button>
                    </div>
                    <form @submit.prevent="submitCert" class="space-y-4 p-5">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Certification name</label>
                            <input v-model="certForm.name" required maxlength="200" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Issuer</label>
                                <input v-model="certForm.issuer" maxlength="120" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Credential ID</label>
                                <input v-model="certForm.credential_id" maxlength="120" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Issued</label>
                                <input v-model="certForm.issued_at" type="date" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Expires</label>
                                <input v-model="certForm.expires_at" type="date" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Verification URL</label>
                            <input v-model="certForm.verification_url" type="url" maxlength="255" placeholder="https://…" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" @click="showAddCert = false" class="rounded-xl border border-outline-variant/60 px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low">Cancel</button>
                            <button type="submit" :disabled="certForm.processing" class="rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60" style="background:linear-gradient(135deg,#0051d5,#316bf3)">{{ certForm.processing ? 'Saving…' : 'Add' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>
