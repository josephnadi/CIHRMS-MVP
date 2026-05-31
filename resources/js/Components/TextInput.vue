<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({ type: String, required: true });
const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input aria-label="Model"
        class="w-full rounded-xl border border-outline-variant/80 bg-surface-container-low/60 px-4 py-3 text-[13.5px] text-on-surface placeholder:text-on-surface-variant/40 transition-all duration-200 focus:border-secondary/50 focus:ring-4 focus:ring-secondary/10 focus:bg-white focus:outline-none"
        v-model="model"
        ref="input"
    />
</template>
