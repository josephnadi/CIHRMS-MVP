<script setup>
const props = defineProps({
    events: { type: Array, default: () => [] },
});

const iconFor = {
    uploaded:      'upload_file',
    version_added: 'upload',
    routed:        'send',
    annotated:     'edit_note',
    signed:        'gesture',
    stamped:       'verified',
    forwarded:     'forward',
    rejected:      'block',
    completed:     'check_circle',
    withdrawn:     'undo',
    downloaded:    'download',
    archived:      'archive',
};
</script>

<template>
    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-3">Timeline</p>
        <div v-if="!events.length" class="text-[12px] font-semibold text-on-surface-variant">
            No activity yet.
        </div>
        <ol v-else class="space-y-3">
            <li v-for="e in events" :key="e.id" class="flex items-start gap-2">
                <span class="material-symbols-outlined text-[18px] text-secondary mt-0.5">{{ iconFor[e.type] ?? 'circle' }}</span>
                <div class="flex-1 min-w-0">
                    <div class="text-[12px] font-black text-primary">{{ e.type.replace('_', ' ') }}</div>
                    <div class="text-[11px] text-on-surface-variant">
                        {{ e.actor?.name ?? '—' }} · {{ new Date(e.occurred_at).toLocaleString('en-GB') }}
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>
