<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import GlossaryText from '@/Components/GlossaryText.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    case:         Object, // OnboardingCaseResource (possibly wrapped in { data: … })
    activeModule: String,
});

// ── Unwrap resource wrappers ──────────────────────────────────────────────────
const C = computed(() => props.case?.data ?? props.case ?? {});

const tasks = computed(() => C.value.tasks ?? []);

// Group tasks by area_label, preserving first-seen order.
const taskGroups = computed(() => {
    const groups = {};
    for (const t of tasks.value) {
        const key = t.area_label ?? 'Other';
        (groups[key] ??= []).push(t);
    }
    return groups;
});

const tasksDone = computed(() =>
    tasks.value.filter(t => t.status === 'completed' || t.status === 'skipped').length
);
const tasksTotal = computed(() => tasks.value.length);

// ── Helpers ───────────────────────────────────────────────────────────────────
const pct = (v) => Math.round((Number(v) || 0) * 100) + '%';

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

// ── Area icon map ─────────────────────────────────────────────────────────────
const areaIcon = (label) => ({
    'IT Provisioning':         'computer',
    'HR Orientation':          'badge',
    'Policy Acknowledgement':  'gavel',
    'Learning':                'school',
    'Mentorship':              'diversity_3',
    'Department Introduction': 'groups',
    'Other':                   'checklist',
})[label] ?? 'checklist';

// ── Task actions ──────────────────────────────────────────────────────────────
const completeTask = (task) => {
    router.post(
        route('onboarding.tasks.update', { case: C.value.id, task: task.id }),
        { action: 'complete', reason: '' },
        { preserveScroll: true },
    );
};

const skipForm  = useForm({ action: 'skip', reason: '' });
const showSkipId = ref(null);
const submitSkip = () => skipForm.post(
    route('onboarding.tasks.update', { case: C.value.id, task: showSkipId.value }),
    {
        preserveScroll: true,
        onSuccess: () => { showSkipId.value = null; skipForm.reset('reason'); },
    },
);

// ── Case actions ──────────────────────────────────────────────────────────────
const complete = () => router.post(route('onboarding.complete', C.value.id), {}, { preserveScroll: true });

const cancelForm = useForm({ reason: '' });
const showCancel  = ref(false);
const submitCancel = () => cancelForm.post(route('onboarding.cancel', C.value.id), {
    preserveScroll: true,
    onSuccess: () => { showCancel.value = false; cancelForm.reset(); },
});
</script>

<template>
    <Head :title="`Case ${C.reference ?? ''}`" />
    <div data-page-root="true">
            <!-- ── Header ─────────────────────────────────────────────────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('onboarding.index')"
                            class="flex h-9 w-9 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                        </Link>
                        <div>
                            <h2 class="text-[1.5rem] font-black tracking-tight text-on-surface leading-tight">Onboarding Case</h2>
                            <p class="mt-0.5 text-[13px] text-on-surface-variant">
                                <span class="font-mono">{{ C.reference }}</span>
                                <span class="mx-1.5 text-on-surface-variant/40">·</span>
                                {{ C.employee?.name ?? '—' }}
                            </p>
                        </div>
                    </div>
                    <StatusBadge :status="C.status" :label="C.status_label" />
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- ── Hero card ───────────────────────────────────────────────────── -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <div class="flex flex-wrap items-start gap-6">

                        <!-- Identity block -->
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-3 mb-2">
                                <div class="h-12 w-12 rounded-2xl bg-secondary/10 flex items-center justify-center flex-shrink-0">
                                    <span class="material-symbols-outlined text-[24px] text-secondary">login</span>
                                </div>
                                <div>
                                    <p class="text-[20px] font-black text-on-surface leading-tight">{{ C.employee?.name ?? '—' }}</p>
                                    <p class="text-[13px] text-on-surface-variant font-mono">{{ C.reference }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-container-low px-3 py-1 text-[12px] font-bold text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[14px]">event</span>
                                    Hire date: {{ formatDate(C.hire_date) }}
                                </span>
                                <span v-if="C.target_date" class="inline-flex items-center gap-1.5 rounded-full bg-surface-container-low px-3 py-1 text-[12px] font-bold text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[14px]">flag</span>
                                    Target: {{ formatDate(C.target_date) }}
                                </span>
                            </div>
                        </div>

                        <!-- Quick stats -->
                        <div class="flex items-center gap-6 flex-shrink-0">
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Tasks</p>
                                <p class="text-[16px] font-black text-on-surface">{{ tasksDone }}/{{ tasksTotal }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/50"></div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Progress</p>
                                <p class="text-[16px] font-black text-on-surface">{{ pct(C.progress) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="mt-5 space-y-1.5">
                        <div class="flex items-center justify-between text-[12px]">
                            <span class="font-semibold text-on-surface-variant">Onboarding progress</span>
                            <span class="font-black text-on-surface">{{ tasksDone }} of {{ tasksTotal }} tasks signed off</span>
                        </div>
                        <div class="h-2.5 w-full rounded-full bg-surface-container overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all"
                                :style="`width:${pct(C.progress)};background:${Number(C.progress) >= 1 ? 'linear-gradient(90deg,#059669,#34d399)' : 'linear-gradient(90deg,#0d1452,#1a237e)'}`"
                            ></div>
                        </div>
                    </div>
                </div>

                <!-- ── HR Action bar ───────────────────────────────────────────────── -->
                <div v-if="C.can?.complete || C.can?.manage" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-5 shadow-card space-y-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70"><GlossaryText text="HR Actions" /></p>
                    <div class="flex flex-wrap gap-3">
                        <button
                            v-if="C.can?.complete"
                            @click="complete"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm"
                            style="background:linear-gradient(135deg,#059669,#34d399)"
                        >
                            <span class="material-symbols-outlined text-[17px]">task_alt</span>
                            Complete Onboarding
                        </button>
                        <button
                            v-if="C.can?.manage"
                            @click="showCancel = !showCancel"
                            class="flex items-center gap-2 rounded-xl border border-red-300/60 bg-red-50 dark:bg-red-950/20 px-4 py-2.5 text-[13px] font-bold text-red-600 hover:bg-red-100 transition-colors"
                        >
                            <span class="material-symbols-outlined text-[17px]">cancel</span>
                            Cancel Case
                        </button>
                    </div>

                    <!-- Cancel inline form -->
                    <div v-if="showCancel" class="rounded-xl border border-red-300/50 bg-red-50/30 dark:bg-red-950/20 p-4 space-y-3">
                        <p class="text-[12px] font-bold text-red-700">Cancellation reason <span class="text-red-500">*</span></p>
                        <textarea aria-label="Cancellation reason"
                            v-model="cancelForm.reason"
                            rows="2"
                            placeholder="Provide a reason for cancellation…"
                            class="w-full rounded-xl border border-red-300/60 bg-white dark:bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-red-400 focus:ring-2 focus:ring-red-400/20 transition-all resize-none"
                        ></textarea>
                        <div class="flex items-center gap-3">
                            <button
                                @click="submitCancel"
                                :disabled="!cancelForm.reason || cancelForm.processing"
                                class="flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-red-700 disabled:opacity-50 transition-colors"
                            >
                                <span v-if="cancelForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                Confirm Cancellation
                            </button>
                            <button @click="showCancel = false" class="text-[13px] font-semibold text-on-surface-variant hover:text-on-surface transition-colors">
                                Dismiss
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Tasks ─────────────────────────────────────────────────────────── -->
                <div class="space-y-4">
                    <div v-if="tasksTotal === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">checklist</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No onboarding tasks found for this case.</p>
                    </div>

                    <div
                        v-for="(items, area) in taskGroups"
                        :key="area"
                        class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden"
                    >
                        <!-- Area header -->
                        <div class="px-5 py-3 bg-surface-container-low border-b border-outline-variant/40 flex items-center gap-3">
                            <div class="h-8 w-8 rounded-lg bg-secondary/10 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-[16px] text-secondary">{{ areaIcon(area) }}</span>
                            </div>
                            <p class="text-[12px] font-black uppercase tracking-[0.12em] text-on-surface-variant/80">
                                {{ area }}
                            </p>
                            <div class="ml-auto flex items-center gap-1.5 text-[11px] font-semibold text-on-surface-variant/60">
                                <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                {{ items.filter(i => i.status === 'completed' || i.status === 'skipped').length }}/{{ items.length }}
                            </div>
                        </div>

                        <!-- Items -->
                        <div class="divide-y divide-outline-variant/30">
                            <div
                                v-for="item in items"
                                :key="item.id"
                                class="px-5 py-4"
                            >
                                <div class="flex items-start gap-3">
                                    <!-- Status icon -->
                                    <div class="mt-0.5 flex-shrink-0">
                                        <span
                                            class="material-symbols-outlined text-[22px]"
                                            :class="{
                                                'text-emerald-600': item.status === 'completed',
                                                'text-amber-500':   item.status === 'skipped',
                                                'text-on-surface-variant/30': item.status === 'pending',
                                            }"
                                        >
                                            {{ item.status === 'completed' ? 'check_circle' : item.status === 'skipped' ? 'remove_circle' : 'radio_button_unchecked' }}
                                        </span>
                                    </div>

                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2 mb-1">
                                            <span class="text-[13px] font-semibold text-on-surface">{{ item.label }}</span>
                                            <span v-if="!item.is_required" class="text-[10px] font-bold text-on-surface-variant/50 bg-surface-container-low rounded-full px-2 py-0.5">optional</span>
                                            <StatusBadge :status="item.status" :label="item.status_label ?? item.status" />
                                        </div>
                                        <p v-if="item.notes" class="text-[12px] text-on-surface-variant/70 italic mt-1">{{ item.notes }}</p>
                                        <p v-if="item.completed_at" class="text-[11px] text-on-surface-variant/50 mt-1">
                                            {{ item.status === 'completed' ? 'Completed' : 'Skipped' }}
                                            <span v-if="item.completed_by"> by <span class="font-semibold">{{ item.completed_by }}</span></span>
                                            on {{ formatDate(item.completed_at) }}
                                        </p>
                                    </div>

                                    <!-- Actions -->
                                    <div v-if="item.status === 'pending' && C.can?.complete" class="flex-shrink-0 flex gap-2">
                                        <button
                                            @click="completeTask(item)"
                                            class="flex items-center gap-1 rounded-xl bg-emerald-500/10 px-3 py-1.5 text-[12px] font-bold text-emerald-700 hover:bg-emerald-500/20 transition-colors"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">check</span>
                                            Complete
                                        </button>
                                        <button
                                            @click="showSkipId = (showSkipId === item.id ? null : item.id)"
                                            class="flex items-center gap-1 rounded-xl bg-amber-500/10 px-3 py-1.5 text-[12px] font-bold text-amber-700 hover:bg-amber-500/20 transition-colors"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">remove_circle</span>
                                            Skip
                                        </button>
                                    </div>
                                </div>

                                <!-- Inline skip form -->
                                <div v-if="showSkipId === item.id" class="mt-3 ml-8 rounded-xl bg-amber-50/40 dark:bg-amber-950/20 border border-amber-300/50 p-4">
                                    <form @submit.prevent="submitSkip" class="flex flex-wrap items-end gap-3">
                                        <div class="flex-1 min-w-[200px]">
                                            <label class="text-[11px] font-semibold text-amber-700 mb-1 block">
                                                Reason for skipping <span class="text-red-500">*</span>
                                            </label>
                                            <input aria-label="Reason for skipping"
                                                v-model="skipForm.reason"
                                                class="w-full rounded-xl border border-amber-300/60 bg-white dark:bg-surface-container-low px-4 py-2 text-[13px] text-on-surface focus:outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20 transition-all"
                                                placeholder="Enter skip reason…"
                                                required
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            :disabled="skipForm.processing || !skipForm.reason"
                                            class="flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2 text-[13px] font-bold text-white hover:bg-amber-600 disabled:opacity-50 transition-colors"
                                        >
                                            <span v-if="skipForm.processing" class="material-symbols-outlined animate-spin text-[15px]">progress_activity</span>
                                            Confirm Skip
                                        </button>
                                        <button
                                            type="button"
                                            @click="showSkipId = null"
                                            class="text-[12px] font-semibold text-on-surface-variant hover:text-on-surface transition-colors"
                                        >
                                            Cancel
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
    </div>
</template>
