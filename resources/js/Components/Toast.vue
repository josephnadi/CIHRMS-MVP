<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

const page = usePage();
const { toasts: toastList, success, error, info, warning, dismiss } = useToast();

// Bridge Inertia flash messages into the singleton queue. Flash bag
// accepts: success | error | info | warning.
//
// Why not a `watch(page.props.flash, …, { deep, immediate })`:
// The previous implementation used that pattern and produced two
// regressions —
//   1. Duplicate toasts on the same action (immediate + post-mount
//      reactivity both fired the same payload).
//   2. The toast re-appeared every time the user tabbed away and back,
//      because Inertia re-emits the same `flash` object during partial
//      reloads / visibility-triggered refreshes, which the deep-watch
//      treated as a brand-new change.
//
// Fix: listen to discrete Inertia router events instead of reactive
// props, and dedupe by the flash object reference. A WeakSet of
// already-consumed references ensures the same payload can't fire
// twice no matter how often Inertia re-hydrates it.
const consumedFlashes = new WeakSet();

function bridgeFlash(flash) {
    if (! flash || typeof flash !== 'object') return;
    if (consumedFlashes.has(flash)) return;
    consumedFlashes.add(flash);

    if (flash.success) success(flash.success);
    if (flash.error)   error(flash.error);
    if (flash.info)    info(flash.info);
    if (flash.warning) warning(flash.warning);
}

let removeRouterListener = null;
onMounted(() => {
    // 1. Initial mount — surface any flash on the page that just loaded.
    bridgeFlash(page.props.flash);
    // 2. Subsequent Inertia requests — bridge exactly once per response.
    //    `router.on('success')` fires only on actual navigations, not on
    //    arbitrary prop reactivity, so tabbing away no longer triggers it.
    removeRouterListener = router.on('success', (event) => {
        bridgeFlash(event.detail.page.props.flash);
    });
});

onBeforeUnmount(() => {
    if (removeRouterListener) removeRouterListener();
});

// Per-type visual signature. Each variant pairs a palette-correct tile with
// its own icon — the matching sound preset is fired in useToast.push().
const VARIANTS = {
    success: {
        icon:  'check_circle',
        tile:  'bg-green-50 border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800/40 dark:text-green-300',
        accent:'text-green-600 dark:text-green-400',
    },
    error: {
        icon:  'error',
        tile:  'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800/40 dark:text-red-300',
        accent:'text-red-600 dark:text-red-400',
    },
    info: {
        icon:  'campaign',
        // Cyan = brand "live / informational" accent (12d9e3 family)
        tile:  'bg-cyan-50 border-cyan-200 text-cyan-900 dark:bg-cyan-900/20 dark:border-cyan-800/40 dark:text-cyan-200',
        accent:'text-cyan-600 dark:text-cyan-400',
    },
    warning: {
        icon:  'warning',
        tile:  'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-900/20 dark:border-amber-800/40 dark:text-amber-200',
        accent:'text-amber-600 dark:text-amber-400',
    },
};
const variantFor = (t) => VARIANTS[t] ?? VARIANTS.info;
</script>

<template>
    <Teleport to="body">
        <div class="fixed bottom-6 right-6 z-[9999] flex flex-col gap-2 pointer-events-none">
            <TransitionGroup
                tag="div"
                class="flex flex-col gap-2"
                enter-active-class="transition-all duration-300 ease-spring"
                leave-active-class="transition-all duration-300 ease-out"
                enter-from-class="translate-y-4 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-4 opacity-0"
            >
                <div
                    v-for="toast in toastList"
                    :key="toast.id"
                    :class="[
                        'pointer-events-auto flex items-start gap-3 rounded-2xl border px-4 py-3 shadow-lifted min-w-[280px] max-w-sm',
                        variantFor(toast.type).tile,
                    ]"
                    role="status"
                    aria-live="polite"
                >
                    <!-- Icon -->
                    <span
                        :class="['material-symbols-outlined text-[20px] flex-shrink-0 mt-0.5', variantFor(toast.type).accent]"
                        style="font-variation-settings:'FILL' 1"
                    >{{ variantFor(toast.type).icon }}</span>

                    <!-- Message -->
                    <p class="flex-1 text-[13px] font-semibold leading-snug">
                        {{ toast.message }}
                    </p>

                    <!-- Dismiss -->
                    <button
                        class="flex-shrink-0 rounded-lg p-0.5 opacity-60 hover:opacity-100 transition-opacity"
                        aria-label="Dismiss"
                        @click="dismiss(toast.id)"
                    >
                        <span class="material-symbols-outlined text-[16px]">close</span>
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
