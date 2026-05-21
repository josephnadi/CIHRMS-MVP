<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabBar from '@/Components/TabBar.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    case:         Object, // OffboardingCaseResource (possibly wrapped in { data: … })
    clearance:    Object, // keyed by area value â†’ ClearanceItemResource collection
    settlement:   Object, // FinalSettlementResource or null
    activeModule: String,
});

// ── Unwrap resource wrappers ──────────────────────────────────────────────────
const C = computed(() => props.case?.data ?? props.case ?? {});
const S = computed(() => props.settlement?.data ?? props.settlement ?? null);

// Clearance is: { area_value: { data: [...items] } | [...items] }
const clearanceGroups = computed(() => {
    const raw = props.clearance ?? {};
    const result = {};
    for (const [area, val] of Object.entries(raw)) {
        result[area] = Array.isArray(val) ? val : (val?.data ?? []);
    }
    return result;
});

const allClearanceItems = computed(() =>
    Object.values(clearanceGroups.value).flat()
);

const clearanceSigned = computed(() =>
    allClearanceItems.value.filter(i => i.status === 'cleared' || i.status === 'waived').length
);

const clearanceTotal = computed(() => allClearanceItems.value.length);

// ── Helpers ───────────────────────────────────────────────────────────────────
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct  = (v) => Math.round((Number(v) || 0) * 100) + '%';

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const lwdCountdown = computed(() => {
    const d = C.value.last_working_day;
    if (!d) return { label: '—', urgent: false, past: false };
    const diff = Math.floor((new Date(d).getTime() - Date.now()) / 86400000);
    if (diff === 0)  return { label: 'Today',       urgent: true,  past: false };
    if (diff === 1)  return { label: 'Tomorrow',    urgent: true,  past: false };
    if (diff > 0)    return { label: `in ${diff}d`, urgent: diff <= 7, past: false };
    if (diff === -1) return { label: '1 day ago',   urgent: false, past: true };
    return { label: `${Math.abs(diff)} days ago`,   urgent: false, past: true };
});

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

// ── Tabs ──────────────────────────────────────────────────────────────────────
const activeTab = ref('clearance');
const tabs = [
    { label: 'Clearance',   value: 'clearance'  },
    { label: 'Settlement',  value: 'settlement' },
    { label: 'Audit',       value: 'audit'      },
];

// ── Clearance actions ─────────────────────────────────────────────────────────
const clearItem = (item) => {
    router.post(
        route('offboarding.clearance.update', { case: C.value.id, item: item.id }),
        { action: 'clear', notes: '' },
        { preserveScroll: true },
    );
};

const waiveForm  = useForm({ action: 'waive', notes: '' });
const showWaiveId = ref(null);
const submitWaive = () => waiveForm.post(
    route('offboarding.clearance.update', { case: C.value.id, item: showWaiveId.value }),
    {
        preserveScroll: true,
        onSuccess: () => { showWaiveId.value = null; waiveForm.reset('notes'); },
    },
);

// ── Settlement actions ────────────────────────────────────────────────────────
const settleForm = useForm({
    gratuity_months_per_year:  1.0,
    severance_months_per_year: 1.5,
    working_days_per_month:    22,
    ex_gratia:                 0,
    prorated_13th_month:       0,
    other_deductions:          0,
    pay_paye:                  true,
});

const calculate = () => settleForm.post(route('offboarding.settlement.calculate', C.value.id), { preserveScroll: true });
const approve   = () => router.post(route('offboarding.settlement.approve', C.value.id), {}, { preserveScroll: true });
const complete  = () => router.post(route('offboarding.complete', C.value.id), {}, { preserveScroll: true });
const cancelForm = useForm({ reason: '' });
const showCancel  = ref(false);
const submitCancel = () => cancelForm.post(route('offboarding.cancel', C.value.id), {
    preserveScroll: true,
    onSuccess: () => { showCancel.value = false; cancelForm.reset(); },
});

// ── Area icon map ─────────────────────────────────────────────────────────────
const areaIcon = (area) => ({
    it:       'computer',
    hr:       'badge',
    finance:  'account_balance_wallet',
    manager:  'manage_accounts',
    library:  'menu_book',
    assets:   'devices',
    security: 'security',
    legal:    'gavel',
    payroll:  'payments',
})[String(area).toLowerCase()] ?? 'checklist';
</script>

<template>
    <Head :title="`Case ${C.reference ?? ''}`" />
    <div data-page-root="true">
            <!-- ── Header ─────────────────────────────────────────────────────────── -->
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('offboarding.index')"
                            class="flex h-9 w-9 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant hover:bg-surface-container transition-colors"
                        >
                            <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                        </Link>
                        <div>
                            <h2 class="text-[1.5rem] font-black tracking-tight text-on-surface leading-tight">Off-boarding Case</h2>
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
                                <div class="h-12 w-12 rounded-2xl bg-amber-500/10 flex items-center justify-center flex-shrink-0">
                                    <span class="material-symbols-outlined text-[24px] text-amber-600">{{ exitTypeIcon(C.exit_type) }}</span>
                                </div>
                                <div>
                                    <p class="text-[20px] font-black text-on-surface leading-tight">{{ C.employee?.name ?? '—' }}</p>
                                    <p class="text-[13px] text-on-surface-variant">
                                        {{ C.employee?.department ?? '' }}
                                        <span v-if="C.employee?.employee_no" class="mx-1.5 text-on-surface-variant/40">·</span>
                                        <span class="font-mono text-[12px]">{{ C.employee?.employee_no ?? '' }}</span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3 py-1 text-[12px] font-bold text-amber-700">
                                    <span class="material-symbols-outlined text-[14px]">{{ exitTypeIcon(C.exit_type) }}</span>
                                    {{ C.exit_type_label ?? C.exit_type }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-bold"
                                    :class="lwdCountdown.urgent ? 'bg-red-500/10 text-red-700' : lwdCountdown.past ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-low text-on-surface-variant'"
                                >
                                    <span class="material-symbols-outlined text-[14px]">event</span>
                                    LWD {{ C.last_working_day ? `(${formatDate(C.last_working_day)})` : '' }}: {{ lwdCountdown.label }}
                                </span>
                            </div>
                        </div>

                        <!-- Quick stats -->
                        <div class="flex items-center gap-6 flex-shrink-0">
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Clearance</p>
                                <p class="text-[16px] font-black text-on-surface">{{ clearanceSigned }}/{{ clearanceTotal }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/50"></div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Progress</p>
                                <p class="text-[16px] font-black text-on-surface">{{ pct(C.clearance_progress) }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/50"></div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Net Settlement</p>
                                <p class="font-mono text-[15px] font-black text-on-surface tabular-nums">{{ S ? cedi(S.net_payable) : '—' }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Clearance progress bar -->
                    <div class="mt-5 space-y-1.5">
                        <div class="flex items-center justify-between text-[12px]">
                            <span class="font-semibold text-on-surface-variant">Clearance progress</span>
                            <span class="font-black text-on-surface">{{ clearanceSigned }} of {{ clearanceTotal }} items signed off</span>
                        </div>
                        <div class="h-2.5 w-full rounded-full bg-surface-container overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all"
                                :style="`width:${pct(C.clearance_progress)};background:${Number(C.clearance_progress) >= 1 ? 'linear-gradient(90deg,#059669,#34d399)' : 'linear-gradient(90deg,#0d1452,#1a237e)'}`"
                            ></div>
                        </div>
                    </div>
                </div>

                <!-- ── 4 Stat cards ────────────────────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(26, 35, 126,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Notice Received</p>
                        <p class="text-[14px] font-black text-on-surface">{{ formatDate(C.notice_received_on) }}</p>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(217,119,6,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Last Working Day</p>
                        <p class="text-[14px] font-black text-on-surface" :class="lwdCountdown.urgent ? 'text-red-600' : ''">
                            {{ formatDate(C.last_working_day) }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(5,150,105,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Clearance Progress</p>
                        <p class="text-[18px] font-black text-on-surface">{{ pct(C.clearance_progress) }}</p>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/50 shadow-card p-4 bg-surface-container-lowest"
                         style="border-left:3px solid rgba(124,92,255,0.5)">
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-1">Net Settlement</p>
                        <p class="font-mono text-[15px] font-black text-on-surface tabular-nums">{{ S ? cedi(S.net_payable) : '—' }}</p>
                    </div>
                </div>

                <!-- ── HR Action bar ───────────────────────────────────────────────── -->
                <div v-if="C.can?.complete || C.can?.approve_settle" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-5 shadow-card space-y-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">HR Actions</p>
                    <div class="flex flex-wrap gap-3">
                        <button
                            v-if="S?.status === 'approved' && C.can?.complete"
                            @click="complete"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm"
                            style="background:linear-gradient(135deg,#059669,#34d399)"
                        >
                            <span class="material-symbols-outlined text-[17px]">task_alt</span>
                            Complete Case &amp; Terminate
                            <span class="text-[10px] opacity-75 font-normal">(2FA)</span>
                        </button>
                        <button
                            v-if="C.can?.complete"
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
                        <textarea
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

                <!-- ── Tabs ──────────────────────────────────────────────────────────── -->
                <TabBar :tabs="tabs" v-model="activeTab" />

                <!-- ── CLEARANCE TAB ───────────────────────────────────────────────── -->
                <div v-show="activeTab === 'clearance'" class="space-y-4">
                    <div v-if="Object.keys(clearanceGroups).length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">checklist</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No clearance items found for this case.</p>
                    </div>

                    <div
                        v-for="(items, area) in clearanceGroups"
                        :key="area"
                        class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden"
                    >
                        <!-- Area header -->
                        <div class="px-5 py-3 bg-surface-container-low border-b border-outline-variant/40 flex items-center gap-3">
                            <div class="h-8 w-8 rounded-lg bg-secondary/10 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-[16px] text-secondary">{{ areaIcon(area) }}</span>
                            </div>
                            <p class="text-[12px] font-black uppercase tracking-[0.12em] text-on-surface-variant/80">
                                {{ items[0]?.area_label ?? area }}
                            </p>
                            <div class="ml-auto flex items-center gap-1.5 text-[11px] font-semibold text-on-surface-variant/60">
                                <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                {{ items.filter(i => i.status === 'cleared' || i.status === 'waived').length }}/{{ items.length }}
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
                                                'text-emerald-600': item.status === 'cleared',
                                                'text-amber-500':   item.status === 'waived',
                                                'text-on-surface-variant/30': item.status === 'pending',
                                            }"
                                        >
                                            {{ item.status === 'cleared' ? 'check_circle' : item.status === 'waived' ? 'remove_circle' : 'radio_button_unchecked' }}
                                        </span>
                                    </div>

                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2 mb-1">
                                            <span class="text-[13px] font-semibold text-on-surface">{{ item.label }}</span>
                                            <span v-if="!item.is_required" class="text-[10px] font-bold text-on-surface-variant/50 bg-surface-container-low rounded-full px-2 py-0.5">optional</span>
                                            <StatusBadge :status="item.status" :label="item.status_label" />
                                        </div>
                                        <p v-if="item.department?.name || item.responsible_user?.name" class="text-[11px] text-on-surface-variant/60 mb-1">
                                            <span v-if="item.department?.name">Dept: {{ item.department.name }}</span>
                                            <span v-if="item.department?.name && item.responsible_user?.name"> · </span>
                                            <span v-if="item.responsible_user?.name">Assigned: {{ item.responsible_user.name }}</span>
                                        </p>
                                        <p v-if="item.notes" class="text-[12px] text-on-surface-variant/70 italic mt-1">{{ item.notes }}</p>
                                        <p v-if="item.cleared_at" class="text-[11px] text-on-surface-variant/50 mt-1">
                                            {{ item.status === 'cleared' ? 'Cleared' : 'Waived' }} by
                                            <span class="font-semibold">{{ item.cleared_by?.name ?? '—' }}</span>
                                            on {{ formatDate(item.cleared_at) }}
                                        </p>
                                    </div>

                                    <!-- Actions -->
                                    <div v-if="item.status === 'pending' && C.can?.clear" class="flex-shrink-0 flex gap-2">
                                        <button
                                            @click="clearItem(item)"
                                            class="flex items-center gap-1 rounded-xl bg-emerald-500/10 px-3 py-1.5 text-[12px] font-bold text-emerald-700 hover:bg-emerald-500/20 transition-colors"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">check</span>
                                            Clear
                                        </button>
                                        <button
                                            @click="showWaiveId = (showWaiveId === item.id ? null : item.id)"
                                            class="flex items-center gap-1 rounded-xl bg-amber-500/10 px-3 py-1.5 text-[12px] font-bold text-amber-700 hover:bg-amber-500/20 transition-colors"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">remove_circle</span>
                                            Waive
                                        </button>
                                    </div>
                                </div>

                                <!-- Inline waive form -->
                                <div v-if="showWaiveId === item.id" class="mt-3 ml-8 rounded-xl bg-amber-50/40 dark:bg-amber-950/20 border border-amber-300/50 p-4">
                                    <form @submit.prevent="submitWaive" class="flex flex-wrap items-end gap-3">
                                        <div class="flex-1 min-w-[200px]">
                                            <label class="text-[11px] font-semibold text-amber-700 mb-1 block">
                                                Reason for waiving <span class="text-red-500">*</span>
                                            </label>
                                            <input
                                                v-model="waiveForm.notes"
                                                class="w-full rounded-xl border border-amber-300/60 bg-white dark:bg-surface-container-low px-4 py-2 text-[13px] text-on-surface focus:outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20 transition-all"
                                                placeholder="Enter waive reason…"
                                                required
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            :disabled="waiveForm.processing || !waiveForm.notes"
                                            class="flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2 text-[13px] font-bold text-white hover:bg-amber-600 disabled:opacity-50 transition-colors"
                                        >
                                            <span v-if="waiveForm.processing" class="material-symbols-outlined animate-spin text-[15px]">progress_activity</span>
                                            Confirm Waive
                                        </button>
                                        <button
                                            type="button"
                                            @click="showWaiveId = null"
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

                <!-- ── SETTLEMENT TAB ──────────────────────────────────────────────── -->
                <div v-show="activeTab === 'settlement'" class="space-y-5">

                    <!-- Calculation overrides panel -->
                    <div
                        v-if="(!S || S.status === 'calculated') && C.can?.settle"
                        class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6"
                    >
                        <div class="flex items-center gap-2 mb-4">
                            <div class="h-8 w-8 rounded-lg bg-secondary/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-[16px] text-secondary">calculate</span>
                            </div>
                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">Calculation Parameters</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-5">
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Gratuity (months/yr)</label>
                                <input
                                    v-model.number="settleForm.gratuity_months_per_year"
                                    type="number"
                                    step="0.1"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Severance (months/yr)</label>
                                <input
                                    v-model.number="settleForm.severance_months_per_year"
                                    type="number"
                                    step="0.1"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Working days / month</label>
                                <input
                                    v-model.number="settleForm.working_days_per_month"
                                    type="number"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Ex-gratia (GHS)</label>
                                <input
                                    v-model.number="settleForm.ex_gratia"
                                    type="number"
                                    step="0.01"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Prorated 13th month</label>
                                <input
                                    v-model.number="settleForm.prorated_13th_month"
                                    type="number"
                                    step="0.01"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Other deductions</label>
                                <input
                                    v-model.number="settleForm.other_deductions"
                                    type="number"
                                    step="0.01"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>
                            <div class="flex items-end pb-1">
                                <label class="inline-flex items-center gap-2 text-[13px] font-semibold text-on-surface-variant cursor-pointer">
                                    <input
                                        v-model="settleForm.pay_paye"
                                        type="checkbox"
                                        class="h-4 w-4 rounded accent-secondary"
                                    />
                                    Apply PAYE
                                </label>
                            </div>
                        </div>

                        <button
                            @click="calculate"
                            :disabled="settleForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="settleForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            <span class="material-symbols-outlined text-[17px]" v-else>calculate</span>
                            {{ S ? 'Recalculate Settlement' : 'Calculate Settlement' }}
                        </button>
                    </div>

                    <!-- Settlement snapshot -->
                    <div v-if="S" class="space-y-4">
                        <!-- Status pill + metadata -->
                        <div class="flex flex-wrap items-center gap-4 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-4">
                            <div class="flex-1">
                                <p class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant/60 mb-1">Settlement Status</p>
                                <StatusBadge :status="S.status" :label="S.status_label" />
                            </div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Years of Service</p>
                                <p class="text-[15px] font-black text-on-surface">{{ S.years_of_service }}yr</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Basic Salary</p>
                                <p class="font-mono text-[15px] font-black text-on-surface tabular-nums">{{ cedi(S.basic_salary) }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Leave Days</p>
                                <p class="text-[15px] font-black text-on-surface">{{ S.accrued_leave_days }} days</p>
                            </div>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <!-- Earnings card -->
                            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                                <div class="flex items-center gap-2 mb-4">
                                    <div class="h-7 w-7 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-[15px] text-emerald-600">add_circle</span>
                                    </div>
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-emerald-700">Earnings</p>
                                </div>
                                <dl class="space-y-2.5">
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Gratuity</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.earnings.gratuity) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Severance (Act 651)</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.earnings.severance) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Leave encashment ({{ S.accrued_leave_days }} days)</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.earnings.leave_encashment) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Pro-rated 13th month</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.earnings.prorated_13th_month) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Ex-gratia</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.earnings.ex_gratia) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[14px] font-black border-t border-outline-variant/50 pt-3 mt-3">
                                        <dt class="text-on-surface">Gross Settlement</dt>
                                        <dd class="font-mono text-emerald-700 tabular-nums">{{ cedi(S.earnings.gross_settlement) }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <!-- Deductions card -->
                            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5">
                                <div class="flex items-center gap-2 mb-4">
                                    <div class="h-7 w-7 rounded-lg bg-red-500/10 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-[15px] text-red-600">remove_circle</span>
                                    </div>
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-red-700">Deductions</p>
                                </div>
                                <dl class="space-y-2.5">
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Outstanding loans</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.deductions.outstanding_loans) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Garnishments</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.deductions.garnishments) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">Other deductions</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.deductions.other_deductions) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px]">
                                        <dt class="text-on-surface-variant">PAYE on settlement</dt>
                                        <dd class="font-mono font-semibold text-on-surface tabular-nums">{{ cedi(S.deductions.paye_on_settlement) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-[13px] font-bold border-t border-outline-variant/50 pt-3 mt-3">
                                        <dt class="text-on-surface">Total Deductions</dt>
                                        <dd class="font-mono text-red-600 tabular-nums">{{ cedi(S.deductions.total_deductions) }}</dd>
                                    </div>
                                    <!-- Net payable highlight -->
                                    <div class="flex justify-between text-[16px] font-black bg-secondary/5 rounded-xl px-3 py-3 mt-2 border border-secondary/15">
                                        <dt class="text-on-surface">Net Payable</dt>
                                        <dd class="font-mono tabular-nums" style="color:#1a237e">{{ cedi(S.net_payable) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Workflow actions -->
                        <div class="flex flex-wrap gap-3">
                            <button
                                v-if="S.status === 'calculated' && C.can?.approve_settle"
                                @click="approve"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm"
                                style="background:linear-gradient(135deg,#059669,#34d399)"
                            >
                                <span class="material-symbols-outlined text-[17px]">verified</span>
                                Approve Settlement
                                <span class="text-[10px] opacity-75 font-normal">(2FA)</span>
                            </button>
                            <button
                                v-if="S.status === 'approved' && C.can?.complete"
                                @click="complete"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[17px]">task_alt</span>
                                Complete Case &amp; Terminate Employee
                                <span class="text-[10px] opacity-75 font-normal">(2FA)</span>
                            </button>
                            <p v-if="S.status === 'approved' && !C.can?.complete" class="text-[13px] text-on-surface-variant/70 italic self-center">
                                Settlement approved. Awaiting HR to complete the case.
                            </p>
                        </div>
                    </div>

                    <div v-if="!S" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">account_balance_wallet</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">
                            No settlement calculated yet.
                            <span v-if="C.can?.settle"> Use the parameters above to run the first calculation.</span>
                        </p>
                    </div>
                </div>

                <!-- ── AUDIT TAB ────────────────────────────────────────────────────── -->
                <div v-show="activeTab === 'audit'" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 space-y-5">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Case Timeline</p>

                    <!-- Vertical timeline -->
                    <div class="relative space-y-0 pl-8">
                        <!-- Vertical line -->
                        <div class="absolute left-3.5 top-2 bottom-2 w-px bg-outline-variant/40"></div>

                        <!-- Initiated -->
                        <div class="relative pb-6">
                            <div class="absolute -left-8 mt-0.5 h-6 w-6 rounded-full bg-secondary/10 border-2 border-secondary flex items-center justify-center">
                                <span class="material-symbols-outlined text-[12px] text-secondary">add_circle</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-on-surface">Case Initiated</p>
                                <p class="text-[12px] text-on-surface-variant">
                                    By {{ C.initiator?.name ?? 'System' }}
                                    <span v-if="C.notice_received_on"> · Notice: {{ formatDate(C.notice_received_on) }}</span>
                                </p>
                                <p class="text-[11px] text-on-surface-variant/55 mt-0.5">Exit type: {{ C.exit_type_label ?? C.exit_type }}</p>
                            </div>
                        </div>

                        <!-- Settlement calculated -->
                        <div v-if="S?.calculated_at" class="relative pb-6">
                            <div class="absolute -left-8 mt-0.5 h-6 w-6 rounded-full bg-blue-500/10 border-2 border-blue-400 flex items-center justify-center">
                                <span class="material-symbols-outlined text-[12px] text-blue-600">calculate</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-on-surface">Settlement Calculated</p>
                                <p class="text-[12px] text-on-surface-variant">Net payable: {{ cedi(S.net_payable) }}</p>
                                <p class="text-[11px] text-on-surface-variant/55 mt-0.5">{{ formatDate(S.calculated_at) }}</p>
                            </div>
                        </div>

                        <!-- Settlement approved -->
                        <div v-if="S?.approved_at" class="relative pb-6">
                            <div class="absolute -left-8 mt-0.5 h-6 w-6 rounded-full bg-emerald-500/10 border-2 border-emerald-500 flex items-center justify-center">
                                <span class="material-symbols-outlined text-[12px] text-emerald-600">verified</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-on-surface">Settlement Approved</p>
                                <p class="text-[12px] text-on-surface-variant">{{ formatDate(S.approved_at) }}</p>
                            </div>
                        </div>

                        <!-- Case completed -->
                        <div v-if="C.completed_at" class="relative">
                            <div class="absolute -left-8 mt-0.5 h-6 w-6 rounded-full bg-emerald-600/15 border-2 border-emerald-600 flex items-center justify-center">
                                <span class="material-symbols-outlined text-[12px] text-emerald-700">task_alt</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-on-surface">Case Completed</p>
                                <p class="text-[12px] text-on-surface-variant">Employee terminated · {{ formatDate(C.completed_at) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Additional metadata -->
                    <div class="pt-5 border-t border-outline-variant/50 grid sm:grid-cols-2 gap-4">
                        <div class="space-y-2.5">
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Reference</span>
                                <span class="font-mono font-bold text-on-surface">{{ C.reference }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Initiated by</span>
                                <span class="font-semibold text-on-surface">{{ C.initiator?.name ?? '—' }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Exit type</span>
                                <span class="font-semibold text-on-surface">{{ C.exit_type_label ?? C.exit_type }}</span>
                            </div>
                        </div>
                        <div class="space-y-2.5">
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Rehire eligible</span>
                                <span class="font-semibold text-on-surface">{{ C.rehire_eligible ? 'Yes' : 'No' }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Last working day</span>
                                <span class="font-semibold text-on-surface">{{ formatDate(C.last_working_day) }}</span>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-on-surface-variant">Effective termination</span>
                                <span class="font-semibold text-on-surface">{{ formatDate(C.effective_termination_date) }}</span>
                            </div>
                        </div>

                        <div v-if="C.reason" class="sm:col-span-2">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Reason / Context</p>
                            <p class="text-[13px] text-on-surface-variant italic">{{ C.reason }}</p>
                        </div>
                        <div v-if="C.exit_interview_summary" class="sm:col-span-2">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Exit Interview Summary</p>
                            <p class="text-[13px] text-on-surface-variant">{{ C.exit_interview_summary }}</p>
                        </div>
                    </div>
                </div>

            </div>
    </div>
</template>
