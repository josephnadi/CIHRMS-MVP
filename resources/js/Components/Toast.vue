<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

const page = usePage();
const { toasts: toastList, success, error, dismiss } = useToast();

// Bridge Inertia flash messages into the singleton queue.
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) success(flash.success);
        if (flash.error)   error(flash.error);
    },
    { deep: true, immediate: true }
);
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
                        toast.type === 'success'
                            ? 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800/40 text-green-800 dark:text-green-300'
                            : 'bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-800/40 text-red-800 dark:text-red-300',
                    ]"
                >
                    <!-- Icon -->
                    <span class="material-symbols-outlined text-[20px] flex-shrink-0 mt-0.5">
                        {{ toast.type === 'success' ? 'check_circle' : 'error' }}
                    </span>

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
