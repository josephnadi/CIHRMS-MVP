import { ref, onMounted, onBeforeUnmount } from 'vue';

/**
 * PWA composable — wraps the install prompt + service-worker update lifecycle.
 *
 *   const { canInstall, install, updateReady, applyUpdate, isStandalone } = usePwa();
 *
 *   - `canInstall`   becomes true when the browser fires `beforeinstallprompt`.
 *   - `install()`    triggers the native prompt; returns 'accepted' or 'dismissed'.
 *   - `updateReady`  becomes true when a newer service worker has installed.
 *   - `applyUpdate()` tells the waiting SW to skipWaiting + reloads the page.
 *   - `isStandalone` true when running as an installed PWA (vs. browser tab).
 */
export function usePwa() {
    const canInstall   = ref(false);
    const updateReady  = ref(false);
    const isStandalone = ref(false);
    let   deferredPrompt = null;
    let   waitingWorker  = null;

    const onBeforeInstall = (e) => {
        e.preventDefault();
        deferredPrompt = e;
        canInstall.value = true;
    };

    const onAppInstalled = () => {
        canInstall.value = false;
        deferredPrompt = null;
    };

    const onControllerChange = () => {
        // The SW that controls the page changed — reload to pick up new chunks.
        window.location.reload();
    };

    const onMessage = (event) => {
        if (event.data?.type === 'CIHRMS_SYNC_PUNCHES') {
            // Background-sync ping — page-side queue replays.
            window.dispatchEvent(new CustomEvent('cihrms:sync-punches'));
        }
    };

    onMounted(async () => {
        isStandalone.value = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        window.addEventListener('beforeinstallprompt', onBeforeInstall);
        window.addEventListener('appinstalled', onAppInstalled);

        if ('serviceWorker' in navigator) {
            try {
                const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });

                // Listen for an updated worker waiting to activate.
                if (reg.waiting) {
                    waitingWorker = reg.waiting;
                    updateReady.value = true;
                }

                reg.addEventListener('updatefound', () => {
                    const newSW = reg.installing;
                    if (!newSW) return;
                    newSW.addEventListener('statechange', () => {
                        if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
                            waitingWorker = newSW;
                            updateReady.value = true;
                        }
                    });
                });

                navigator.serviceWorker.addEventListener('controllerchange', onControllerChange);
                navigator.serviceWorker.addEventListener('message', onMessage);
            } catch (e) {
                // SW registration failed — likely on http://. Non-fatal.
                console.warn('[pwa] sw registration failed', e);
            }
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeinstallprompt', onBeforeInstall);
        window.removeEventListener('appinstalled', onAppInstalled);
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange);
            navigator.serviceWorker.removeEventListener('message', onMessage);
        }
    });

    const install = async () => {
        if (!deferredPrompt) return 'unavailable';
        deferredPrompt.prompt();
        const choice = await deferredPrompt.userChoice;
        deferredPrompt = null;
        canInstall.value = false;
        return choice.outcome; // 'accepted' | 'dismissed'
    };

    const applyUpdate = () => {
        if (waitingWorker) {
            waitingWorker.postMessage({ type: 'SKIP_WAITING' });
            // controllerchange listener will reload the page.
        }
    };

    return { canInstall, install, updateReady, applyUpdate, isStandalone };
}
