<script setup>
import AttachmentChip from './AttachmentChip.vue';

defineProps({
    message:  { type: Object, required: true },
    isOwn:    { type: Boolean, default: false },
});
</script>

<template>
    <div :class="['flex', isOwn ? 'justify-end' : 'justify-start']">
        <div :class="['max-w-[75%] rounded-2xl border px-4 py-3 shadow-card',
                      isOwn ? 'bg-secondary/[0.06] border-secondary/15' : 'bg-surface-container-lowest border-outline-variant/50']">
            <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/70">
                <span>{{ message.author?.name ?? 'Unknown' }}</span>
                <span class="text-on-surface-variant/40">·</span>
                <span class="tabular-nums">{{ new Date(message.created_at).toLocaleString('en-GB') }}</span>
            </div>
            <p class="mt-2 text-[13px] text-on-surface whitespace-pre-wrap">{{ message.body }}</p>
            <div v-if="message.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                <AttachmentChip v-for="a in message.attachments" :key="a.id" :attachment="a" />
            </div>
        </div>
    </div>
</template>
