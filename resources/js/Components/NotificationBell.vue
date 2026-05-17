<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useSound } from '@/composables/useSound';

const page = usePage();
const open = ref(false);
const dropdownRef = ref(null);
const sfx = useSound();

const notifications = computed(() => page.props.notifications ?? []);
const count = computed(() => page.props.notificationCount ?? 0);
const ringing = ref(false);

// Detect new-message arrivals via id-set comparison so silent reloads
// (Inertia partial polls) only chime when something genuinely new appeared.
let knownIds = new Set();
const initIds = () => { knownIds = new Set(notifications.value.map(n => n.id)); };

watch(notifications, (next) => {
    const fresh = next.filter(n => !knownIds.has(n.id));
    if (fresh.length > 0 && knownIds.size > 0) {
        // Prefer explicit `kind` field; fall back to message-keyword matching
        // so older notifications without `kind` still route reasonably.
        const first = fresh[0];
        const kind  = (first?.kind || '').toLowerCase();
        const msg   = (first?.message || '').toLowerCase();
        const sound = kind === 'ticket.completed'              ? 'task.completed'
                    : kind === 'ticket.assigned'               ? 'assigned.you'
                    : kind === 'event.created'                 ? 'event.created'
                    : msg.includes('resolved') || msg.includes('completed') || msg.includes('closed')
                                                               ? 'task.completed'
                    : msg.includes('assign')                   ? 'assigned.you'
                    : msg.includes('event')                    ? 'event.created'
                    :                                            'notification';
        sfx.play(sound);
        ringing.value = true;
        // Hold the ringing class long enough for two full bell swings — the
        // CSS keyframe runs 0.85s, so 1.8s lets it loop twice for emphasis.
        setTimeout(() => { ringing.value = false; }, 1800);
    }
    knownIds = new Set(next.map(n => n.id));
}, { deep: true });

const handleClickOutside = (e) => {
    if (dropdownRef.value && !dropdownRef.value.contains(e.target)) {
        open.value = false;
    }
};

onMounted(() => {
    initIds();
    document.addEventListener('click', handleClickOutside);
});
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside));

const markAllRead = () => {
    router.post(route('notifications.readAll'), {}, {
        preserveScroll: true,
        onSuccess: () => { open.value = false; },
    });
};
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <button
            @click="open = !open"
            class="relative flex h-9 w-9 items-center justify-center rounded-xl text-on-surface-variant transition-all hover:bg-surface-container-low hover:text-on-surface"
            title="Notifications"
        >
            <span class="material-symbols-outlined text-[20px]"
                  :class="[
                      ringing ? 'nb-ring' : '',
                      !ringing && count > 0 ? 'nb-pulse' : '',
                      count > 0 ? 'nb-active' : ''
                  ]"
                  :style="count > 0 ? `font-variation-settings:'FILL' 1;` : ''">notifications</span>
            <!--
                Unread badge stays red by design.
                Red = unread is a universal cross-platform convention (iOS,
                Android, macOS, Slack, Gmail, GitHub). Re-tinting it with the
                brand palette would harm scan-time recognition more than it
                would gain brand cohesion. This intentional override of the
                5% accent rule is documented in docs/QA_REPORT.md §M-3.
            -->
            <span
                v-if="count > 0"
                class="absolute top-0.5 right-0.5 min-w-[16px] h-[16px] px-1 inline-flex items-center justify-center rounded-full bg-red-500 text-white text-[9px] font-bold ring-2 ring-surface-container-lowest nb-badge"
            >{{ count > 99 ? '99+' : count }}</span>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div
                v-if="open"
                class="absolute right-0 mt-2 w-[360px] origin-top-right rounded-2xl bg-surface-container-lowest border border-outline-variant/60 shadow-lifted-lg overflow-hidden z-50"
            >
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-4 py-3">
                    <p class="text-[13px] font-bold text-on-surface">Notifications</p>
                    <button
                        v-if="count > 0"
                        @click="markAllRead"
                        class="text-[11px] font-semibold text-secondary hover:underline"
                    >Mark all read</button>
                </div>

                <div v-if="notifications.length === 0" class="p-8 text-center">
                    <span class="material-symbols-outlined text-[32px] text-on-surface-variant/30">notifications_none</span>
                    <p class="mt-2 text-[12px] text-on-surface-variant">You're all caught up.</p>
                </div>

                <div v-else class="max-h-[400px] overflow-y-auto divide-y divide-outline-variant/30">
                    <div
                        v-for="n in notifications"
                        :key="n.id"
                        class="px-4 py-3 hover:bg-surface-container/50 transition-colors"
                    >
                        <p class="text-[12px] font-medium text-on-surface leading-snug">{{ n.message ?? 'Notification' }}</p>
                        <p class="mt-1 text-[10px] text-on-surface-variant/60">{{ n.time }}</p>
                    </div>
                </div>

                <div class="border-t border-outline-variant/40 px-4 py-2.5">
                    <Link
                        :href="route('notifications.index')"
                        class="block text-center text-[12px] font-semibold text-secondary hover:underline"
                        @click="open = false"
                    >View all notifications</Link>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
/* Full-bodied ring — fires once when a new notification arrives. */
@keyframes nb-ring {
    0%, 100% { transform: rotate(0); }
    10%      { transform: rotate(18deg); }
    20%      { transform: rotate(-15deg); }
    30%      { transform: rotate(14deg); }
    40%      { transform: rotate(-12deg); }
    50%      { transform: rotate(10deg); }
    60%      { transform: rotate(-8deg); }
    70%      { transform: rotate(6deg); }
    80%      { transform: rotate(-4deg); }
    90%      { transform: rotate(2deg); }
}
.nb-ring {
    transform-origin: 50% 18%;
    animation: nb-ring 1.6s cubic-bezier(0.36, 0, 0.66, 1) both;
}

/* Persistent gentle attention pulse — loops every 3s while count > 0
   (only when not actively ringing) so the bell visibly wants attention
   until the user clears their notifications. */
@keyframes nb-pulse {
    0%, 88%, 100% { transform: rotate(0); }
    91%           { transform: rotate(8deg); }
    94%           { transform: rotate(-6deg); }
    97%           { transform: rotate(3deg); }
}
.nb-pulse {
    transform-origin: 50% 18%;
    animation: nb-pulse 3s ease-in-out infinite;
}

/* The bell itself gets a red attention colour while count > 0 so it
   reads as "alive" even before the swing kicks in. */
.nb-active {
    color: #dc2626;
}

/* Badge: pop-in on first appearance + subtle infinite heartbeat. */
@keyframes nb-badge-in {
    0%   { transform: scale(0);    opacity: 0; }
    60%  { transform: scale(1.25); opacity: 1; }
    100% { transform: scale(1);    opacity: 1; }
}
@keyframes nb-badge-heartbeat {
    0%, 70%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.55); }
    85%           { transform: scale(1.12); box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); }
}
.nb-badge {
    animation:
        nb-badge-in 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both,
        nb-badge-heartbeat 2.2s ease-in-out 0.35s infinite;
}

@media (prefers-reduced-motion: reduce) {
    .nb-ring,
    .nb-pulse,
    .nb-badge { animation: none !important; }
}
</style>
