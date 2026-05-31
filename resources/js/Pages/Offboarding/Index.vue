<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    cases:        Object, // paginated { data: [], links: [], meta: {} }
    stats:        Object, // { in_progress, awaiting_settle, completed_ytd, settlement_total }
    filters:      Object, // { status, exit_type, q }
    activeModule: String,
});

// ── Helpers ───────────────────────────────────────────────────────────────────
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct  = (v) => Math.round((Number(v) || 0) * 100) + '%';

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const relativeLWD = (dateStr) => {
    if (!dateStr) return '—';
    const diff = Math.floor((new Date(dateStr).getTime() - Date.now()) / 86400000);
    if (diff === 0)   return 'Today';
    if (diff === 1)   return 'Tomorrow';
    if (diff > 0)     return `in ${diff} days`;
    if (diff === -1)  return '1 day ago';
    return `${Math.abs(diff)} days ago`;
};

const lwdUrgency = (dateStr) => {
    if (!dateStr) return '';
    const diff = Math.floor((new Date(dateStr).getTime() - Date.now()) / 86400000);
    if (diff < 0)  return 'text-on-surface-variant/60';
    if (diff <= 7) return 'text-red-600 font-bold';
    if (diff <= 14) return 'text-amber-600 font-semibold';
    return 'text-on-surface-variant';
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

const exitTypeIcon = (type) => ({
    resignation:       'exit_to_app',
    retirement:        'elderly',
    end_of_contract:   'event_busy',
    dismissal:         'gavel',
    redundancy:        'group_remove',
    mutual_separation: 'handshake',
    death:             'sentiment_sad',
    abscondment:       'person_off',
})[type] ?? 'logout';

const exitTypeColor = (type) => ({
    resignation:       'rgba(26, 35, 126,0.12)',
    retirement:        'rgba(5,150,105,0.12)',
    end_of_contract:   'rgba(217,119,6,0.12)',
    dismissal:         'rgba(220,38,38,0.12)',
    redundancy:        'rgba(124,92,255,0.12)',
    mutual_separation: 'rgba(15,118,110,0.12)',
    death:             'rgba(100,116,139,0.12)',
    abscondment:       'rgba(220,38,38,0.12)',
})[type] ?? 'rgba(100,116,139,0.12)';

// ── Filters ───────────────────────────────────────────────────────────────────
const localFilters = reactive({
    status:    props.filters?.status    ?? '',
    exit_type: props.filters?.exit_type ?? '',
    q:         props.filters?.q         ?? '',
});

const applyFilters = () => router.get(
    route('offboarding.index'),
    {
        status:    localFilters.status    || undefined,
        exit_type: localFilters.exit_type || undefined,
        q:         localFilters.q         || undefined,
    },
    { preserveState: true, replace: true },
);

let searchTimer = null;
watch(() => localFilters.q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const hasFilters = computed(() => localFilters.status || localFilters.exit_type || localFilters.q);

// ── Editorial-Sovereign masthead ──────────────────────────────────
// Volume = year offset from CIHRM founding (kept stable). Issue = day-of-year.
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
const clearFilters = () => {
    localFilters.status = '';
    localFilters.exit_type = '';
    localFilters.q = '';
    applyFilters();
};

// ── Initiate panel ────────────────────────────────────────────────────────────
const showPanel = ref(false);
const form = useForm({
    employee_id:        '',
    exit_type:          'resignation',
    notice_received_on: new Date().toISOString().slice(0, 10),
    last_working_day:   '',
    reason:             '',
});

// Auto-open the off-boarding panel when arriving via Quick Action (?new=1).
// Strip the flag immediately so refresh + post-submit back() don't re-trigger
// the panel and leave the backdrop stuck over the page.
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

const submitCase = () => form.post(route('offboarding.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); },
});
</script>

<template>
    <Head title="Off-boarding &amp; Settlement" />
    <div data-page-root="true">
            <!-- ── Header ────────────────────────────────────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">logout</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">OFF-BOARDING DOSSIER</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Off-boarding &amp; Settlement</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Every separation logged against Act 651 §17 · clearance across IT, Finance, HR, line manager · dual-control settlement.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">edit_note</span>
                            Initiate Case
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
                        <option value="awaiting_settlement">Awaiting Settlement</option>
                        <option value="settled">Settled</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <select aria-label="Exit type"
                        v-model="localFilters.exit_type"
                        @change="applyFilters"
                        class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option value="">All Exit Types</option>
                        <option value="resignation">Resignation</option>
                        <option value="retirement">Retirement</option>
                        <option value="end_of_contract">End of Contract</option>
                        <option value="dismissal">Dismissal</option>
                        <option value="redundancy">Redundancy</option>
                        <option value="mutual_separation">Mutual Separation</option>
                        <option value="death">Death</option>
                        <option value="abscondment">Abscondment</option>
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
                <div v-if="(cases?.data?.length ?? 0) === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                    <EmptyState
                        title="No off-boarding cases found"
                        description="Initiated cases will appear here. Adjust filters or open a new case."
                        icon="logout"
                    >
                        <template #action>
                            <button
                                @click="showPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Open Case
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div
                        v-for="(c, i) in cases.data"
                        :key="c.id"
                        class="card-lift rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5 flex flex-col gap-4 cursor-pointer animate-slide-up-fade"
                        :style="`animation-delay:${i * 0.06}s`"
                        @click="router.get(route('offboarding.show', c.id))"
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
                                <p class="text-[11px] text-on-surface-variant/60 leading-tight truncate">
                                    {{ c.employee?.department ?? '' }}
                                    <span v-if="c.employee?.employee_no"> · {{ c.employee.employee_no }}</span>
                                </p>
                            </div>
                            <StatusBadge :status="c.status" :label="c.status_label" />
                        </div>

                        <!-- Exit type badge + LWD -->
                        <div class="flex items-center gap-3">
                            <div
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-bold"
                                :style="`background:${exitTypeColor(c.exit_type)}`"
                            >
                                <span class="material-symbols-outlined text-[14px]">{{ exitTypeIcon(c.exit_type) }}</span>
                                {{ c.exit_type_label ?? c.exit_type }}
                            </div>
                            <div class="ml-auto flex items-center gap-1.5 text-[12px]" :class="lwdUrgency(c.last_working_day)">
                                <span class="material-symbols-outlined text-[15px]">event</span>
                                LWD: {{ relativeLWD(c.last_working_day) }}
                            </div>
                        </div>

                        <!-- Clearance progress bar -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="font-semibold text-on-surface-variant">Clearance progress</span>
                                <span class="font-black text-on-surface">{{ pct(c.clearance_progress) }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-surface-container overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :style="`width:${pct(c.clearance_progress)};background:${Number(c.clearance_progress) >= 1 ? 'linear-gradient(90deg,#059669,#34d399)' : 'linear-gradient(90deg,#1a237e,#3949ab)'}`"
                                ></div>
                            </div>
                        </div>

                        <!-- Settlement row -->
                        <div class="rounded-xl bg-surface-container-low px-4 py-3 flex items-center justify-between">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60 mb-0.5">Net Settlement</p>
                                <p v-if="c.settlement" class="font-mono text-[14px] font-black text-on-surface tabular-nums">
                                    {{ cedi(c.settlement.net_payable) }}
                                </p>
                                <p v-else class="text-[13px] text-on-surface-variant/40 italic">Not yet calculated</p>
                            </div>
                            <div v-if="c.settlement">
                                <StatusBadge :status="c.settlement.status" :label="c.settlement.status_label" />
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-1 border-t border-outline-variant/40">
                            <div class="flex items-center gap-1.5 text-[11px] text-on-surface-variant/60">
                                <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                Notice: {{ formatDate(c.notice_received_on) }}
                            </div>
                            <Link
                                :href="route('offboarding.show', c.id)"
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

            <!-- ── Initiate Off-boarding SlidePanel ──────────────────────────────── -->
            <SlidePanel
                :open="showPanel"
                title="Initiate Off-boarding Case"
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

                    <!-- Exit type -->
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                            Exit Type <span class="text-red-500">*</span>
                        </label>
                        <select aria-label="Exit Type"
                            v-model="form.exit_type"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.exit_type }"
                        >
                            <option value="resignation">Resignation</option>
                            <option value="retirement">Retirement</option>
                            <option value="end_of_contract">End of Contract</option>
                            <option value="dismissal">Dismissal (with cause)</option>
                            <option value="redundancy">Redundancy (Act 651 Â§31)</option>
                            <option value="mutual_separation">Mutual Separation</option>
                            <option value="death">Death</option>
                            <option value="abscondment">Abscondment</option>
                        </select>
                        <p v-if="form.errors.exit_type" class="mt-1 text-[11px] text-red-500">{{ form.errors.exit_type }}</p>
                    </div>

                    <!-- Dates -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                Notice Received On <span class="text-red-500">*</span>
                            </label>
                            <input aria-label="Notice Received On"
                                v-model="form.notice_received_on"
                                type="date"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.notice_received_on }"
                            />
                            <p v-if="form.errors.notice_received_on" class="mt-1 text-[11px] text-red-500">{{ form.errors.notice_received_on }}</p>
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                Last Working Day <span class="text-red-500">*</span>
                            </label>
                            <input aria-label="Last Working Day"
                                v-model="form.last_working_day"
                                type="date"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.last_working_day }"
                            />
                            <p v-if="form.errors.last_working_day" class="mt-1 text-[11px] text-red-500">{{ form.errors.last_working_day }}</p>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Reason / Context</label>
                        <textarea aria-label="Reason / Context"
                            v-model="form.reason"
                            rows="3"
                            placeholder="Briefly describe the circumstances…"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                        ></textarea>
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
                            Initiate Case
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>
