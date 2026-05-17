<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState         from '@/Components/EmptyState.vue';
import Pagination         from '@/Components/Pagination.vue';

const props = defineProps({
    notifications: Object,
    activeModule:  String,
});

const items = computed(() => props.notifications?.data ?? []);

const filterMode = ref('all');

const visible = computed(() => {
    if (filterMode.value === 'unread') return items.value.filter(n => !n.read_at);
    if (filterMode.value === 'read')   return items.value.filter(n => !!n.read_at);
    return items.value;
});

const unreadCount = computed(() => items.value.filter(n => !n.read_at).length);

const thisWeekCount = computed(() => {
    const weekAgo = Date.now() - 7 * 86_400_000;
    return items.value.filter(n => n.created_at && new Date(n.created_at).getTime() >= weekAgo).length;
});

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

// Notification type palette — disciplined Sovereign Precision.
// LeaveStatusUpdated: was cobalt color but violet hue tint (mismatch bug).
// TicketCreated: was a generic cyan-blue; pulled to brand cyan #12d9e3.
// PaymentMarkedPaid: switched amber -> green (semantic — payment success).
const TYPE_META = {
    LeaveRequested:       { color: '#1a237e', icon: 'event_note',      tag: 'Leave',    hue: '26, 35, 126'   },
    LeaveStatusUpdated:   { color: '#059669', icon: 'event_available', tag: 'Leave',    hue: '5,150,105'   },
    TicketCreated:        { color: '#0e8a93', icon: 'support_agent',   tag: 'Service',  hue: '18,217,227'  },
    EmployeeCreated:      { color: '#d912e3', icon: 'person_add',      tag: 'Employee', hue: '217,18,227'  },
    PaymentMarkedPaid:    { color: '#059669', icon: 'payments',        tag: 'Payroll',  hue: '5,150,105'   },
    DatabaseNotification: { color: '#64748b', icon: 'notifications',   tag: 'System',   hue: '100,116,139' },
};

function metaFor(type) {
    return TYPE_META[type] ?? TYPE_META.DatabaseNotification;
}

function timeFromIso(d) {
    if (!d) return '';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
    return new Date(d).toLocaleDateString('en-GH', { day: '2-digit', month: 'short' });
}

function markAllRead() {
    router.post(route('notifications.readAll'), {}, { preserveScroll: true });
}

const FILTERS = [
    { value: 'all',    label: 'All' },
    { value: 'unread', label: 'Unread' },
    { value: 'read',   label: 'Read' },
];
</script>

<template>
    <Head title="Notifications â€” CIHRMS" />

    <AuthenticatedLayout :activeModule="activeModule">

        <!-- Editorial Sovereign · Masthead -->
        <div class="es-masthead">
            <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">NOTICES</span></span>
            <span class="es-masthead-spacer"></span>
            <span>{{ editionLabel.date }}</span>
            <span class="es-masthead-spacer"></span>
            <span>{{ editionLabel.edition }}</span>
            <span class="es-masthead-spacer"></span>
            <span class="es-masthead-live">
                <span class="es-dot" aria-hidden="true"></span>
                {{ unreadCount ? `Live · ${unreadCount} unread` : 'Live · Inbox clear' }}
            </span>
        </div>

        <!-- Broadsheet hero -->
        <div class="es-broadsheet rounded-none">
            <!-- LEAD column -->
            <div class="es-broadsheet-lead">
                <p class="es-eyebrow mb-6">Inbound institutional notices</p>
                <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                    Notices,
                    <span class="es-display-italic">delivered.</span>
                </h2>
                <p class="es-display-sub">
                    System events, approval activity and mentions routed to your desk by the Registrar's office —
                    leave dispositions, service-desk dispatches, payroll confirmations and personnel actions, in
                    order of arrival.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                    <button v-if="unreadCount" @click="markAllRead" class="es-chip">
                        <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">done_all</span>
                        Mark all read
                    </button>
                    <span v-if="unreadCount" class="text-on-surface-variant/30">·</span>
                    <button @click="router.visit(route('notifications.channels'))" class="es-chip">
                        <span class="material-symbols-outlined text-[15px]">tune</span>
                        Channel preferences
                    </button>
                </div>
            </div>

            <!-- SIDEBAR column: feature KPI -->
            <div class="es-broadsheet-sidebar">
                <div class="es-stat-hero">
                    <p class="es-stat-hero-label">Unread Notices</p>
                    <p class="es-stat-hero-value">{{ unreadCount.toLocaleString() }}</p>
                    <p class="es-stat-hero-caption">
                        Awaiting your attention · of {{ items.length.toLocaleString() }} on file
                    </p>
                    <span class="es-stat-hero-delta">
                        <span class="material-symbols-outlined text-[13px]">{{ unreadCount ? 'mark_email_unread' : 'mark_email_read' }}</span>
                        {{ unreadCount ? 'Outstanding correspondence' : 'Desk is clear' }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Supporting metrics strip -->
        <div class="es-stat-strip rounded-none mb-6">
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">On File</p>
                <p class="es-stat-cell-value">{{ items.length.toLocaleString() }}</p>
                <p class="es-stat-cell-caption">Notices in this dispatch</p>
            </div>
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">Unread</p>
                <p class="es-stat-cell-value">{{ unreadCount.toLocaleString() }}</p>
                <p class="es-stat-cell-caption">Pending acknowledgement</p>
            </div>
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">This Week</p>
                <p class="es-stat-cell-value">{{ thisWeekCount.toLocaleString() }}</p>
                <p class="es-stat-cell-caption">Filed in last 7 days</p>
            </div>
        </div>

        <!-- Filter pill bar -->
        <div class="mb-5 inline-flex items-center gap-1 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-1 shadow-card">
            <button
                v-for="f in FILTERS" :key="f.value"
                @click="filterMode = f.value"
                class="rounded-xl px-4 py-2 text-[12px] font-bold transition-all"
                :class="filterMode === f.value
                    ? 'bg-secondary/10 text-secondary shadow-glow-sm'
                    : 'text-on-surface-variant/70 hover:text-on-surface'"
            >
                {{ f.label }}
                <span v-if="f.value === 'unread' && unreadCount"
                      class="ml-1.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-secondary px-1.5 text-[10px] font-black text-white">
                    {{ unreadCount }}
                </span>
            </button>
        </div>

        <!-- List -->
        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
            <ul v-if="visible.length" class="max-h-[calc(100vh-340px)] min-h-[260px] overflow-y-auto divide-y divide-outline-variant/30">
                <li
                    v-for="(n, idx) in visible"
                    :key="n.id"
                    class="group relative flex gap-4 px-5 py-4 transition-colors cursor-pointer hover:bg-surface-container/40"
                    :style="`animation-delay:${Math.min(idx, 10)*0.04}s`"
                    :class="!n.read_at ? 'bg-secondary/[0.02]' : ''"
                >
                    <!-- Unread dot -->
                    <span
                        v-if="!n.read_at"
                        class="absolute left-1.5 top-1/2 -translate-y-1/2 h-2 w-2 rounded-full bg-secondary shadow-glow-sm"
                    ></span>

                    <!-- Type icon -->
                    <div
                        class="mt-0.5 flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl"
                        :style="`background:rgba(${metaFor(n.type).hue},0.12);border:1px solid rgba(${metaFor(n.type).hue},0.2);`"
                    >
                        <span
                            class="material-symbols-outlined text-[19px]"
                            :style="`color:${metaFor(n.type).color};font-variation-settings:'FILL' 1`"
                        >{{ metaFor(n.type).icon }}</span>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider mb-1"
                                    :style="`background:rgba(${metaFor(n.type).hue},0.12);color:${metaFor(n.type).color}`"
                                >{{ metaFor(n.type).tag }}</span>
                                <p
                                    class="text-[14px] leading-snug"
                                    :class="!n.read_at ? 'font-bold text-on-surface' : 'font-medium text-on-surface/85'"
                                >{{ n.message ?? n.data?.message ?? n.type }}</p>
                            </div>
                            <span class="text-[11px] font-semibold text-on-surface-variant/50 whitespace-nowrap mt-0.5">
                                {{ timeFromIso(n.created_at) }}
                            </span>
                        </div>

                        <!-- Optional rich data preview -->
                        <div v-if="n.data && Object.keys(n.data).length > 1" class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="(val, key) in n.data"
                                :key="key"
                                v-show="key !== 'message' && typeof val !== 'object'"
                                class="rounded-md bg-surface-container px-2 py-0.5 text-[10px] font-bold text-on-surface-variant/70"
                            >
                                {{ key.replace(/_/g, ' ') }}: <span class="text-on-surface">{{ val }}</span>
                            </span>
                        </div>
                    </div>

                    <!-- Right arrow indicator on hover -->
                    <span class="material-symbols-outlined self-center text-[18px] text-on-surface-variant/30 opacity-0 -translate-x-1 transition-all duration-200 group-hover:opacity-100 group-hover:translate-x-0 group-hover:text-secondary/70" aria-hidden="true">
                        chevron_right
                    </span>
                </li>
            </ul>

            <EmptyState
                v-else
                title="No notifications"
                :description="filterMode === 'unread' ? 'You\'re all caught up.' : 'Nothing here yet â€” you\'ll see new system activity as it happens.'"
                icon="notifications_off"
            />

            <!-- Pagination -->
            <div v-if="notifications?.links?.length > 3" class="border-t border-outline-variant/40 px-5 py-3">
                <Pagination :links="notifications.links" :meta="notifications.meta" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
