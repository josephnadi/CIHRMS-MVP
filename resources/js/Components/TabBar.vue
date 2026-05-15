<script setup>
defineProps({
    tabs:       { type: Array,  required: true },
    modelValue: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex gap-1 border-b border-outline-variant/60 overflow-x-auto scrollbar-none">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            :class="[
                'px-4 py-2.5 text-[13px] font-semibold whitespace-nowrap flex items-center gap-2 rounded-t-lg transition-all',
                modelValue === tab.value
                    ? 'text-secondary border-b-2 border-secondary font-bold'
                    : 'text-on-surface-variant/70 hover:text-on-surface hover:bg-surface-container',
            ]"
            @click="emit('update:modelValue', tab.value)"
        >
            <!-- Optional icon -->
            <span
                v-if="tab.icon"
                class="material-symbols-outlined text-[16px]"
            >{{ tab.icon }}</span>

            {{ tab.label }}

            <!-- Optional count badge -->
            <span
                v-if="tab.count !== undefined && tab.count !== null"
                :class="[
                    'rounded-full px-1.5 text-[10px] font-bold leading-5 min-w-[20px] text-center',
                    modelValue === tab.value
                        ? 'bg-secondary/10 text-secondary'
                        : 'bg-surface-container-high text-on-surface-variant',
                ]"
            >{{ tab.count }}</span>
        </button>
    </div>
</template>
