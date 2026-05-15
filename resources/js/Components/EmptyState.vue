<script setup>
defineProps({
    title:       { type: String, default: 'No data found' },
    description: { type: String, default: '' },
    icon:        { type: String, default: 'inbox' },
});
</script>

<template>
    <div class="py-16 flex flex-col items-center">
        <!-- Icon circle -->
        <div class="h-16 w-16 rounded-2xl bg-surface-container flex items-center justify-center">
            <span class="material-symbols-outlined text-[32px] text-on-surface-variant/40">{{ icon }}</span>
        </div>

        <!-- Title -->
        <h3 class="text-[15px] font-bold text-on-surface mt-4">{{ title }}</h3>

        <!-- Description -->
        <p
            v-if="description"
            class="text-[13px] text-on-surface-variant/70 mt-1 max-w-xs text-center"
        >
            {{ description }}
        </p>

        <!-- Action slot -->
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
