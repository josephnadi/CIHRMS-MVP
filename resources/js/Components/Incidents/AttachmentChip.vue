<script setup>
import { computed } from 'vue';

const props = defineProps({
    attachment: { type: Object, required: true },
});

const kb = computed(() => Math.max(1, Math.round(props.attachment.size_bytes / 1024)));
</script>

<template>
    <a :href="attachment.download_url"
       class="inline-flex items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px] font-semibold text-on-surface hover:bg-surface-container transition-colors">
        <span class="material-symbols-outlined text-[16px] text-secondary">attach_file</span>
        <span class="truncate max-w-[200px]">{{ attachment.original_name }}</span>
        <span class="text-on-surface-variant/60 tabular-nums">{{ kb }} KB</span>
    </a>
</template>
