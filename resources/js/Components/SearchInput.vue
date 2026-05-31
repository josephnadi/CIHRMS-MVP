<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue:  { type: String,  default: '' },
    placeholder: { type: String,  default: 'Search…' },
    loading:     { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const internalValue = ref(props.modelValue);
let debounceTimer = null;

// Keep internal value in sync when parent updates modelValue externally
watch(() => props.modelValue, (val) => {
    if (val !== internalValue.value) internalValue.value = val;
});

function onInput(e) {
    internalValue.value = e.target.value;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        emit('update:modelValue', internalValue.value);
    }, 300);
}

function clear() {
    internalValue.value = '';
    clearTimeout(debounceTimer);
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="relative flex items-center">
        <!-- Left icon: spinner when loading, search otherwise -->
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center">
            <svg
                v-if="loading"
                class="h-4 w-4 animate-spin text-secondary"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
            >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            <span
                v-else
                class="material-symbols-outlined text-[18px] text-on-surface-variant/50"
            >search</span>
        </span>

        <!-- Input -->
        <input aria-label="placeholder"
            type="text"
            :value="internalValue"
            :placeholder="placeholder"
            class="w-full rounded-full border border-outline-variant bg-surface-container-low py-2.5 pl-10 pr-9 text-[13px] text-on-surface placeholder:text-on-surface-variant/50 outline-none transition-all focus:border-secondary/40 focus:ring-2 focus:ring-secondary/10 dark:bg-surface-container-low"
            @input="onInput"
        />

        <!-- Clear button -->
        <button
            v-if="internalValue && !loading"
            class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center justify-center rounded-full p-0.5 text-on-surface-variant/50 hover:bg-surface-container hover:text-on-surface transition-colors"
            aria-label="Clear search"
            @click="clear"
        >
            <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
    </div>
</template>
