<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useSound } from '@/composables/useSound';

const page  = usePage();
const items = computed(() => page.props.announcementTicker ?? []);
const sfx   = useSound();
let knownIds = new Set();

// ── State ─────────────────────────────────────────────────────────
const paused       = ref(false);
const dismissed    = ref(false);
const refreshing   = ref(false);
const lastSyncedAt = ref(new Date());
const pulse        = ref(0); // bumps when items change â†’ triggers content fade

// ── Polling: every 60s, partial-reload only the ticker payload ────
const POLL_MS = 60_000;
let pollHandle = null;

const refreshNow = () => {
    if (document.hidden) return; // skip when tab is backgrounded
    refreshing.value = true;
    router.reload({
        only: ['announcementTicker', 'notifications', 'notificationCount'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            refreshing.value = false;
            lastSyncedAt.value = new Date();
        },
    });
};

const startPolling = () => {
    stopPolling();
    pollHandle = setInterval(refreshNow, POLL_MS);
};
const stopPolling = () => {
    if (pollHandle) {
        clearInterval(pollHandle);
        pollHandle = null;
    }
};

const handleVisibility = () => {
    if (document.hidden) {
        stopPolling();
    } else {
        refreshNow();
        startPolling();
    }
};

onMounted(() => {
    startPolling();
    document.addEventListener('visibilitychange', handleVisibility);
});
onBeforeUnmount(() => {
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibility);
});

// Bump pulse counter + chime when the item set changes
watch(
    () => items.value.map(i => i.id).join('|'),
    () => {
        pulse.value++;

        // Detect genuinely new items (skip first paint so we don't chime on cold load)
        if (knownIds.size > 0) {
            const fresh = items.value.filter(i => !knownIds.has(i.id));
            if (fresh.length > 0) {
                const first = fresh[0];
                const sound = first.type === 'birthday' ? 'announcement'
                            : first.type === 'task'     ? 'assigned.you'
                            : first.type === 'event'    ? 'event.created'
                            : first.severity === 'urgent' ? 'warning'
                            : 'announcement';
                sfx.play(sound);
            }
        }
        knownIds = new Set(items.value.map(i => i.id));
    },
);
onMounted(() => { knownIds = new Set(items.value.map(i => i.id)); });

// ── Visual helpers ────────────────────────────────────────────────
const speedSec = computed(() => Math.max(32, items.value.length * 7));

// Friendly "synced 2m ago" label
const syncedAgo = ref('just now');
let agoHandle = null;
const refreshAgo = () => {
    const secs = Math.max(1, Math.round((Date.now() - lastSyncedAt.value.getTime()) / 1000));
    if (secs < 60)        syncedAgo.value = `synced ${secs}s ago`;
    else if (secs < 3600) syncedAgo.value = `synced ${Math.round(secs / 60)}m ago`;
    else                  syncedAgo.value = `synced ${Math.round(secs / 3600)}h ago`;
};
onMounted(() => {
    refreshAgo();
    agoHandle = setInterval(refreshAgo, 10_000);
});
onBeforeUnmount(() => { if (agoHandle) clearInterval(agoHandle); });
watch(lastSyncedAt, refreshAgo);

const severityClass = (sev) => ({
    info:      'text-secondary',
    important: 'text-brand-gold-deep',
    urgent:    'text-brand-magenta',
}[sev] || 'text-on-surface-variant');

const typeAccent = (type) => ({
    notice:   'before:bg-secondary',
    event:    'before:bg-brand-cyan',
    birthday: 'before:bg-brand-magenta',
    task:     'before:bg-brand-gold',
    system:   'before:bg-brand-blue-bright',
}[type] || 'before:bg-on-surface-variant/60');
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0 -translate-y-2"
    >
        <div v-if="items.length && !dismissed"
             class="tk-shell"
             role="status"
             aria-live="polite"
             aria-label="Organisation notices"
             @mouseenter="paused = true"
             @mouseleave="paused = false">

            <!-- Leading label chip · navy with pulsing gold "live" dot -->
            <div class="tk-label">
                <span class="tk-live" aria-hidden="true">
                    <span class="tk-live-dot" :class="{ 'tk-live-dot--spin': refreshing }"></span>
                    <span class="tk-live-ring"></span>
                </span>
                <span class="material-symbols-outlined tk-label-icon" style="font-variation-settings:'FILL' 1">campaign</span>
                <span class="tk-label-text">Notice board</span>
                <span class="tk-label-count">{{ items.length }}</span>
            </div>

            <!-- Marquee viewport -->
            <div class="tk-viewport">
                <Transition name="tk-cross" mode="out-in">
                    <div class="tk-rail" :key="pulse">
                        <!-- Track 1 -->
                        <div class="tk-track" :class="{ 'tk-paused': paused }"
                             :style="`--tk-duration:${speedSec}s`">
                            <template v-for="item in items" :key="`a-${item.id}`">
                                <component :is="item.link_url ? 'a' : 'span'"
                                           :href="item.link_url ?? undefined"
                                           class="tk-item"
                                           :class="[typeAccent(item.type), `tk-${item.severity}`]">
                                    <span class="material-symbols-outlined tk-item-icon"
                                          :class="severityClass(item.severity)"
                                          style="font-variation-settings:'FILL' 1">{{ item.icon }}</span>
                                    <span class="tk-item-title">{{ item.title }}</span>
                                    <span v-if="item.pinned" class="material-symbols-outlined tk-pin" aria-label="Pinned">push_pin</span>
                                </component>
                            </template>
                        </div>
                        <!-- Track 2 (clone for seamless loop) -->
                        <div class="tk-track" :class="{ 'tk-paused': paused }"
                             :style="`--tk-duration:${speedSec}s`"
                             aria-hidden="true">
                            <template v-for="item in items" :key="`b-${item.id}`">
                                <component :is="item.link_url ? 'a' : 'span'"
                                           :href="item.link_url ?? undefined"
                                           class="tk-item"
                                           :class="[typeAccent(item.type), `tk-${item.severity}`]">
                                    <span class="material-symbols-outlined tk-item-icon"
                                          :class="severityClass(item.severity)"
                                          style="font-variation-settings:'FILL' 1">{{ item.icon }}</span>
                                    <span class="tk-item-title">{{ item.title }}</span>
                                    <span v-if="item.pinned" class="material-symbols-outlined tk-pin" aria-label="Pinned">push_pin</span>
                                </component>
                            </template>
                        </div>
                    </div>
                </Transition>

                <!-- Edge fades -->
                <div class="tk-fade tk-fade--left" aria-hidden="true"></div>
                <div class="tk-fade tk-fade--right" aria-hidden="true"></div>
            </div>

            <!-- Controls -->
            <div class="tk-ctl">
                <span class="tk-synced" :title="lastSyncedAt.toLocaleTimeString()">{{ syncedAgo }}</span>
                <button type="button" class="tk-btn"
                        :title="paused ? 'Resume' : 'Pause'"
                        :aria-label="paused ? 'Resume scrolling' : 'Pause scrolling'"
                        @click="paused = !paused">
                    <span class="material-symbols-outlined">{{ paused ? 'play_arrow' : 'pause' }}</span>
                </button>
                <button type="button" class="tk-btn"
                        title="Refresh now"
                        aria-label="Refresh notices"
                        @click="refreshNow">
                    <span class="material-symbols-outlined" :class="{ 'tk-spin': refreshing }">refresh</span>
                </button>
                <button type="button" class="tk-btn tk-btn--close"
                        title="Dismiss"
                        aria-label="Hide notice ticker"
                        @click="dismissed = true">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
/* ─── Shell ────────────────────────────────────────────────────────── */
.tk-shell {
    position: relative;
    display: flex;
    align-items: stretch;
    height: 38px;
    width: 100%;
    overflow: hidden;
    background:
        linear-gradient(180deg, rgba(255,255,255,0.04), transparent 60%),
        linear-gradient(90deg, #1a237e 0%, #283593 55%, #3949ab 100%);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    box-shadow: 0 1px 0 rgba(0,0,0,0.18), 0 4px 18px -8px rgba(13, 20, 82,0.55);
    color: #e8eef7;
    font-family: 'Open Sans', system-ui, sans-serif;
    isolation: isolate;
    z-index: 30;
}
/* Faint reading-grid texture */
.tk-shell::before {
    content: '';
    position: absolute; inset: 0;
    background-image: repeating-linear-gradient(90deg, rgba(255,255,255,0.022) 0 1px, transparent 1px 80px);
    pointer-events: none;
    z-index: 0;
}

/* ─── Leading label · navy chip with gold "live" pulse ─────────────── */
.tk-label {
    position: relative;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 0 16px 0 14px;
    background: linear-gradient(135deg, rgba(255,215,0,0.12), rgba(255,215,0,0.02));
    border-right: 1px solid rgba(255,215,0,0.18);
    color: #ffd700;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    flex-shrink: 0;
    z-index: 2;
}
.tk-label-icon  { font-size: 17px; line-height: 1; opacity: 0.9; }
.tk-label-text  { line-height: 1; }
.tk-label-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 16px;
    padding: 0 5px;
    background: #ffd700;
    color: #070b3a;
    border-radius: 99px;
    font-size: 9.5px;
    font-weight: 900;
    letter-spacing: 0;
    box-shadow: 0 0 12px rgba(255,215,0,0.32);
}

/* Live dot pulse */
.tk-live {
    position: relative;
    width: 10px; height: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.tk-live-dot {
    position: relative;
    width: 6px; height: 6px;
    background: #ffd700;
    border-radius: 99px;
    box-shadow: 0 0 10px rgba(255,215,0,0.7);
    z-index: 1;
}
.tk-live-dot--spin {
    background: #12d9e3;
    box-shadow: 0 0 12px rgba(18,217,227,0.85);
}
.tk-live-ring {
    position: absolute;
    inset: 0;
    border-radius: 99px;
    border: 1.5px solid rgba(255,215,0,0.5);
    animation: tk-pulse 2s cubic-bezier(0.22, 1, 0.36, 1) infinite;
}
@keyframes tk-pulse {
    0%   { transform: scale(0.5); opacity: 0.9; }
    80%  { transform: scale(2.2); opacity: 0; }
    100% { transform: scale(2.2); opacity: 0; }
}

/* ─── Viewport + marquee ──────────────────────────────────────────── */
.tk-viewport {
    position: relative;
    flex: 1;
    overflow: hidden;
}
.tk-rail {
    display: flex;
    height: 100%;
    width: 100%;
}
.tk-track {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    gap: 0;
    white-space: nowrap;
    padding: 0 18px;
    will-change: transform;
    animation: tk-scroll var(--tk-duration, 36s) linear infinite;
}
.tk-paused { animation-play-state: paused; }
.tk-viewport:hover .tk-track { animation-play-state: paused; }

@keyframes tk-scroll {
    from { transform: translate3d(0, 0, 0); }
    to   { transform: translate3d(-100%, 0, 0); }
}

/* Cross-fade when items change (refresh tick) */
.tk-cross-enter-active,
.tk-cross-leave-active { transition: opacity 0.32s ease; }
.tk-cross-enter-from,
.tk-cross-leave-to     { opacity: 0; }

/* ─── Item ───────────────────────────────────────────────────────── */
.tk-item {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 0 18px 0 14px;
    margin-right: 6px;
    height: 28px;
    border-radius: 8px;
    color: rgba(255,255,255,0.88);
    font-size: 12.5px;
    font-weight: 500;
    letter-spacing: -0.005em;
    text-decoration: none;
    transition: color 0.15s ease, background 0.18s ease;
}
.tk-item::before {
    content: '';
    position: absolute;
    left: 6px; top: 7px; bottom: 7px;
    width: 2px;
    border-radius: 2px;
}
.tk-item:hover {
    color: #ffffff;
    background: rgba(255,255,255,0.06);
}
.tk-item-icon { font-size: 16px; line-height: 1; flex-shrink: 0; }
.tk-item-title { line-height: 1; padding-top: 1px; }

/* Severity-keyed left-rail intensity */
.tk-item.tk-urgent    { color: #ffe2ec; }
.tk-item.tk-urgent::before    { background: #d912e3; box-shadow: 0 0 8px rgba(217,18,227,0.65); }
.tk-item.tk-important { color: #fff5d6; }
.tk-item.tk-important::before { background: #ffd700; }

/* Pin indicator */
.tk-pin {
    font-size: 13px;
    margin-left: 2px;
    color: #ffd700;
    transform: rotate(35deg);
}

/* Edge fades */
.tk-fade {
    position: absolute;
    top: 0; bottom: 0;
    width: 56px;
    pointer-events: none;
    z-index: 2;
}
.tk-fade--left  { left: 0;  background: linear-gradient(90deg,  #1a237e, transparent); }
.tk-fade--right { right: 0; background: linear-gradient(270deg, #3949ab, transparent); }

/* ─── Controls ───────────────────────────────────────────────────── */
.tk-ctl {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 0 6px 0 10px;
    border-left: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
    z-index: 2;
}
.tk-synced {
    margin-right: 6px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.42);
    white-space: nowrap;
}
.tk-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 7px;
    color: rgba(255,255,255,0.55);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease;
}
.tk-btn:hover { color: #ffffff; background: rgba(255,255,255,0.08); }
.tk-btn--close:hover { color: #ffe2ec; background: rgba(217,18,227,0.18); }
.tk-btn .material-symbols-outlined { font-size: 16px; }

.tk-spin { animation: tk-rotate 0.9s linear infinite; }
@keyframes tk-rotate {
    to { transform: rotate(360deg); }
}

/* ─── Reduced motion ─────────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .tk-track     { animation: none; }
    .tk-live-ring { animation: none; }
    .tk-spin      { animation: none; }
}

/* ─── Small screens ──────────────────────────────────────────────── */
@media (max-width: 640px) {
    .tk-label-text { display: none; }
    .tk-label      { padding: 0 10px 0 8px; gap: 6px; }
    .tk-synced     { display: none; }
    .tk-item       { padding: 0 12px 0 10px; font-size: 12px; }
}
</style>
