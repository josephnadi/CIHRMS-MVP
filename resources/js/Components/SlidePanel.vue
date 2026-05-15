<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    open:     { type: Boolean, required: true },
    title:    { type: String,  required: true },
    size:     {
        type:    String,
        default: 'md',
        validator: v => ['sm', 'md', 'lg', 'xl'].includes(v),
    },
    subtitle: { type: String, default: null },
});

const emit = defineEmits(['close']);

const panel = ref(null);

const sizeClasses = {
    sm: 'w-full max-w-sm',
    md: 'w-full max-w-lg',
    lg: 'w-full max-w-2xl',
    xl: 'w-full max-w-5xl',
};

// Focus panel when opened
watch(() => props.open, (val) => {
    if (val) {
        nextTick(() => {
            panel.value?.focus();
        });
    }
});

function handleKey(e) {
    if (e.key === 'Escape' && props.open) emit('close');
}

onMounted(() => document.addEventListener('keydown', handleKey));
onUnmounted(() => document.removeEventListener('keydown', handleKey));
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-300 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200]"
                @click="emit('close')"
            ></div>
        </Transition>

        <!-- Panel -->
        <Transition
            enter-active-class="transition-all duration-300 ease-spring"
            enter-from-class="translate-x-full opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition-all duration-300 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="translate-x-full opacity-0"
        >
            <div
                v-if="open"
                ref="panel"
                tabindex="-1"
                :class="[
                    'fixed inset-y-0 right-0 z-[201] flex flex-col bg-surface-container-lowest shadow-lifted-lg outline-none',
                    sizeClasses[size],
                ]"
            >
                <!-- Header -->
                <div class="flex items-start justify-between px-6 py-5 flex-shrink-0">
                    <div class="flex-1 min-w-0 pr-4">
                        <h2 class="text-[17px] font-bold text-on-surface leading-tight truncate">
                            {{ title }}
                        </h2>
                        <p
                            v-if="subtitle"
                            class="mt-0.5 text-[13px] text-on-surface-variant leading-snug"
                        >
                            {{ subtitle }}
                        </p>
                    </div>
                    <button
                        class="flex-shrink-0 rounded-xl p-2 text-on-surface-variant hover:bg-surface-container transition-colors"
                        aria-label="Close panel"
                        @click="emit('close')"
                    >
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>

                <!-- Divider -->
                <div class="border-t border-outline-variant/50 flex-shrink-0"></div>

                <!-- Scrollable body -->
                <div :class="['flex-1 overflow-y-auto', size === 'xl' ? 'p-0' : 'p-6']">
                    <slot />
                </div>

                <!-- Footer -->
                <div v-if="$slots.footer" class="border-t border-outline-variant/50 px-6 py-4 flex justify-end gap-3 flex-shrink-0">
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
