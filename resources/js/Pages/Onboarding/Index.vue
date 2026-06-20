<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    cases:        Object, // paginated { data: [], links: [], meta: {} }
    filters:      Object, // { status }
    activeModule: String,
});

// ── Helpers ───────────────────────────────────────────────────────────────────
const pct  = (v) => Math.round((Number(v) || 0) * 100) + '%';

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

// Avatar gradient pool — disciplined cool family
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
    const parts = name.trim().split(' ');
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
};

// ── Filters ───────────────────────────────────────────────────────────────────
const localFilters = reactive({
    status: props.filters?.status ?? '',
    q:      props.filters?.q      ?? '',
});

const applyFilters = () => router.get(
    route('onboarding.index'),
    {
        status: localFilters.status || undefined,
        q:      localFilters.q      || undefined,
    },
    { preserveState: true, replace: true },
);

let searchTimer = null;
watch(() => localFilters.q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const hasFilters = computed(() => localFilters.status || localFilters.q);

const clearFilters = () => {
    localFilters.status = '';
    localFilters.q = '';
    applyFilters();
};

const caseList = computed(() => props.cases?.data ?? []);

// ── Initiate panel ────────────────────────────────────────────────────────────
const showPanel = ref(false);
const form = useForm({
    employee_id:            '',
    target_completion_date: '',
});

// Auto-open the panel when arriving via Quick Action (?new=1).
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showPanel.value = true;
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash,
        );
    }
});

const submitCase = () => form.post(route('onboarding.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); },
});
</script>

<template>
    <Head title="Onboarding" />
    <div data-page-root="true">
            <!-- ── Header ────────────────────────────────────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">login</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">ONBOARDING DOSSIER</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Onboarding</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Every new hire onboarded against a templated checklist · IT, HR, policy, learning, mentorship · sign-off and completion.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">edit_note</span>
                            Start Onboarding
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- ── Filter strip ─────────────────────────────────────────────────── -->
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[200px] max-w-xs">
                        <SearchInput
                            v-model="localFilters.q"
                            placeholder="Search reference or employee…"
                        />
                    </div>

                    <select aria-label="Status"
                        v-model="localFilters.status"
                        @change="applyFilters"
                        class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
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

                <!-- ── Case card grid ────────────────────────────────────────────────── -->
                <div v-if="caseList.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                    <EmptyState
                        title="No onboarding cases found"
                        description="Initiated cases will appear here. Adjust filters or start a new onboarding."
                        icon="login"
                    >
                        <template #action>
                            <button
                                @click="showPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Start Onboarding
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div
                        v-for="(c, i) in caseList"
                        :key="c.id"
                        class="card-lift rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5 flex flex-col gap-4 cursor-pointer animate-slide-up-fade"
                        :style="`animation-delay:${i * 0.06}s`"
                        @click="router.get(route('onboarding.show', c.id))"
                    >
                        <!-- Employee header -->
                        <div class="flex items-center gap-3">
                            <div
                                class="h-10 w-10 flex-shrink-0 rounded-full flex items-center justify-center text-[13px] font-black text-white"
                                :style="`background:${avatarGradient(c.employee?.id ?? c.id)}`"
                            >
                                {{ initials(c.employee?.name) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[14px] font-bold text-on-surface leading-tight truncate">{{ c.employee?.name ?? '—' }}</p>
                                <p class="text-[11px] text-on-surface-variant/60 leading-tight truncate font-mono">{{ c.reference }}</p>
                            </div>
                            <StatusBadge :status="c.status" :label="c.status_label" />
                        </div>

                        <!-- Onboarding progress bar -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="font-semibold text-on-surface-variant">Onboarding progress</span>
                                <span class="font-black text-on-surface">{{ pct(c.progress) }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-surface-container overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :style="`width:${pct(c.progress)};background:${Number(c.progress) >= 1 ? 'linear-gradient(90deg,#059669,#34d399)' : 'linear-gradient(90deg,#1a237e,#3949ab)'}`"
                                ></div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-1 border-t border-outline-variant/40">
                            <div class="flex items-center gap-1.5 text-[11px] text-on-surface-variant/60">
                                <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                Hire date: {{ formatDate(c.hire_date) }}
                            </div>
                            <Link
                                :href="route('onboarding.show', c.id)"
                                class="flex items-center gap-1 text-[12px] font-bold text-secondary hover:underline"
                                @click.stop
                            >
                                Open Case
                                <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="cases?.links?.length > 3" class="flex items-center justify-between">
                    <p class="text-[12px] text-on-surface-variant">
                        Showing
                        <span class="font-semibold text-on-surface">{{ cases.meta?.from }}</span>
                        —
                        <span class="font-semibold text-on-surface">{{ cases.meta?.to }}</span>
                        of
                        <span class="font-semibold text-on-surface">{{ cases.meta?.total }}</span>
                    </p>
                    <Pagination :links="cases.links" />
                </div>
            </div>

            <!-- ── Start Onboarding SlidePanel ──────────────────────────────── -->
            <SlidePanel
                :open="showPanel"
                title="Start Onboarding Case"
                size="lg"
                @close="showPanel = false"
            >
                <form @submit.prevent="submitCase" class="space-y-5 p-6">

                    <!-- Employee ID -->
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                            Employee ID <span class="text-red-500">*</span>
                        </label>
                        <input aria-label="Employee ID"
                            v-model="form.employee_id"
                            type="number"
                            placeholder="Employee record ID"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.employee_id }"
                        />
                        <p v-if="form.errors.employee_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.employee_id }}</p>
                    </div>

                    <!-- Target completion date -->
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Target Completion Date</label>
                        <input aria-label="Target Completion Date"
                            v-model="form.target_completion_date"
                            type="date"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.target_completion_date }"
                        />
                        <p v-if="form.errors.target_completion_date" class="mt-1 text-[11px] text-red-500">{{ form.errors.target_completion_date }}</p>
                    </div>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="showPanel = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitCase"
                            :disabled="form.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            Start Onboarding
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>
