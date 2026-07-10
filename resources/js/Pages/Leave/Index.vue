<script setup>
import SlidePanel          from '@/Components/SlidePanel.vue';
import ConfirmDialog       from '@/Components/ConfirmDialog.vue';
import StatusBadge         from '@/Components/StatusBadge.vue';
import Pagination          from '@/Components/Pagination.vue';
import EmptyState          from '@/Components/EmptyState.vue';
import TabBar              from '@/Components/TabBar.vue';
import FileUpload          from '@/Components/FileUpload.vue';
import ProgressRing        from '@/Components/ProgressRing.vue';
import InputError          from '@/Components/InputError.vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref, watch, onMounted } from 'vue';


defineOptions({ layout: AuthenticatedLayout });
// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    leaves:       Object,  // paginated leave-request collection
    balances:     Array,   // [{ type, label, total_days, used_days, remaining }]
    pendingCount: Number,  // HR badge — count of pending requests (0 for self-service)
    employees:    Array,   // HR filter dropdown — [{ id, name, employee_no }]
    filters:      Object,
    activeModule: String,
});

// Template alias — controllers ship the collection as `leaves` but the
// template/UI semantics read more naturally as `requests`. Computed alias
// keeps script and template aligned without forcing a controller change.
const requests = computed(() => props.leaves);

// ── Auth / role ───────────────────────────────────────────────────────────────
const page = usePage();
const user = computed(() => page.props.auth?.user);
const isHR = computed(() => ['hr_admin', 'super_admin', 'ceo'].includes(user.value?.role));

// ── Ghana leave types ─────────────────────────────────────────────────────────
// Leave type palette — disciplined Sovereign Precision colors.
// annual=cobalt (action), maternity=magenta (people/family), paternity=cyan,
// study=cyan (learning), sick=amber (medical alarm — semantic, retained),
// emergency=red (alarm — semantic, retained), unpaid=slate (neutral).
const LEAVE_TYPES = [
    { value: 'annual',    label: 'Annual Leave',    days: 15,  icon: 'beach_access',     color: '#1a237e', chipColor: 'blue'    },
    { value: 'sick',      label: 'Sick Leave',      days: 14,  icon: 'medical_services', color: '#d97706', chipColor: 'amber'   },
    { value: 'maternity', label: 'Maternity Leave', days: 84,  icon: 'child_care',       color: '#d912e3', chipColor: 'magenta' },
    { value: 'paternity', label: 'Paternity Leave', days: 5,   icon: 'family_restroom',  color: '#0e8a93', chipColor: 'cyan'    },
    { value: 'emergency', label: 'Emergency Leave', days: 3,   icon: 'emergency',        color: '#dc2626', chipColor: 'red'     },
    { value: 'study',     label: 'Study Leave',     days: null, icon: 'school',          color: '#0e8a93', chipColor: 'cyan'    },
    { value: 'unpaid',    label: 'Unpaid Leave',    days: null, icon: 'money_off',       color: '#64748b', chipColor: 'gray'    },
];

const typeMap = Object.fromEntries(LEAVE_TYPES.map(t => [t.value, t]));

function leaveTypeColor(type) {
    return typeMap[type]?.color ?? '#64748b';
}

function leaveTypeLabel(type) {
    return typeMap[type]?.label ?? type;
}

// Balance card colors — aligned with LEAVE_TYPES palette
const balanceColors = {
    annual:    { color: '#1a237e', bg: 'bg-blue-50   dark:bg-blue-950/20',  ring: 'ring-blue-200   dark:ring-blue-800/30'   },
    sick:      { color: '#d97706', bg: 'bg-amber-50  dark:bg-amber-950/20', ring: 'ring-amber-200  dark:ring-amber-800/30'  },
    maternity: { color: '#d912e3', bg: '',                                  ring: '',                                       },
    paternity: { color: '#0e8a93', bg: '',                                  ring: '',                                       },
    emergency: { color: '#dc2626', bg: 'bg-red-50    dark:bg-red-950/20',   ring: 'ring-red-200    dark:ring-red-800/30'    },
    study:     { color: '#0e8a93', bg: '',                                  ring: '',                                       },
    unpaid:    { color: '#64748b', bg: 'bg-slate-50  dark:bg-slate-900/20', ring: 'ring-slate-200  dark:ring-slate-700/30'  },
};
const balanceTint = (type) => {
    const c = balanceColors[type]?.color ?? '#64748b';
    // inline rgba-tinted card background for cyan/magenta (where Tailwind 50/950 doesn't fit the brand palette)
    if (c === '#d912e3') return 'background:rgba(217,18,227,0.06)';
    if (c === '#0e8a93') return 'background:rgba(18,217,227,0.07)';
    return '';
};

// ── Employee view: apply leave panel ─────────────────────────────────────────
const showApplyPanel   = ref(false);

// Auto-open the leave request panel when navigated to via Quick Action (?new=1).
// Strip the flag immediately so refresh + post-submit back() don't re-trigger
// the panel and leave the backdrop stuck over the page.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showApplyPanel.value = true;
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash,
        );
    }
});
const showDetailPanel  = ref(false);
const selectedRequest  = ref(null);

const leaveForm = useForm({
    type:       'annual',
    start_date: '',
    end_date:   '',
    reason:     '',
    attachment: null,
});

// Computed requested days
const requestedDays = computed(() => {
    if (!leaveForm.start_date || !leaveForm.end_date) return 0;
    const s = new Date(leaveForm.start_date);
    const e = new Date(leaveForm.end_date);
    if (e < s) return 0;
    let count = 0;
    const cur = new Date(s);
    while (cur <= e) {
        const d = cur.getDay();
        if (d !== 0 && d !== 6) count++;
        cur.setDate(cur.getDate() + 1);
    }
    return count;
});

function submitLeave() {
    leaveForm.post(route('leave.store'), {
        onSuccess: () => {
            showApplyPanel.value = false;
            leaveForm.reset();
        },
    });
}

function openDetail(req) {
    selectedRequest.value = req;
    showDetailPanel.value = true;
}

// Decision history for the detail panel. Backend persists a single
// approve/reject decision (decision_comment + decided_at); surface it as a
// one-entry history so the Status History block is no longer always empty.
const detailHistory = computed(() => {
    const r = selectedRequest.value;
    if (!r) return [];
    if (Array.isArray(r.history) && r.history.length) return r.history;
    if (r.decided_at && (r.status === 'approved' || r.status === 'rejected')) {
        return [{
            id:         `decision-${r.id}`,
            status:     r.status,
            comment:    r.decision_comment,
            created_at: r.decided_at,
            actor_name: r.approver?.name ?? 'HR / Manager',
        }];
    }
    return [];
});

// ── Manager/HR view ───────────────────────────────────────────────────────────
const activeTab = ref('pending');
const hrTabs = computed(() => [
    { value: 'pending', label: 'Pending Approvals', icon: 'pending_actions', count: props.pendingCount ?? 0 },
    { value: 'all',     label: 'All Requests',      icon: 'list_alt'        },
    { value: 'calendar',label: 'Leave Calendar',    icon: 'calendar_month'  },
]);

// Approve / Reject flow
const showActionModal = ref(false);
const actionTarget    = ref(null);
const actionType      = ref('approved'); // 'approved' | 'rejected'

const actionForm = useForm({
    status:  'approved',
    comment: '',
});

function initiateAction(req, type) {
    actionTarget.value  = req;
    actionType.value    = type;
    actionForm.reset();
    actionForm.clearErrors();
    actionForm.status = type;
    showActionModal.value = true;
}

function withdrawRequest(req) {
    if (! req) return;
    if (! window.confirm('Withdraw this leave request? This cannot be undone — you will need to submit a fresh request if you change your mind.')) return;
    router.delete(route('leave.destroy', req.id), {
        preserveScroll: true,
        onFinish: () => { showDetailPanel.value = false; },
    });
}

function submitAction() {
    if (!actionTarget.value) return;
    actionForm.status = actionType.value;
    actionForm.patch(route('leave.update', actionTarget.value.id), {
        preserveState: false,
        preserveScroll: true,
        // Close only on success; on failure keep the modal open so the error shows.
        onSuccess: () => { showActionModal.value = false; actionTarget.value = null; },
    });
}

// HR Filters
const filterEmployee = ref(props.filters?.employee_id ?? '');
const filterType     = ref(props.filters?.type ?? '');
const filterStatus   = ref(props.filters?.status ?? '');
const filterFrom     = ref(props.filters?.from ?? '');
const filterTo       = ref(props.filters?.to ?? '');

function applyFilters() {
    router.get(route('leave.index'), {
        employee_id: filterEmployee.value || undefined,
        type:        filterType.value     || undefined,
        status:      filterStatus.value   || undefined,
        from:        filterFrom.value     || undefined,
        to:          filterTo.value       || undefined,
    }, { preserveState: true });
}

function clearFilters() {
    filterEmployee.value = '';
    filterType.value     = '';
    filterStatus.value   = '';
    filterFrom.value     = '';
    filterTo.value       = '';
    router.get(route('leave.index'), {}, { preserveState: false });
}

// Pending urgency (days since submitted)
function daysSince(dateStr) {
    if (!dateStr) return 0;
    return Math.floor((Date.now() - new Date(dateStr).getTime()) / 86400000);
}

function urgencyClass(days) {
    if (days >= 5) return 'text-red-600 dark:text-red-400';
    if (days >= 2) return 'text-amber-600 dark:text-amber-400';
    return 'text-on-surface-variant';
}

// Formatted date
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GH', { day: '2-digit', month: 'short', year: 'numeric' });
}

function fmtDateShort(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GH', { day: '2-digit', month: 'short' });
}

// Avatars — disciplined cool-family gradients (matches Employees pages)
const AVATAR_GRADIENTS = [
    'linear-gradient(135deg,#0d1452,#1a237e)',          // navy → cobalt
    'linear-gradient(135deg,#1a237e,#7986cb)',          // cobalt → soft sky
    'linear-gradient(135deg,#070b3a,#0d1452)',          // deep navy → navy
    'linear-gradient(135deg,#1a237e,#3949ab)',          // cobalt → bright blue
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',  // navy → cobalt → magenta (people spark)
    'linear-gradient(135deg,#1a237e,#12d9e3)',          // cobalt → cyan
];
function avatarColor(name) {
    let hash = 0;
    for (let i = 0; i < (name?.length ?? 0); i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_GRADIENTS[Math.abs(hash) % AVATAR_GRADIENTS.length];
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

// Today stats (HR)
const approvedToday = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return props.leaves?.data?.filter(r =>
        r.status === 'approved' && r.updated_at?.startsWith(today)
    ).length ?? 0;
});

const onLeaveNow = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return props.leaves?.data?.filter(r =>
        r.status === 'approved' &&
        r.start_date <= today && r.end_date >= today
    ).length ?? 0;
});

// ── Editorial-Sovereign masthead ──────────────────────────────────
// Volume = year offset from CIHRM founding (2023). Issue = day-of-year.
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
        date: d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

// Headline pending — prefer prop; fall back to derived for employee view
const headlinePending = computed(() => {
    if (typeof props.pendingCount === 'number') return props.pendingCount;
    return (props.leaves?.data ?? []).filter(l => l.status === 'pending').length;
});

// Approved / rejected this month (derived from current leaves page) — only
// surfaced as strip metrics when the underlying collection actually contains
// matching rows; otherwise the cell is omitted (no invented numbers).
const monthKey = computed(() => new Date().toISOString().slice(0, 7));
const approvedThisMonth = computed(() =>
    (props.leaves?.data ?? []).filter(r =>
        r.status === 'approved' && (r.updated_at ?? r.start_date ?? '').startsWith(monthKey.value)
    ).length
);
const rejectedThisMonth = computed(() =>
    (props.leaves?.data ?? []).filter(r =>
        r.status === 'rejected' && (r.updated_at ?? '').startsWith(monthKey.value)
    ).length
);

// Employee-view remaining-days headline: sum of `remaining` across configured balances
const remainingDaysTotal = computed(() =>
    (props.balances ?? []).reduce((sum, b) => sum + (Number(b.remaining) || 0), 0)
);
const annualRemaining = computed(() => {
    const a = (props.balances ?? []).find(b => b.type === 'annual');
    return a ? Number(a.remaining) || 0 : null;
});

// Inline calendar for manager view
const calMonth   = ref(new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0'));
const calYear    = computed(() => parseInt(calMonth.value.split('-')[0]));
const calMonthN  = computed(() => parseInt(calMonth.value.split('-')[1]) - 1);

const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAY_NAMES   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

const GH_HOLIDAYS = {
    '01-01': 'New Year\'s Day',
    '01-07': 'Constitution Day',
    '03-06': 'Independence Day',
    '05-01': 'Workers\' Day',
    '08-04': 'Founders\' Day',
    '09-21': 'Kwame Nkrumah Day',
    '12-25': 'Christmas Day',
    '12-26': 'Boxing Day',
};

const calDays = computed(() => {
    const y = calYear.value;
    const m = calMonthN.value;
    const firstDay = new Date(y, m, 1).getDay(); // 0=Sun
    // Convert to Mon-based: Mon=0
    const offset = firstDay === 0 ? 6 : firstDay - 1;
    const daysInMonth = new Date(y, m + 1, 0).getDate();

    const cells = [];
    for (let i = 0; i < offset; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${y}-${String(m + 1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const mmdd    = `${String(m + 1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        cells.push({
            date: dateStr,
            day:  d,
            holiday: GH_HOLIDAYS[mmdd] ?? null,
            leaves: (props.leaves?.data ?? []).filter(r =>
                r.status === 'approved' &&
                r.start_date <= dateStr && r.end_date >= dateStr
            ),
        });
    }
    return cells;
});

const calRows = computed(() => {
    const rows = [];
    for (let i = 0; i < calDays.value.length; i += 7) rows.push(calDays.value.slice(i, i + 7));
    return rows;
});

function prevMonth() {
    const [y, m] = calMonth.value.split('-').map(Number);
    const d = new Date(y, m - 2, 1);
    calMonth.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function nextMonth() {
    const [y, m] = calMonth.value.split('-').map(Number);
    const d = new Date(y, m, 1);
    calMonth.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}

const isToday = (dateStr) => dateStr === new Date().toISOString().slice(0, 10);

// Calendar day detail panel
const showDayPanel = ref(false);
const dayDetail    = ref(null);

function openDay(cell) {
    if (!cell) return;
    dayDetail.value   = cell;
    showDayPanel.value = true;
}

// Input / select shared class
const inputCls = 'w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all';
const labelCls = 'block text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1.5';
</script>

<template>
    <Head title="Leave Management — CIHRMS" />
    <div data-page-root="true">

            <!-- ── EMPLOYEE / MANAGER VIEW ──────────────────────────────────────── -->
            <template v-if="!isHR">

                <!-- ─── Executive header ─────────────────────── -->
                <section class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">beach_access</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">LEAVE POSTURE</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Leave Posture</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Your standing entitlements under the Ghana Labour Act, 2003 (Act 651) — annual, sick, maternity and statutory leave.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showApplyPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">event_available</span>
                            Request Leave
                        </button>
                    </div>
                </section>

                <!-- Balance Cards (horizontal scroll) -->
                <div class="mb-6 -mx-1 flex gap-3 overflow-x-auto px-1 pb-2 scrollbar-none">
                    <div
                        v-for="bal in (balances ?? [])"
                        :key="bal.type"
                        class="group flex-shrink-0 min-w-[148px] rounded-2xl border border-outline-variant/50 p-4 text-center shadow-card transition-all duration-200 hover:shadow-card-hover hover:-translate-y-0.5 cursor-default ring-1 ring-transparent hover:ring-outline-variant/30"
                        :class="balanceColors[bal.type]?.bg || 'bg-surface-container-lowest'"
                        :style="balanceTint(bal.type)"
                    >
                        <!-- Ring -->
                        <div class="relative mx-auto mb-3 flex h-14 w-14 items-center justify-center">
                            <ProgressRing
                                :used="bal.used_days"
                                :total="bal.total_days"
                                :color="balanceColors[bal.type]?.color ?? '#64748b'"
                                :size="56"
                                :stroke="5"
                            />
                            <span
                                class="material-symbols-outlined absolute text-[18px]"
                                :style="`color:${balanceColors[bal.type]?.color ?? '#64748b'};font-variation-settings:'FILL' 1`"
                            >{{ typeMap[bal.type]?.icon ?? 'event' }}</span>
                        </div>

                        <!-- Type label -->
                        <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60 leading-none mb-1">
                            {{ bal.label ?? leaveTypeLabel(bal.type) }}
                        </p>

                        <!-- Remaining -->
                        <p
                            class="text-[22px] font-black leading-none tabular-nums"
                            :style="`color:${balanceColors[bal.type]?.color ?? '#64748b'}`"
                        >{{ bal.remaining }}</p>
                        <p class="mt-0.5 text-[10px] font-semibold text-on-surface-variant/50">days remaining</p>

                        <!-- Used / Total -->
                        <p class="mt-2 text-[10px] text-on-surface-variant/40 font-medium">
                            {{ bal.used_days }} used / {{ bal.total_days }} total
                        </p>
                    </div>

                    <!-- Placeholder if no balances -->
                    <div v-if="!balances?.length" class="text-[13px] text-on-surface-variant/50 py-4 px-2">
                        No leave balances configured.
                    </div>
                </div>

                <!-- My Requests Table -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <!-- Table header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b border-outline-variant/40">
                        <h2 class="text-[14px] font-bold text-on-surface">My Requests</h2>
                        <span class="rounded-full bg-surface-container px-2.5 py-0.5 text-[11px] font-bold text-on-surface-variant">
                            {{ requests?.total ?? 0 }} total
                        </span>
                    </div>

                    <div class="max-h-[calc(100vh-460px)] min-h-[260px] overflow-auto">
                        <table class="w-full text-[13px]">
                            <thead class="sticky top-0 z-10">
                                <tr class="bg-surface-container-low/95 backdrop-blur-sm border-b border-outline-variant/40">
                                    <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Type</th>
                                    <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Start</th>
                                    <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">End</th>
                                    <th class="px-4 py-3 text-center text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Days</th>
                                    <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 hidden md:table-cell">Reason</th>
                                    <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                    <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 hidden lg:table-cell">Applied</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(req, idx) in (requests?.data ?? [])"
                                    :key="req.id"
                                    class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                                    :style="idx % 2 === 1 ? 'background:rgba(var(--ct-surface-low)/0.35)' : ''"
                                    @click="openDetail(req)"
                                >
                                    <td class="px-5 py-3.5">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold"
                                            :style="`background:${leaveTypeColor(req.type)}18;color:${leaveTypeColor(req.type)}`"
                                        >
                                            <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">
                                                {{ typeMap[req.type]?.icon ?? 'event' }}
                                            </span>
                                            {{ leaveTypeLabel(req.type) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 text-on-surface font-medium">{{ fmtDateShort(req.start_date) }}</td>
                                    <td class="px-5 py-3.5 text-on-surface font-medium">{{ fmtDateShort(req.end_date) }}</td>
                                    <td class="px-4 py-3.5 text-center">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-surface-container text-[11px] font-black text-on-surface-variant">
                                            {{ req.days_count ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 hidden md:table-cell">
                                        <span class="text-on-surface-variant/70 line-clamp-1 max-w-[180px] block">{{ req.reason ?? '—' }}</span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <StatusBadge :status="req.status" type="leave" />
                                    </td>
                                    <td class="px-5 py-3.5 text-on-surface-variant/60 hidden lg:table-cell">{{ fmtDate(req.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <EmptyState
                            v-if="!requests?.data?.length"
                            title="No leave requests yet"
                            description="Your leave applications will appear here once submitted."
                            icon="calendar_today"
                        >
                            <template #action>
                                <button
                                    class="btn-shimmer rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                    @click="showApplyPanel = true"
                                >Apply for Leave</button>
                            </template>
                        </EmptyState>
                    </div>

                    <!-- Pagination -->
                    <div v-if="requests?.links?.length > 3" class="border-t border-outline-variant/40 px-5 py-3">
                        <Pagination :links="requests.links" :meta="requests.meta" />
                    </div>
                </div>

            </template><!-- END employee view -->


            <!-- ── HR / ADMIN VIEW ────────────────────────────────────────────────── -->
            <template v-else>

                <!-- ─── Executive header (HR) ─────────────────── -->
                <section class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">beach_access</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">LEAVE POSTURE</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Leave Approvals Desk</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Institutional view of statutory leave — pending approvals, current absences, and officers off-station under Act 651.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="activeTab = 'pending'"
                                class="flex items-center gap-2 rounded-xl border border-outline-variant/50 bg-surface-container-lowest px-4 py-2.5 text-[13px] font-black text-primary shadow-card transition-all hover:-translate-y-px hover:shadow-card-hover">
                            <span class="material-symbols-outlined text-[17px]">pending_actions</span>
                            Approvals
                        </button>
                        <button @click="showApplyPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">event_available</span>
                            Request Leave
                        </button>
                    </div>
                </section>

                <!-- Tab bar -->
                <div class="rounded-t-2xl bg-surface-container-lowest border border-outline-variant/50 border-b-0 shadow-card overflow-hidden">
                    <div class="px-2 pt-2">
                        <TabBar :tabs="hrTabs" v-model="activeTab" />
                    </div>
                </div>

                <!-- Tab content -->
                <div class="rounded-b-2xl bg-surface-container-lowest border border-outline-variant/50 border-t-0 shadow-card overflow-hidden">

                    <!-- ── PENDING APPROVALS ── -->
                    <template v-if="activeTab === 'pending'">
                        <div class="max-h-[calc(100vh-460px)] min-h-[260px] overflow-auto">
                            <table class="w-full text-[13px]">
                                <thead class="sticky top-0 z-10">
                                    <tr class="bg-surface-container-low/95 backdrop-blur-sm border-b border-outline-variant/40">
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Employee</th>
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Type</th>
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Dates</th>
                                        <th class="px-4 py-3 text-center text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Days</th>
                                        <th class="px-4 py-3 text-center text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 hidden md:table-cell">Waiting</th>
                                        <th class="px-5 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="req in (requests?.data ?? []).filter(r => r.status === 'pending')"
                                        :key="req.id"
                                        class="group border-b border-outline-variant/25 transition-colors hover:bg-secondary/[0.04]"
                                    >
                                        <!-- Employee -->
                                        <td class="px-5 py-3.5">
                                            <div class="flex items-center gap-2.5">
                                                <div
                                                    class="h-9 w-9 flex-shrink-0 rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm flex items-center justify-center text-[11px] font-black text-white transition-transform group-hover:scale-105"
                                                    :style="`background:${avatarColor(req.employee_name)}`"
                                                >{{ initials(req.employee_name) }}</div>
                                                <div class="min-w-0">
                                                    <p class="font-bold text-on-surface truncate max-w-[140px]">{{ req.employee_name }}</p>
                                                    <p class="text-[11px] text-on-surface-variant/55 font-mono">{{ req.employee_no ?? '' }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <!-- Type -->
                                        <td class="px-5 py-3.5">
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                                :style="`background:${leaveTypeColor(req.type)}18;color:${leaveTypeColor(req.type)}`"
                                            >
                                                <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">{{ typeMap[req.type]?.icon ?? 'event' }}</span>
                                                {{ leaveTypeLabel(req.type) }}
                                            </span>
                                        </td>
                                        <!-- Dates -->
                                        <td class="px-5 py-3.5">
                                            <span class="font-medium text-on-surface">{{ fmtDateShort(req.start_date) }}</span>
                                            <span class="mx-1 text-on-surface-variant/40">—</span>
                                            <span class="font-medium text-on-surface">{{ fmtDateShort(req.end_date) }}</span>
                                        </td>
                                        <!-- Days count -->
                                        <td class="px-4 py-3.5 text-center">
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-surface-container text-[11px] font-black text-on-surface-variant">
                                                {{ req.days_count ?? '?' }}
                                            </span>
                                        </td>
                                        <!-- Waiting -->
                                        <td class="px-4 py-3.5 text-center hidden md:table-cell">
                                            <span
                                                class="text-[12px] font-bold"
                                                :class="urgencyClass(daysSince(req.created_at))"
                                            >{{ daysSince(req.created_at) }}d</span>
                                        </td>
                                        <!-- Actions -->
                                        <td class="px-5 py-3.5">
                                            <div class="flex items-center justify-end gap-2">
                                                <button
                                                    class="flex items-center gap-1.5 rounded-lg border border-green-200/60 dark:border-green-700/40 bg-green-50 dark:bg-green-900/20 px-3 py-1.5 text-[12px] font-bold text-green-700 dark:text-green-400 hover:bg-green-100 hover:border-green-400/60 dark:hover:bg-green-900/40 transition-all hover:-translate-y-px"
                                                    @click.stop="initiateAction(req, 'approved')"
                                                >
                                                    <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                                                    Approve
                                                </button>
                                                <button
                                                    class="flex items-center gap-1.5 rounded-lg border border-red-200/60 dark:border-red-700/40 bg-red-50 dark:bg-red-900/20 px-3 py-1.5 text-[12px] font-bold text-red-700 dark:text-red-400 hover:bg-red-100 hover:border-red-400/60 dark:hover:bg-red-900/40 transition-all hover:-translate-y-px"
                                                    @click.stop="initiateAction(req, 'rejected')"
                                                >
                                                    <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">cancel</span>
                                                    Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <EmptyState
                                v-if="!(requests?.data ?? []).some(r => r.status === 'pending')"
                                title="No pending requests"
                                description="All leave requests have been actioned."
                                icon="task_alt"
                            />
                        </div>
                    </template>

                    <!-- ── ALL REQUESTS ── -->
                    <template v-else-if="activeTab === 'all'">
                        <!-- Filter bar -->
                        <div class="border-b border-outline-variant/40 bg-surface-container-low/50 px-5 py-4">
                            <div class="flex flex-wrap items-end gap-3">
                                <!-- Employee filter -->
                                <div class="min-w-[160px] flex-1">
                                    <label :class="labelCls">Employee</label>
                                    <select v-model="filterEmployee" aria-label="Filter by employee" :class="inputCls">
                                        <option value="">All Employees</option>
                                        <option v-for="emp in (employees ?? [])" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                                    </select>
                                </div>
                                <!-- Type filter -->
                                <div class="min-w-[140px]">
                                    <label :class="labelCls">Leave Type</label>
                                    <select v-model="filterType" aria-label="Filter by leave type" :class="inputCls">
                                        <option value="">All Types</option>
                                        <option v-for="t in LEAVE_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
                                    </select>
                                </div>
                                <!-- Status filter -->
                                <div class="min-w-[130px]">
                                    <label :class="labelCls">Status</label>
                                    <select v-model="filterStatus" aria-label="Filter by status" :class="inputCls">
                                        <option value="">All Statuses</option>
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                                <!-- Date from -->
                                <div class="min-w-[130px]">
                                    <label :class="labelCls">From</label>
                                    <input type="date" v-model="filterFrom" aria-label="Filter from date" :class="inputCls" />
                                </div>
                                <!-- Date to -->
                                <div class="min-w-[130px]">
                                    <label :class="labelCls">To</label>
                                    <input type="date" v-model="filterTo" aria-label="Filter to date" :class="inputCls" />
                                </div>
                                <!-- Actions -->
                                <div class="flex gap-2">
                                    <button
                                        class="btn-shimmer rounded-xl px-4 py-2.5 text-[13px] font-bold text-white"
                                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                        @click="applyFilters"
                                    >Apply</button>
                                    <button
                                        class="rounded-xl border border-outline-variant px-4 py-2.5 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                                        @click="clearFilters"
                                    >Clear</button>
                                </div>
                            </div>
                        </div>

                        <div class="max-h-[calc(100vh-460px)] min-h-[260px] overflow-auto">
                            <table class="w-full text-[13px]">
                                <thead class="sticky top-0 z-10">
                                    <tr class="bg-surface-container-low/95 backdrop-blur-sm border-b border-outline-variant/40">
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Employee</th>
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Type</th>
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 hidden md:table-cell">Period</th>
                                        <th class="px-4 py-3 text-center text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Days</th>
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                        <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 hidden lg:table-cell">Applied</th>
                                        <th class="px-5 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(req, idx) in (requests?.data ?? [])"
                                        :key="req.id"
                                        class="group border-b border-outline-variant/25 transition-colors hover:bg-secondary/[0.04]"
                                        :style="idx % 2 === 1 ? 'background:rgba(var(--ct-surface-low)/0.3)' : ''"
                                    >
                                        <td class="px-5 py-3.5">
                                            <div class="flex items-center gap-2.5">
                                                <div
                                                    class="h-8 w-8 flex-shrink-0 rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm flex items-center justify-center text-[10px] font-black text-white"
                                                    :style="`background:${avatarColor(req.employee_name)}`"
                                                >{{ initials(req.employee_name) }}</div>
                                                <span class="font-bold text-on-surface truncate max-w-[120px]">{{ req.employee_name }}</span>
                                            </div>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold"
                                                :style="`background:${leaveTypeColor(req.type)}15;color:${leaveTypeColor(req.type)}`"
                                            >{{ leaveTypeLabel(req.type) }}</span>
                                        </td>
                                        <td class="px-5 py-3.5 hidden md:table-cell text-on-surface-variant">
                                            {{ fmtDateShort(req.start_date) }} — {{ fmtDateShort(req.end_date) }}
                                        </td>
                                        <td class="px-4 py-3.5 text-center">
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-surface-container text-[11px] font-black text-on-surface-variant">
                                                {{ req.days_count ?? '?' }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <StatusBadge :status="req.status" type="leave" />
                                        </td>
                                        <td class="px-5 py-3.5 text-on-surface-variant/60 hidden lg:table-cell">{{ fmtDate(req.created_at) }}</td>
                                        <td class="px-5 py-3.5">
                                            <div class="flex justify-end gap-1">
                                                <button
                                                    v-if="req.status === 'pending'"
                                                    class="flex items-center gap-1 rounded-lg border border-green-200/60 dark:border-green-700/40 bg-green-50 dark:bg-green-900/20 px-2.5 py-1 text-[11px] font-bold text-green-700 dark:text-green-400 hover:bg-green-100 hover:border-green-400/60 dark:hover:bg-green-900/40 transition-all"
                                                    @click.stop="initiateAction(req, 'approved')"
                                                    title="Approve request"
                                                >
                                                    <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                                                    Approve
                                                </button>
                                                <button
                                                    v-if="req.status === 'pending'"
                                                    class="flex items-center gap-1 rounded-lg border border-red-200/60 dark:border-red-700/40 bg-red-50 dark:bg-red-900/20 px-2.5 py-1 text-[11px] font-bold text-red-700 dark:text-red-400 hover:bg-red-100 hover:border-red-400/60 dark:hover:bg-red-900/40 transition-all"
                                                    @click.stop="initiateAction(req, 'rejected')"
                                                    title="Reject request"
                                                >
                                                    <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">cancel</span>
                                                    Reject
                                                </button>
                                                <button
                                                    class="flex items-center gap-1 rounded-lg border border-transparent bg-surface-container px-2.5 py-1 text-[11px] font-semibold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary hover:border-secondary/15 transition-all"
                                                    @click.stop="openDetail(req)"
                                                    title="View details"
                                                >
                                                    View
                                                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <EmptyState
                                v-if="!requests?.data?.length"
                                title="No leave requests found"
                                description="Try adjusting your filters."
                                icon="search_off"
                            />
                        </div>

                        <!-- Pagination -->
                        <div v-if="requests?.links?.length > 3" class="border-t border-outline-variant/40 px-5 py-3">
                            <Pagination :links="requests.links" :meta="requests.meta" />
                        </div>
                    </template>

                    <!-- ── CALENDAR TAB ── -->
                    <template v-else-if="activeTab === 'calendar'">
                        <div class="p-5">
                            <!-- Calendar nav -->
                            <div class="mb-4 flex items-center justify-between">
                                <button
                                    class="flex h-9 w-9 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant hover:bg-surface-container transition-colors"
                                    @click="prevMonth"
                                    aria-label="Previous month"
                                >
                                    <span class="material-symbols-outlined text-[20px]">chevron_left</span>
                                </button>
                                <h3 class="text-[16px] font-black text-on-surface tracking-tight">
                                    {{ MONTH_NAMES[calMonthN] }} {{ calYear }}
                                </h3>
                                <button
                                    class="flex h-9 w-9 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant hover:bg-surface-container transition-colors"
                                    @click="nextMonth"
                                    aria-label="Next month"
                                >
                                    <span class="material-symbols-outlined text-[20px]">chevron_right</span>
                                </button>
                            </div>

                            <!-- Day labels -->
                            <div class="grid grid-cols-7 gap-px mb-1">
                                <div
                                    v-for="d in DAY_NAMES"
                                    :key="d"
                                    class="py-2 text-center text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50"
                                >{{ d }}</div>
                            </div>

                            <!-- Calendar grid -->
                            <div class="grid grid-cols-7 gap-px bg-outline-variant/20 rounded-xl overflow-hidden border border-outline-variant/30">
                                <template v-for="(row, ri) in calRows" :key="ri">
                                    <div
                                        v-for="(cell, ci) in row"
                                        :key="ci"
                                        class="min-h-[80px] bg-surface-container-lowest p-1.5 transition-colors"
                                        :class="[
                                            cell ? 'cursor-pointer hover:bg-surface-container/70' : 'bg-surface-container/20 opacity-40',
                                            cell?.holiday ? 'bg-slate-50 dark:bg-slate-900/30' : '',
                                        ]"
                                        @click="cell && openDay(cell)"
                                    >
                                        <template v-if="cell">
                                            <!-- Date number -->
                                            <div class="flex items-center justify-between mb-1">
                                                <span
                                                    class="flex h-6 w-6 items-center justify-center rounded-full text-[12px] font-bold"
                                                    :class="isToday(cell.date)
                                                        ? 'bg-secondary text-white'
                                                        : 'text-on-surface'"
                                                >{{ cell.day }}</span>
                                                <!-- Holiday indicator -->
                                                <span
                                                    v-if="cell.holiday"
                                                    class="h-1.5 w-1.5 rounded-full bg-slate-400"
                                                    :title="cell.holiday"
                                                ></span>
                                            </div>
                                            <!-- Holiday name -->
                                            <p v-if="cell.holiday" class="text-[9px] font-semibold text-slate-500 dark:text-slate-400 truncate mb-0.5">{{ cell.holiday }}</p>
                                            <!-- Leave chips -->
                                            <div class="flex flex-col gap-0.5">
                                                <span
                                                    v-for="leave in cell.leaves.slice(0, 3)"
                                                    :key="leave.id"
                                                    class="rounded-full px-1.5 py-0.5 text-[9px] font-bold truncate"
                                                    :style="`background:${leaveTypeColor(leave.type)}20;color:${leaveTypeColor(leave.type)}`"
                                                    :title="leave.employee_name"
                                                >{{ leave.employee_name?.split(' ')[0] }}</span>
                                                <span
                                                    v-if="cell.leaves.length > 3"
                                                    class="text-[9px] font-bold text-on-surface-variant/50 pl-1"
                                                >+{{ cell.leaves.length - 3 }} more</span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <!-- Legend -->
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mr-1">Legend:</p>
                                <div v-for="t in LEAVE_TYPES.slice(0, 5)" :key="t.value" class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="`background:${t.color}`"></span>
                                    <span class="text-[11px] font-medium text-on-surface-variant">{{ t.label }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>
                                    <span class="text-[11px] font-medium text-on-surface-variant">Public Holiday</span>
                                </div>
                            </div>
                        </div>
                    </template>

                </div><!-- end tab content card -->

            </template><!-- END HR view -->


            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 SLIDE PANELS & MODALS
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->

            <!-- Apply Leave Panel (employee) -->
            <SlidePanel
                :open="showApplyPanel"
                title="Apply for Leave"
                subtitle="Submit a new leave request for approval"
                size="md"
                @close="showApplyPanel = false"
            >
                <form @submit.prevent="submitLeave" class="space-y-5">
                    <!-- Leave Type -->
                    <div>
                        <label :class="labelCls">Leave Type</label>
                        <select v-model="leaveForm.type" aria-label="Leave type" :class="inputCls" required>
                            <option v-for="t in LEAVE_TYPES" :key="t.value" :value="t.value">
                                {{ t.label }}{{ t.days ? ` (up to ${t.days} days)` : '' }}
                            </option>
                        </select>
                        <p v-if="leaveForm.errors.type" class="mt-1 text-[12px] text-red-500">{{ leaveForm.errors.type }}</p>
                    </div>

                    <!-- Ghana policy note -->
                    <div
                        v-if="typeMap[leaveForm.type]"
                        class="rounded-xl border border-secondary/15 bg-secondary/5 px-4 py-3 flex items-start gap-3"
                    >
                        <span class="material-symbols-outlined text-[18px] text-secondary mt-0.5 flex-shrink-0" style="font-variation-settings:'FILL' 1">info</span>
                        <div>
                            <p class="text-[12px] font-bold text-secondary">{{ typeMap[leaveForm.type].label }}</p>
                            <p class="text-[11px] text-on-surface-variant/70 mt-0.5">
                                <template v-if="leaveForm.type === 'annual'">15 working days per year (Labour Act 651)</template>
                                <template v-else-if="leaveForm.type === 'sick'">Up to 14 days with medical certificate</template>
                                <template v-else-if="leaveForm.type === 'maternity'">12 weeks (84 days); 14 weeks for multiple births</template>
                                <template v-else-if="leaveForm.type === 'paternity'">5 working days (birth of child)</template>
                                <template v-else-if="leaveForm.type === 'emergency'">Up to 3 days for urgent family matters</template>
                                <template v-else-if="leaveForm.type === 'study'">Duration as approved by supervisor</template>
                                <template v-else>No pay; approved on a case-by-case basis</template>
                            </p>
                        </div>
                    </div>

                    <!-- Date range -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label :class="labelCls">Start Date</label>
                            <input aria-label="Start Date"
                                type="date"
                                v-model="leaveForm.start_date"
                                :class="inputCls"
                                :min="new Date().toISOString().slice(0,10)"
                                required
                            />
                            <p v-if="leaveForm.errors.start_date" class="mt-1 text-[12px] text-red-500">{{ leaveForm.errors.start_date }}</p>
                        </div>
                        <div>
                            <label :class="labelCls">End Date</label>
                            <input aria-label="End Date"
                                type="date"
                                v-model="leaveForm.end_date"
                                :class="inputCls"
                                :min="leaveForm.start_date || new Date().toISOString().slice(0,10)"
                                required
                            />
                            <p v-if="leaveForm.errors.end_date" class="mt-1 text-[12px] text-red-500">{{ leaveForm.errors.end_date }}</p>
                        </div>
                    </div>

                    <!-- Days computed -->
                    <div
                        v-if="requestedDays > 0"
                        class="rounded-xl bg-surface-container-low px-4 py-3 flex items-center justify-between"
                    >
                        <span class="text-[12px] font-semibold text-on-surface-variant">Working days requested:</span>
                        <span class="text-[18px] font-black text-on-surface tabular-nums">{{ requestedDays }}</span>
                    </div>

                    <!-- Reason -->
                    <div>
                        <label :class="labelCls">Reason</label>
                        <textarea aria-label="Reason"
                            v-model="leaveForm.reason"
                            :class="inputCls + ' resize-none'"
                            rows="3"
                            placeholder="Brief reason for your leave request…"
                            required
                        ></textarea>
                        <p v-if="leaveForm.errors.reason" class="mt-1 text-[12px] text-red-500">{{ leaveForm.errors.reason }}</p>
                    </div>

                    <!-- Attachment -->
                    <div>
                        <label :class="labelCls">Supporting Document <span class="normal-case font-medium text-on-surface-variant/40">(optional)</span></label>
                        <FileUpload
                            v-model="leaveForm.attachment"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                            :max-size-mb="5"
                            label="Upload supporting document"
                        />
                        <p v-if="leaveForm.errors.attachment" class="mt-1 text-[12px] text-red-500">{{ leaveForm.errors.attachment }}</p>
                    </div>
                </form>

                <template #footer>
                    <button
                        type="button"
                        class="rounded-xl border border-outline-variant px-5 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                        @click="showApplyPanel = false"
                    >Cancel</button>
                    <button
                        type="button"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        :disabled="leaveForm.processing"
                        @click="submitLeave"
                    >
                        <svg v-if="leaveForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                        Submit Request
                    </button>
                </template>
            </SlidePanel>


            <!-- Request Detail Panel -->
            <SlidePanel
                :open="showDetailPanel"
                :title="selectedRequest ? leaveTypeLabel(selectedRequest.type) + ' Leave' : 'Leave Details'"
                subtitle="Full request details and status history"
                size="md"
                @close="showDetailPanel = false"
            >
                <div v-if="selectedRequest" class="space-y-5">
                    <!-- Status + type -->
                    <div class="flex items-center justify-between">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-bold"
                            :style="`background:${leaveTypeColor(selectedRequest.type)}18;color:${leaveTypeColor(selectedRequest.type)}`"
                        >
                            <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">{{ typeMap[selectedRequest.type]?.icon ?? 'event' }}</span>
                            {{ leaveTypeLabel(selectedRequest.type) }}
                        </span>
                        <StatusBadge :status="selectedRequest.status" type="leave" />
                    </div>

                    <!-- Details grid -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-surface-container-low p-3">
                            <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-1">Start Date</p>
                            <p class="text-[14px] font-bold text-on-surface">{{ fmtDate(selectedRequest.start_date) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-low p-3">
                            <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-1">End Date</p>
                            <p class="text-[14px] font-bold text-on-surface">{{ fmtDate(selectedRequest.end_date) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-low p-3">
                            <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-1">Working Days</p>
                            <p class="text-[22px] font-black text-on-surface tabular-nums">{{ selectedRequest.days_count ?? '—' }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-low p-3">
                            <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-1">Applied On</p>
                            <p class="text-[14px] font-bold text-on-surface">{{ fmtDate(selectedRequest.created_at) }}</p>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div class="rounded-xl bg-surface-container-low p-4">
                        <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-2">Reason</p>
                        <p class="text-[13px] text-on-surface leading-relaxed">{{ selectedRequest.reason ?? 'No reason provided.' }}</p>
                    </div>

                    <!-- Supporting document indicator -->
                    <div v-if="selectedRequest.has_attachment" class="flex items-center gap-2 rounded-xl bg-surface-container-low px-4 py-3">
                        <span class="material-symbols-outlined text-[18px] text-secondary" style="font-variation-settings:'FILL' 1">attach_file</span>
                        <p class="text-[12px] font-semibold text-on-surface">Supporting document attached</p>
                    </div>

                    <!-- Status history -->
                    <div v-if="detailHistory.length">
                        <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/50 mb-3">Status History</p>
                        <div class="space-y-2">
                            <div
                                v-for="h in detailHistory"
                                :key="h.id"
                                class="flex items-start gap-3 rounded-xl bg-surface-container-low p-3"
                            >
                                <div class="mt-0.5 h-6 w-6 flex-shrink-0 rounded-full flex items-center justify-center"
                                     :class="h.status === 'approved' ? 'bg-green-100 dark:bg-green-900/30' : h.status === 'rejected' ? 'bg-red-100 dark:bg-red-900/30' : 'bg-amber-100 dark:bg-amber-900/30'">
                                    <span class="material-symbols-outlined text-[14px]"
                                          :class="h.status === 'approved' ? 'text-green-600 dark:text-green-400' : h.status === 'rejected' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                                          style="font-variation-settings:'FILL' 1">
                                        {{ h.status === 'approved' ? 'check_circle' : h.status === 'rejected' ? 'cancel' : 'pending' }}
                                    </span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <StatusBadge :status="h.status" type="leave" />
                                        <span class="text-[11px] text-on-surface-variant/50">{{ fmtDate(h.created_at) }}</span>
                                    </div>
                                    <p v-if="h.comment" class="mt-1 text-[12px] text-on-surface-variant">{{ h.comment }}</p>
                                    <p class="text-[11px] text-on-surface-variant/50 mt-0.5">by {{ h.actor_name }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- HR: quick action if still pending -->
                    <div v-if="isHR && selectedRequest.status === 'pending'" class="flex gap-3 pt-2">
                        <button
                            class="flex-1 rounded-xl border border-green-200 dark:border-green-700/40 bg-green-50 dark:bg-green-900/20 py-2.5 text-[13px] font-bold text-green-700 dark:text-green-400 hover:bg-green-100 transition-colors"
                            @click="initiateAction(selectedRequest, 'approved'); showDetailPanel = false"
                        >Approve</button>
                        <button
                            class="flex-1 rounded-xl border border-red-200 dark:border-red-700/40 bg-red-50 dark:bg-red-900/20 py-2.5 text-[13px] font-bold text-red-700 dark:text-red-400 hover:bg-red-100 transition-colors"
                            @click="initiateAction(selectedRequest, 'rejected'); showDetailPanel = false"
                        >Reject</button>
                    </div>

                    <!-- Self-service: requester can withdraw their own still-pending request -->
                    <div v-if="!isHR && selectedRequest.status === 'pending'" class="pt-2">
                        <button
                            class="w-full rounded-xl border border-rose-200 bg-rose-50 py-2.5 text-[13px] font-bold text-rose-700 hover:bg-rose-100 transition-colors"
                            @click="withdrawRequest(selectedRequest)"
                        >
                            <span class="material-symbols-outlined text-[16px] align-middle mr-1">undo</span>
                            Withdraw this request
                        </button>
                    </div>
                </div>
            </SlidePanel>


            <!-- Day Detail Panel (calendar) -->
            <SlidePanel
                :open="showDayPanel"
                :title="dayDetail ? fmtDate(dayDetail.date) : 'Day Details'"
                subtitle="Employees on leave this day"
                size="sm"
                @close="showDayPanel = false"
            >
                <div v-if="dayDetail">
                    <div v-if="dayDetail.holiday" class="mb-4 flex items-center gap-2 rounded-xl bg-slate-100 dark:bg-slate-800/40 px-4 py-3">
                        <span class="material-symbols-outlined text-[18px] text-slate-500" style="font-variation-settings:'FILL' 1">celebration</span>
                        <p class="text-[13px] font-bold text-slate-600 dark:text-slate-300">{{ dayDetail.holiday }}</p>
                    </div>

                    <div v-if="dayDetail.leaves?.length" class="space-y-2">
                        <div
                            v-for="leave in dayDetail.leaves"
                            :key="leave.id"
                            class="flex items-center gap-3 rounded-xl bg-surface-container-low p-3"
                        >
                            <div
                                class="h-9 w-9 flex-shrink-0 rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm flex items-center justify-center text-[11px] font-black text-white"
                                :style="`background:${avatarColor(leave.employee_name)}`"
                            >{{ initials(leave.employee_name) }}</div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-semibold text-on-surface truncate">{{ leave.employee_name }}</p>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold mt-0.5"
                                    :style="`background:${leaveTypeColor(leave.type)}18;color:${leaveTypeColor(leave.type)}`"
                                >{{ leaveTypeLabel(leave.type) }}</span>
                            </div>
                        </div>
                    </div>

                    <EmptyState
                        v-else
                        title="No one on leave"
                        description="All employees are in on this day."
                        icon="groups"
                    />
                </div>
            </SlidePanel>


            <!-- Approve/Reject Action Modal -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition-all duration-200 ease-spring"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition-all duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showActionModal"
                        class="fixed inset-0 z-[300] flex items-center justify-center bg-black/50 backdrop-blur-sm"
                        @click.self="showActionModal = false"
                    >
                        <Transition
                            enter-active-class="transition-all duration-200 ease-spring"
                            enter-from-class="opacity-0 scale-95"
                            enter-to-class="opacity-100 scale-100"
                            appear
                        >
                            <div
                                v-if="showActionModal"
                                class="mx-4 w-full max-w-md rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-lifted-lg"
                            >
                                <!-- Icon -->
                                <div class="flex justify-center mb-4">
                                    <div
                                        class="h-12 w-12 rounded-2xl flex items-center justify-center"
                                        :class="actionType === 'approved' ? 'bg-green-100 dark:bg-green-900/30' : 'bg-red-100 dark:bg-red-900/30'"
                                    >
                                        <span
                                            class="material-symbols-outlined text-[24px]"
                                            :class="actionType === 'approved' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                            style="font-variation-settings:'FILL' 1"
                                        >{{ actionType === 'approved' ? 'check_circle' : 'cancel' }}</span>
                                    </div>
                                </div>

                                <h3 class="text-[17px] font-bold text-on-surface text-center">
                                    {{ actionType === 'approved' ? 'Approve Leave Request' : 'Reject Leave Request' }}
                                </h3>
                                <p class="text-[13px] text-on-surface-variant text-center mt-1 mb-5">
                                    {{ actionTarget?.employee_name }} — {{ leaveTypeLabel(actionTarget?.type) }},
                                    {{ actionTarget?.days_count }} days
                                </p>

                                <!-- Comment -->
                                <div>
                                    <label :class="labelCls">Comment <span class="normal-case font-medium text-on-surface-variant/40">(optional)</span></label>
                                    <textarea aria-label="Comment (optional)"
                                        v-model="actionForm.comment"
                                        :class="inputCls + ' resize-none'"
                                        rows="3"
                                        :placeholder="actionType === 'approved' ? 'Any notes for the employee…' : 'Reason for rejection…'"
                                    ></textarea>
                                    <InputError :message="actionForm.errors.comment" />
                                    <InputError :message="actionForm.errors.status" />
                                </div>

                                <!-- Actions -->
                                <div class="mt-5 flex gap-3 justify-end">
                                    <button
                                        class="rounded-xl border border-outline-variant px-5 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                                        @click="showActionModal = false"
                                    >Cancel</button>
                                    <button
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                                        :style="actionType === 'approved'
                                            ? 'background:linear-gradient(135deg,#059669,#10b981)'
                                            : 'background:linear-gradient(135deg,#dc2626,#ef4444)'"
                                        :disabled="actionForm.processing"
                                        @click="submitAction"
                                    >
                                        <svg v-if="actionForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                        </svg>
                                        {{ actionType === 'approved' ? 'Confirm Approval' : 'Confirm Rejection' }}
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </Transition>
            </Teleport>

    </div>
</template>
