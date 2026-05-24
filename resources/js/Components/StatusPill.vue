<script setup>
import { computed } from 'vue';
import { STATUS_PILL_REGISTRY, STATUS_PILL_FALLBACK } from './statusPillRegistry.js';

/**
 * StatusPill — bordered status pill with optional leading dot/icon.
 *
 * Distinct from <StatusBadge> (which uses a softer bg-X-100 pill with no border).
 * This pill matches the bordered visual used by Loans, Recruitment, Whistleblower,
 * PIPs and Dashboard rows: `bg-X-50 text-X-700 border-X-200`.
 *
 * The registry lives in `statusPillRegistry.js` rather than inline because
 * Vue's <script setup> disallows `export` statements. Callers needing the
 * raw dot/colour for other accents (card borders, etc.) should import
 * STATUS_PILL_REGISTRY directly from that file.
 */

const props = defineProps({
    status:    { type: String, required: true },
    /** Override the registry label (e.g. show `status_label` from API) */
    label:     { type: String, default: null },
    /** Hide the leading dot. Default: show. */
    showDot:   { type: Boolean, default: true },
    /** Override icon. Default: registry icon (if any). Pass empty string to hide. */
    icon:      { type: String, default: null },
});

const meta = computed(() => {
    const key = String(props.status ?? '').toLowerCase().trim();
    return STATUS_PILL_REGISTRY[key] ?? { ...STATUS_PILL_FALLBACK, label: props.status ?? STATUS_PILL_FALLBACK.label };
});

const resolvedLabel = computed(() => props.label ?? meta.value.label);
const resolvedIcon  = computed(() => (props.icon === null ? (meta.value.icon ?? null) : props.icon || null));
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider whitespace-nowrap"
        :class="meta.cls"
    >
        <span v-if="showDot && !resolvedIcon"
              class="h-1.5 w-1.5 rounded-full flex-shrink-0"
              :style="`background:${meta.dot}`"></span>
        <span v-if="resolvedIcon" class="material-symbols-outlined text-[11px]">{{ resolvedIcon }}</span>
        {{ resolvedLabel }}
    </span>
</template>
