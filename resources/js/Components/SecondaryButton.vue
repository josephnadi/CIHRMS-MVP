<script setup>
defineProps({
    type: { type: String, default: 'button' },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center justify-center gap-2 rounded-xl border border-outline-variant bg-white px-5 py-2.5 text-[13px] font-bold text-on-surface shadow-sm transition-all duration-150 hover:bg-surface-container-low hover:border-outline-variant/80 hover:-translate-y-px active:scale-[0.97] disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-secondary/20"
    >
        <slot />
    </button>
</template>
