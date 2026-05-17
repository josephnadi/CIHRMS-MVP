import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * useLiveData — periodic Inertia partial-reload with sync state.
 *
 *   const { isSyncing, lastSync, syncAgo, refresh } = useLiveData({
 *     only:        ['stats', 'chartData'],
 *     intervalMs:  20000,
 *     jitter:      true,          // randomise between interval and interval*1.5
 *     onlyVisible: true,          // skip when tab hidden
 *   });
 */
export function useLiveData(opts = {}) {
    const {
        only        = [],
        intervalMs  = 20000,
        jitter      = true,
        onlyVisible = true,
        onSync      = null,
    } = opts;

    const isSyncing = ref(false);
    const lastSync  = ref(Date.now());
    const syncAgo   = ref('just now');

    let timer = null;
    let ago   = null;

    const nextDelay = () => jitter
        ? intervalMs + Math.floor(Math.random() * intervalMs * 0.5)
        : intervalMs;

    const refresh = () => {
        if (onlyVisible && document.hidden) return;
        isSyncing.value = true;
        router.reload({
            only,
            preserveScroll: true,
            preserveState:  true,
            onFinish: () => {
                isSyncing.value = false;
                lastSync.value  = Date.now();
                if (typeof onSync === 'function') onSync();
            },
        });
    };

    const schedule = () => {
        clear();
        timer = setTimeout(() => {
            refresh();
            schedule();
        }, nextDelay());
    };
    const clear = () => {
        if (timer) { clearTimeout(timer); timer = null; }
    };

    const updateAgo = () => {
        const s = Math.max(1, Math.round((Date.now() - lastSync.value) / 1000));
        if (s < 60)        syncAgo.value = `${s}s ago`;
        else if (s < 3600) syncAgo.value = `${Math.round(s / 60)}m ago`;
        else               syncAgo.value = `${Math.round(s / 3600)}h ago`;
    };

    const onVisibility = () => {
        if (document.hidden) {
            clear();
        } else {
            refresh();
            schedule();
        }
    };

    onMounted(() => {
        schedule();
        updateAgo();
        ago = setInterval(updateAgo, 5000);
        if (onlyVisible) document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        clear();
        if (ago) clearInterval(ago);
        if (onlyVisible) document.removeEventListener('visibilitychange', onVisibility);
    });

    watch(lastSync, updateAgo);

    return { isSyncing, lastSync, syncAgo, refresh };
}
