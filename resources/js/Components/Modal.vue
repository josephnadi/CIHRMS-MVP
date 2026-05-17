<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show:      { type: Boolean, default: false },
    maxWidth:  { type: String,  default: '2xl' },
    closeable: { type: Boolean, default: true  },
});

const emit = defineEmits(['close']);
const dialog   = ref();
const showSlot = ref(props.show);

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
            showSlot.value = true;
            dialog.value?.showModal();
        } else {
            document.body.style.overflow = '';
            setTimeout(() => { dialog.value?.close(); showSlot.value = false; }, 250);
        }
    },
);

const close = () => { if (props.closeable) emit('close'); };

const closeOnEscape = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); if (props.show) close(); }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => { document.removeEventListener('keydown', closeOnEscape); document.body.style.overflow = ''; });

const maxWidthClass = computed(() => ({
    sm: 'sm:max-w-sm', md: 'sm:max-w-md', lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl', '2xl': 'sm:max-w-2xl',
})[props.maxWidth]);
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
    >
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-8 sm:px-0" scroll-region>
            <!-- Backdrop -->
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="show" class="fixed inset-0 transition-all" @click="close">
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" />
                </div>
            </Transition>

            <!-- Panel -->
            <Transition
                enter-active-class="ease-spring duration-300"
                enter-from-class="opacity-0 translate-y-4 scale-[0.97]"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 translate-y-4 scale-[0.97]"
            >
                <div
                    v-show="show"
                    class="relative mb-6 mx-auto w-full overflow-hidden rounded-[20px] bg-white shadow-lifted-lg ring-1 ring-black/5 transition-all"
                    :class="maxWidthClass"
                >
                    <!-- Top accent -->
                    <div class="h-[3px] w-full"
                         style="background:linear-gradient(90deg,#1a237e,#3949ab,#1a237e);"></div>
                    <slot v-if="showSlot" />
                </div>
            </Transition>
        </div>
    </dialog>
</template>
