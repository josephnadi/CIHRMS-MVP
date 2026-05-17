<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePwa } from '@/composables/usePwa';
import { size as offlineQueueSize, flush as flushOfflineQueue } from '@/composables/useOfflineQueue';

const { canInstall, install, updateReady, applyUpdate, isStandalone } = usePwa();

// â”€â”€ Install banner state (dismissable, persists across reloads) â”€â”€â”€â”€â”€â”€
const STORAGE_KEY = 'cihrms.pwa.install.dismissedAt';
const dismissed = ref(false);
const showInstall = computed(() =>
    canInstall.value && !dismissed.value && !isStandalone.value
);

const dismiss = () => {
    dismissed.value = true;
    try { localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch {}
};

const onInstall = async () => {
    const outcome = await install();
    if (outcome === 'dismissed') dismiss();
};

// â”€â”€ Offline status + queue badge â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const online = ref(navigator.onLine);
const queuedCount = ref(0);

const refreshQueue = async () => { queuedCount.value = await offlineQueueSize(); };
const goOnline   = () => { online.value = true;  refreshQueue().then(() => flushOfflineQueue().then(refreshQueue)); };
const goOffline  = () => { online.value = false; };
const onSyncPing = () => { flushOfflineQueue().then(refreshQueue); };

onMounted(async () => {
    try {
        const t = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
        // Re-show the prompt 14 days after a previous dismissal.
        if (t && (Date.now() - t) < 14 * 24 * 60 * 60 * 1000) dismissed.value = true;
    } catch {}

    window.addEventListener('online',  goOnline);
    window.addEventListener('offline', goOffline);
    window.addEventListener('cihrms:sync-punches', onSyncPing);

    await refreshQueue();
});

onBeforeUnmount(() => {
    window.removeEventListener('online',  goOnline);
    window.removeEventListener('offline', goOffline);
    window.removeEventListener('cihrms:sync-punches', onSyncPing);
});
</script>

<template>
    <!-- â”€â”€ Install banner â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div v-if="showInstall" class="pwa-banner pwa-banner-install" role="status" aria-live="polite">
        <span class="material-symbols-outlined pwa-icon">install_mobile</span>
        <div class="pwa-body">
            <p class="pwa-title">Install CIHRMS</p>
            <p class="pwa-desc">Add to your home screen for offline access to payslips, leave, and clock-in.</p>
        </div>
        <div class="pwa-actions">
            <button type="button" class="pwa-btn pwa-btn-primary" @click="onInstall">Install</button>
            <button type="button" class="pwa-btn pwa-btn-ghost"   @click="dismiss" aria-label="Dismiss install prompt">Later</button>
        </div>
    </div>

    <!-- â”€â”€ Update-available toast â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div v-if="updateReady" class="pwa-banner pwa-banner-update" role="alert">
        <span class="material-symbols-outlined pwa-icon">system_update</span>
        <div class="pwa-body">
            <p class="pwa-title">New version available</p>
            <p class="pwa-desc">Reload to pick up the latest CIHRMS features and fixes.</p>
        </div>
        <button type="button" class="pwa-btn pwa-btn-primary" @click="applyUpdate">Reload</button>
    </div>

    <!-- â”€â”€ Offline / queued-sync indicator â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div v-if="!online" class="pwa-banner pwa-banner-offline" role="status" aria-live="polite">
        <span class="pwa-pulse" aria-hidden="true"></span>
        <div class="pwa-body">
            <p class="pwa-title">You're offline</p>
            <p class="pwa-desc">
                <template v-if="queuedCount > 0">
                    {{ queuedCount }} action{{ queuedCount === 1 ? '' : 's' }} will sync when you reconnect.
                </template>
                <template v-else>
                    Your work is saved locally until your connection returns.
                </template>
            </p>
        </div>
    </div>
</template>

<style scoped>
.pwa-banner {
    position: fixed;
    bottom: 1rem;
    left: 50%;
    transform: translateX(-50%);
    width: calc(100% - 2rem);
    max-width: 480px;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.9rem 1rem;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 8px 28px rgba(10,31,92,0.18), 0 1px 0 rgba(10,31,92,0.04);
    border: 1px solid rgba(10,31,92,0.10);
    z-index: 9000;
    font-family: 'Open Sans', system-ui, sans-serif;
    animation: pwa-slide-up 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}
.pwa-banner-update  { border-color: rgba(217, 119, 6, 0.32); background: #fffaf0; }
.pwa-banner-offline { border-color: rgba(217, 119, 6, 0.32); }
.pwa-icon {
    color: #1a237e;
    font-size: 24px;
    flex-shrink: 0;
    font-variation-settings: 'FILL' 1;
}
.pwa-banner-offline .pwa-icon { display: none; }
.pwa-body { flex: 1; min-width: 0; }
.pwa-title { margin: 0; font-size: 13px; font-weight: 700; color: #0d1452; }
.pwa-desc  { margin: 2px 0 0; font-size: 12px; color: #475569; line-height: 1.4; }
.pwa-actions { display: flex; gap: 0.4rem; flex-shrink: 0; }
.pwa-btn {
    border: none;
    border-radius: 8px;
    padding: 0.55rem 0.95rem;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, transform 0.15s;
}
.pwa-btn-primary { background: #0d1452; color: #fff; }
.pwa-btn-primary:hover { background: #1a237e; transform: translateY(-1px); }
.pwa-btn-ghost   { background: transparent; color: #475569; }
.pwa-btn-ghost:hover { color: #0d1452; }
.pwa-pulse {
    width: 10px; height: 10px; border-radius: 50%;
    background: #d97706;
    flex-shrink: 0;
    animation: pwa-pulse 1.8s ease-in-out infinite;
}
@keyframes pwa-pulse {
    0%, 100% { opacity: 1;   transform: scale(1); }
    50%      { opacity: 0.3; transform: scale(0.7); }
}
@keyframes pwa-slide-up {
    from { opacity: 0; transform: translate(-50%, 16px); }
    to   { opacity: 1; transform: translate(-50%, 0); }
}
</style>
