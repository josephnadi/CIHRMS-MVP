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

// Focus panel when opened. Use nextTick so the transform animation has
// time to start; otherwise the focus jumps from the trigger before the
// panel is visually present.
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
        <!--
            Backdrop + Panel are always rendered (no v-if) and toggle visibility
            via opacity / transform + `pointer-events`. The previous design used
            two sibling <Transition> elements with separate v-if conditions,
            which under Vue 3 Teleport + Transition could desync — the panel
            could finish its leave-transform while the backdrop's leave-transition
            failed to fire, leaving the page covered by an invisible-but-blocking
            overlay. With v-show-style toggling, both elements observe the same
            `open` prop on every frame and cannot drift apart.
        -->

        <!-- Backdrop -->
        <div
            class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] transition-opacity duration-300 ease-out"
            :class="open ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'"
            :aria-hidden="!open"
            @click="emit('close')"
        ></div>

        <!-- Panel -->
        <div
            ref="panel"
            tabindex="-1"
            role="dialog"
            :aria-modal="open ? 'true' : 'false'"
            :aria-hidden="!open"
            :class="[
                'fixed inset-y-0 right-0 z-[201] flex flex-col bg-surface-container-lowest shadow-lifted-lg outline-none transition-transform duration-300 ease-out',
                sizeClasses[size],
                open ? 'translate-x-0 pointer-events-auto' : 'translate-x-full pointer-events-none',
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
                    type="button"
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
    </Teleport>
</template>
