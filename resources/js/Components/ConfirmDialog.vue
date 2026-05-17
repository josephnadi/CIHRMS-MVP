<script setup>
defineProps({
    open:        { type: Boolean, required: true },
    title:       { type: String,  required: true },
    message:     { type: String,  required: true },
    danger:      { type: Boolean, default: false },
    confirmText: { type: String,  default: 'Confirm' },
    loading:     { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-all duration-200 ease-spring"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[300] flex items-center justify-center"
                @click.self="emit('cancel')"
            >
                <!-- Dialog card -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-spring"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition-all duration-150 ease-in"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                    appear
                >
                    <div
                        v-if="open"
                        class="bg-surface-container-lowest rounded-2xl p-6 shadow-lifted-lg w-full max-w-md border border-outline-variant/50 mx-4"
                    >
                        <!-- Icon circle -->
                        <div class="flex justify-center">
                            <div
                                :class="[
                                    'h-12 w-12 rounded-2xl flex items-center justify-center',
                                    danger
                                        ? 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400'
                                        : 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
                                ]"
                            >
                                <span class="material-symbols-outlined text-[24px]">
                                    {{ danger ? 'warning' : 'info' }}
                                </span>
                            </div>
                        </div>

                        <!-- Title -->
                        <h2 class="text-[17px] font-bold text-on-surface mt-4 text-center">
                            {{ title }}
                        </h2>

                        <!-- Message -->
                        <p class="text-[13px] text-on-surface-variant mt-2 text-center leading-relaxed">
                            {{ message }}
                        </p>

                        <!-- Buttons -->
                        <div class="mt-6 flex gap-3 justify-center">
                            <!-- Cancel -->
                            <button
                                :disabled="loading"
                                class="rounded-xl border border-outline-variant px-5 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                @click="emit('cancel')"
                            >
                                Cancel
                            </button>

                            <!-- Confirm -->
                            <button
                                :disabled="loading"
                                :class="[
                                    'btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60 disabled:cursor-not-allowed',
                                    danger
                                        ? ''
                                        : '',
                                ]"
                                :style="danger
                                    ? 'background: linear-gradient(135deg, #dc2626, #ef4444)'
                                    : 'background: linear-gradient(135deg, #1a237e, #3949ab)'"
                                @click="emit('confirm')"
                            >
                                <!-- Loading spinner -->
                                <svg
                                    v-if="loading"
                                    class="h-4 w-4 animate-spin"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                </svg>
                                {{ confirmText }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
