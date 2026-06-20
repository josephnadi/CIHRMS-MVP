<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge         from '@/Components/StatusBadge.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    leaveRequest: Object,
    activeModule: String,
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const isHR = computed(() => ['hr_admin', 'super_admin', 'ceo', 'manager'].includes(user.value?.role));

const lr = computed(() => props.leaveRequest?.data ?? props.leaveRequest);

// Leave type palette — aligned with Index.vue LEAVE_TYPES.
// annual=cobalt (action), maternity=magenta (people/family), paternity=cyan,
// study=cyan (learning), sick/emergency keep semantic colors.
// Fixes prior bug where annual+maternity both used #1a237e but maternity had
// the wrong rgb triplet for its tint.
const TYPE_META = {
    annual:    { color: '#1a237e', icon: 'beach_access',     rgb: '26, 35, 126'  },
    sick:      { color: '#d97706', icon: 'medical_services', rgb: '217,119,6'  },
    maternity: { color: '#d912e3', icon: 'child_care',       rgb: '217,18,227' },
    paternity: { color: '#0e8a93', icon: 'family_restroom',  rgb: '18,217,227' },
    emergency: { color: '#dc2626', icon: 'emergency',        rgb: '220,38,38'  },
    study:     { color: '#0e8a93', icon: 'school',           rgb: '18,217,227' },
    unpaid:    { color: '#64748b', icon: 'money_off',        rgb: '100,116,139'},
};
function metaFor(type) { return TYPE_META[type] ?? { color: '#64748b', icon: 'event', rgb: '100,116,139' }; }

function fmt(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GH', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
}
function fmtShort(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GH', { day: '2-digit', month: 'short', year: 'numeric' });
}
function fmtDateTime(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GH', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// Avatar gradient pool — disciplined cool family (matches Employees + Leave/Index)
const AVATAR_GRADIENTS = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < (name?.length ?? 0); i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return AVATAR_GRADIENTS[Math.abs(h) % AVATAR_GRADIENTS.length];
}
function initials(name) {
    if (!name) return '?';
    return name.trim().split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

const showActionPanel = ref(false);
const actionType      = ref('approved');
const actionComment   = ref('');
const actionLoading   = ref(false);

function open(action) {
    actionType.value = action;
    actionComment.value = '';
    showActionPanel.value = true;
}

function submit() {
    actionLoading.value = true;
    router.patch(
        route('leave.update', lr.value.id),
        { status: actionType.value, comment: actionComment.value },
        {
            preserveScroll: true,
            onFinish: () => {
                actionLoading.value = false;
                showActionPanel.value = false;
            },
        }
    );
}

// Build quick timeline
const timeline = computed(() => {
    // First event uses cobalt (action) — second event keeps semantic green/red/amber.
    const t = [
        {
            icon: 'send',
            title: 'Leave Requested',
            actor: lr.value?.employee?.name ?? 'Employee',
            at:    lr.value?.created_at,
            color: '#1a237e',
        },
    ];
    if (lr.value?.status === 'approved' && lr.value?.approver) {
        t.push({
            icon: 'check_circle',
            title: 'Approved',
            actor: lr.value.approver.name,
            at:    lr.value.decided_at ?? lr.value.updated_at,
            color: '#059669',
        });
    } else if (lr.value?.status === 'rejected' && lr.value?.approver) {
        t.push({
            icon: 'cancel',
            title: 'Rejected',
            actor: lr.value.approver.name,
            at:    lr.value.decided_at ?? lr.value.updated_at,
            color: '#dc2626',
        });
    } else {
        t.push({
            icon: 'pending',
            title: 'Awaiting Decision',
            actor: 'HR / Manager',
            at:    null,
            color: '#d97706',
            pending: true,
        });
    }
    return t;
});
</script>

<template>
    <Head :title="`Leave Request — ${lr?.type_label ?? ''}`" />
    <div data-page-root="true">

            <!-- Breadcrumbs -->
            <nav class="mb-3 flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60">
                <Link :href="route('leave.index')" class="hover:text-secondary transition-colors">Leave Management</Link>
                <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                <span class="text-on-surface">Request #{{ lr?.id }}</span>
            </nav>

            <!-- Header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl shadow-glow-sm overflow-hidden"
                         :style="`background:rgba(${metaFor(lr?.type).rgb},0.12);border:1px solid rgba(${metaFor(lr?.type).rgb},0.28);`">
                        <div class="pointer-events-none absolute inset-0" :style="`background:radial-gradient(circle at 30% 30%,rgba(${metaFor(lr?.type).rgb},0.20),transparent 70%)`"></div>
                        <span class="relative material-symbols-outlined text-[26px]"
                              :style="`color:${metaFor(lr?.type).color};font-variation-settings:'FILL' 1;filter:drop-shadow(0 0 6px rgba(${metaFor(lr?.type).rgb},0.35))`">
                            {{ metaFor(lr?.type).icon }}
                        </span>
                    </div>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-[22px] font-black tracking-tight text-on-surface">{{ lr?.type_label ?? 'Leave Request' }}</h1>
                            <StatusBadge :status="lr?.status" type="leave" />
                        </div>
                        <p class="mt-0.5 text-[13px] text-on-surface-variant">
                            Submitted <span class="font-semibold text-on-surface">{{ fmtShort(lr?.created_at) }}</span> · Request <span class="font-mono text-[12px]">#{{ lr?.id }}</span>
                        </p>
                    </div>
                </div>

                <div v-if="isHR && lr?.status === 'pending'" class="flex items-center gap-2">
                    <button
                        @click="open('approved')"
                        class="flex items-center gap-2 rounded-xl border border-green-200/60 dark:border-green-700/40 bg-green-50 dark:bg-green-900/20 px-4 py-2 text-[13px] font-bold text-green-700 dark:text-green-400 hover:bg-green-100 hover:border-green-400/60 transition-all hover:-translate-y-px shadow-sm"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                        Approve
                    </button>
                    <button
                        @click="open('rejected')"
                        class="flex items-center gap-2 rounded-xl border border-red-200/60 dark:border-red-700/40 bg-red-50 dark:bg-red-900/20 px-4 py-2 text-[13px] font-bold text-red-700 dark:text-red-400 hover:bg-red-100 hover:border-red-400/60 transition-all hover:-translate-y-px shadow-sm"
                    >
                        <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">cancel</span>
                        Reject
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                <!-- LEFT: details -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Date range card -->
                    <div class="overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card">
                        <div class="h-1 w-full" :style="`background:linear-gradient(90deg,${metaFor(lr?.type).color},transparent)`"></div>
                        <div class="p-6">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-4">Period</p>
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/45 mb-1">Start</p>
                                    <p class="text-[20px] font-black tracking-tight text-on-surface">{{ fmtShort(lr?.start_date) }}</p>
                                    <p class="mt-0.5 text-[12px] text-on-surface-variant/65">{{ fmt(lr?.start_date) }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/45 mb-1">End</p>
                                    <p class="text-[20px] font-black tracking-tight text-on-surface">{{ fmtShort(lr?.end_date) }}</p>
                                    <p class="mt-0.5 text-[12px] text-on-surface-variant/65">{{ fmt(lr?.end_date) }}</p>
                                </div>
                            </div>
                            <div class="mt-5 flex items-center gap-3 rounded-2xl bg-surface-container-low px-4 py-3">
                                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl"
                                     :style="`background:rgba(${metaFor(lr?.type).rgb},0.18)`">
                                    <span class="material-symbols-outlined text-[18px]"
                                          :style="`color:${metaFor(lr?.type).color}`">today</span>
                                </div>
                                <div class="flex-1">
                                    <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55">Working Days</p>
                                    <p class="text-[18px] font-black tabular-nums text-on-surface">{{ lr?.duration_days ?? '—' }} days</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                        <p class="mb-3 text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Reason for Leave</p>
                        <p v-if="lr?.reason" class="text-[14px] leading-relaxed text-on-surface whitespace-pre-line">{{ lr.reason }}</p>
                        <p v-else class="text-[13px] italic text-on-surface-variant/45">No reason provided.</p>
                    </div>

                    <!-- Approver decision (if applicable) -->
                    <div v-if="lr?.status !== 'pending' && lr?.approver"
                         class="rounded-2xl border p-6 shadow-card"
                         :class="lr?.status === 'approved'
                            ? 'border-green-200 dark:border-green-800/40 bg-green-50/40 dark:bg-green-900/10'
                            : 'border-red-200 dark:border-red-800/40 bg-red-50/40 dark:bg-red-900/10'"
                    >
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-[22px] mt-0.5"
                                  :class="lr?.status === 'approved' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                  style="font-variation-settings:'FILL' 1">
                                {{ lr?.status === 'approved' ? 'check_circle' : 'cancel' }}
                            </span>
                            <div class="flex-1">
                                <p class="text-[10px] font-black uppercase tracking-wider"
                                   :class="lr?.status === 'approved' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">
                                    {{ lr?.status === 'approved' ? 'Approved' : 'Rejected' }} by
                                </p>
                                <p class="text-[14px] font-bold text-on-surface">{{ lr.approver.name }}</p>
                                <p class="text-[11px] text-on-surface-variant/60 mt-0.5">{{ fmtDateTime(lr.decided_at ?? lr.updated_at) }}</p>
                                <p v-if="lr.decision_comment" class="mt-2 text-[13px] leading-relaxed text-on-surface whitespace-pre-line">{{ lr.decision_comment }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: meta + timeline -->
                <div class="space-y-6">

                    <!-- Employee card -->
                    <div v-if="lr?.employee" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                        <p class="mb-3 text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Requester</p>
                        <div class="flex items-center gap-3">
                            <div class="h-12 w-12 flex-shrink-0 rounded-2xl ring-2 ring-white dark:ring-surface-container-lowest flex items-center justify-center text-[14px] font-black text-white shadow-glow-sm overflow-hidden"
                                 :style="`background:${avatarColor(lr.employee.name ?? lr.employee.employee_no)}`">
                                {{ initials(lr.employee.name ?? lr.employee.employee_no) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[14px] font-bold text-on-surface truncate">{{ lr.employee.name ?? '—' }}</p>
                                <p class="text-[11px] text-on-surface-variant/60">{{ lr.employee.employee_no }}</p>
                                <p v-if="lr.employee.position" class="text-[11px] text-on-surface-variant/60 truncate">{{ lr.employee.position }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                        <p class="mb-4 text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Activity</p>
                        <ol class="relative border-l-2 border-outline-variant/40 pl-5 space-y-5">
                            <li v-for="(ev, i) in timeline" :key="i" class="relative">
                                <span class="absolute -left-[27px] flex h-5 w-5 items-center justify-center rounded-full text-white"
                                      :style="ev.pending
                                        ? `background:${ev.color}30;border:2px dashed ${ev.color}90;`
                                        : `background:${ev.color};box-shadow:0 0 12px ${ev.color}50`">
                                    <span v-if="!ev.pending" class="material-symbols-outlined text-[12px]" style="font-variation-settings:'FILL' 1">{{ ev.icon }}</span>
                                </span>
                                <p class="text-[13px] font-bold leading-tight"
                                   :class="ev.pending ? 'text-on-surface-variant' : 'text-on-surface'">
                                    {{ ev.title }}
                                </p>
                                <p class="text-[11px] text-on-surface-variant/65 mt-0.5">{{ ev.actor }}</p>
                                <p v-if="ev.at" class="text-[10.5px] text-on-surface-variant/45 font-mono mt-0.5">{{ fmtDateTime(ev.at) }}</p>
                            </li>
                        </ol>
                    </div>

                    <!-- Quick links -->
                    <div class="relative rounded-2xl border border-secondary/15 bg-secondary/5 p-5 overflow-hidden">
                        <div class="pointer-events-none absolute -top-8 -right-8 h-24 w-24 rounded-full" style="background:radial-gradient(circle,rgba(26, 35, 126,0.10),transparent 70%)"></div>
                        <p class="relative text-[10px] font-black uppercase tracking-[0.14em] text-secondary mb-3">Quick Links</p>
                        <div class="relative space-y-1">
                            <Link :href="route('leave.index')"
                                  class="group flex items-center gap-2 rounded-lg px-2 py-1.5 -mx-2 text-[12.5px] font-bold text-secondary hover:bg-secondary/10 transition-colors">
                                <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                                All leave requests
                                <span class="material-symbols-outlined ml-auto text-[15px] opacity-0 -translate-x-1 transition-all group-hover:opacity-100 group-hover:translate-x-0">chevron_right</span>
                            </Link>
                            <Link v-if="lr?.employee?.id" :href="route('employees.show', lr.employee.id)"
                                  class="group flex items-center gap-2 rounded-lg px-2 py-1.5 -mx-2 text-[12.5px] font-bold text-secondary hover:bg-secondary/10 transition-colors">
                                <span class="material-symbols-outlined text-[16px]">badge</span>
                                View employee profile
                                <span class="material-symbols-outlined ml-auto text-[15px] opacity-0 -translate-x-1 transition-all group-hover:opacity-100 group-hover:translate-x-0">chevron_right</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action panel (approve/reject) -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition-all duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition-all duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-if="showActionPanel"
                         class="fixed inset-0 z-[300] flex items-center justify-center bg-black/50 backdrop-blur-sm"
                         @click.self="showActionPanel = false"
                    >
                        <div class="mx-4 w-full max-w-md rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-lifted-lg">
                            <div class="flex justify-center mb-4">
                                <div class="h-12 w-12 rounded-2xl flex items-center justify-center"
                                     :class="actionType === 'approved' ? 'bg-green-100 dark:bg-green-900/30' : 'bg-red-100 dark:bg-red-900/30'">
                                    <span class="material-symbols-outlined text-[24px]"
                                          :class="actionType === 'approved' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                          style="font-variation-settings:'FILL' 1">
                                        {{ actionType === 'approved' ? 'check_circle' : 'cancel' }}
                                    </span>
                                </div>
                            </div>
                            <h3 class="text-[17px] font-bold text-on-surface text-center">
                                {{ actionType === 'approved' ? 'Approve Leave Request' : 'Reject Leave Request' }}
                            </h3>
                            <p class="text-[13px] text-on-surface-variant text-center mt-1 mb-5">
                                {{ lr?.employee?.name ?? 'Employee' }} — {{ lr?.type_label }}, {{ lr?.duration_days }} days
                            </p>

                            <label class="block text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1.5">
                                Comment <span class="normal-case font-medium text-on-surface-variant/40">(optional)</span>
                            </label>
                            <textarea aria-label="Comment (optional)" v-model="actionComment" rows="3"
                                      class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] resize-none focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10"
                                      :placeholder="actionType === 'approved' ? 'Any notes for the employee...' : 'Reason for rejection...'"></textarea>

                            <div class="mt-5 flex gap-3 justify-end">
                                <button @click="showActionPanel = false"
                                        class="rounded-xl border border-outline-variant px-5 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container">Cancel</button>
                                <button @click="submit" :disabled="actionLoading"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                                        :style="actionType === 'approved' ? 'background:linear-gradient(135deg,#059669,#10b981)' : 'background:linear-gradient(135deg,#dc2626,#ef4444)'">
                                    <svg v-if="actionLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                    </svg>
                                    Confirm
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

    </div>
</template>
