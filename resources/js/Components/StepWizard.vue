<script setup>
import { computed } from 'vue';

const props = defineProps({
    steps: {
        type: Array,
        default: () => [],
        // Each item: { label: String, icon?: String }
    },
    modelValue: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['update:modelValue']);

const totalSteps = computed(() => props.steps.length);

function isCompleted(index) {
    return index < props.modelValue;
}

function isCurrent(index) {
    return index === props.modelValue;
}

function isPending(index) {
    return index > props.modelValue;
}

// Percentage of the connector line that should be filled (per segment)
function connectorFill(index) {
    // connector between step[index] and step[index+1]
    if (index < props.modelValue - 1) return 100; // fully completed
    if (index === props.modelValue - 1) return 100; // current step reached — previous connector full
    return 0;
}
</script>

<template>
    <div class="w-full">
        <!-- ── Step indicator ── -->
        <div class="w-full">
            <!-- Desktop: full horizontal indicator -->
            <div class="hidden sm:flex items-start relative">
                <template v-for="(step, index) in steps" :key="index">
                    <!-- Step node -->
                    <div class="flex flex-col items-center relative z-10" style="flex: 1 1 0%;">
                        <!-- Circle -->
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center border-2 transition-all duration-300 font-bold text-[13px] shrink-0"
                            :class="[
                                isCompleted(index)
                                    ? 'bg-secondary border-secondary text-on-secondary'
                                    : isCurrent(index)
                                        ? 'bg-surface-container-lowest border-secondary text-secondary ring-4 ring-secondary/20'
                                        : 'bg-surface-container-low border-outline-variant text-on-surface-variant/40',
                            ]"
                        >
                            <!-- Completed: checkmark -->
                            <span
                                v-if="isCompleted(index)"
                                class="material-symbols-outlined text-[16px] font-black"
                                style="font-variation-settings: 'FILL' 1, 'wght' 700"
                            >check</span>
                            <!-- Current or pending: number or icon -->
                            <template v-else>
                                <span
                                    v-if="step.icon && isCurrent(index)"
                                    class="material-symbols-outlined text-[16px]"
                                >{{ step.icon }}</span>
                                <span v-else>{{ index + 1 }}</span>
                            </template>
                        </div>

                        <!-- Label -->
                        <span
                            class="mt-2 text-[11px] font-semibold text-center leading-tight px-1 transition-colors duration-200"
                            :class="[
                                isCompleted(index)
                                    ? 'text-secondary'
                                    : isCurrent(index)
                                        ? 'text-on-surface font-bold'
                                        : 'text-on-surface-variant/40',
                            ]"
                        >{{ step.label }}</span>
                    </div>

                    <!-- Connector line between steps -->
                    <div
                        v-if="index < steps.length - 1"
                        class="relative h-0.5 mt-4 overflow-hidden rounded-full"
                        style="flex: 2 1 0%; min-width: 16px;"
                    >
                        <!-- Track -->
                        <div class="absolute inset-0 bg-outline-variant/40 rounded-full"></div>
                        <!-- Fill -->
                        <div
                            class="absolute inset-y-0 left-0 bg-secondary rounded-full transition-all duration-500 ease-spring"
                            :style="{ width: connectorFill(index) + '%' }"
                        ></div>
                    </div>
                </template>
            </div>

            <!-- Mobile: compact indicator -->
            <div class="sm:hidden flex items-center gap-3 bg-surface-container-low rounded-xl px-4 py-3">
                <div
                    class="w-8 h-8 rounded-full flex items-center justify-center bg-secondary text-on-secondary font-black text-[13px] shrink-0"
                >
                    <span
                        v-if="modelValue >= totalSteps"
                        class="material-symbols-outlined text-[16px]"
                        style="font-variation-settings: 'FILL' 1"
                    >check</span>
                    <span v-else>{{ modelValue + 1 }}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[12px] text-on-surface-variant/60 font-medium">
                        Step {{ Math.min(modelValue + 1, totalSteps) }} of {{ totalSteps }}
                    </p>
                    <p class="text-[14px] font-bold text-on-surface truncate">
                        {{ steps[modelValue]?.label ?? 'Complete' }}
                    </p>
                </div>
                <!-- Mini dot indicators -->
                <div class="flex items-center gap-1">
                    <div
                        v-for="(_, i) in steps"
                        :key="i"
                        class="rounded-full transition-all duration-300"
                        :class="[
                            i === modelValue
                                ? 'w-4 h-2 bg-secondary'
                                : i < modelValue
                                    ? 'w-2 h-2 bg-secondary/40'
                                    : 'w-2 h-2 bg-outline-variant',
                        ]"
                    ></div>
                </div>
            </div>
        </div>

        <!-- ── Step content ── -->
        <div class="mt-6">
            <Transition name="step-fade" mode="out-in">
                <div :key="modelValue">
                    <slot :name="'step-' + modelValue" />
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.step-fade-enter-active,
.step-fade-leave-active {
    transition: opacity 0.22s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}
.step-fade-enter-from {
    opacity: 0;
    transform: translateX(16px);
}
.step-fade-leave-to {
    opacity: 0;
    transform: translateX(-16px);
}
</style>
