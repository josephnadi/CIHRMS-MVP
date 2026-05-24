<script setup>
import { computed } from 'vue';

/**
 * StatusPill — bordered status pill with optional leading dot/icon.
 *
 * Distinct from <StatusBadge> (which uses a softer bg-X-100 pill with no border).
 * This pill matches the bordered visual used by Loans, Recruitment, Whistleblower,
 * PIPs and Dashboard rows: `bg-X-50 text-X-700 border-X-200`.
 *
 * Registry below is the single source of truth for status colour, label, dot
 * (hex) and optional icon across those modules. Add new statuses here rather
 * than re-introducing local maps. The hex dot is also exported via
 * `useStatusPillMeta()` so callers can drive other accents (e.g. card
 * left-borders) from the same registry.
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

// ── Unified registry ─────────────────────────────────────────────────────────
// Keys are the raw status strings used across the modules. Where two modules
// use the same key with compatible semantics, they share one entry.
export const STATUS_PILL_REGISTRY = {
    // Generic
    pending:                { label: 'Pending',         cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#d97706' },
    approved:               { label: 'Approved',        cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',        dot: '#12d9e3' },
    rejected:               { label: 'Rejected',        cls: 'bg-rose-50 text-rose-700 border-rose-200',        dot: '#dc2626' },
    active:                 { label: 'Active',          cls: 'bg-green-50 text-green-700 border-green-200',     dot: '#059669' },
    inactive:               { label: 'Inactive',        cls: 'bg-slate-100 text-slate-600 border-slate-200',    dot: '#64748b' },
    open:                   { label: 'Open',            cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#3949ab' },
    closed:                 { label: 'Closed',          cls: 'bg-slate-100 text-slate-600 border-slate-200',    dot: '#64748b' },
    draft:                  { label: 'Draft',           cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#d97706' },
    filled:                 { label: 'Filled',          cls: 'bg-green-50 text-green-700 border-green-200',     dot: '#059669' },
    cancelled:              { label: 'Cancelled',       cls: 'bg-slate-100 text-slate-600 border-slate-200',    dot: '#64748b' },

    // Dashboard row statuses
    onboarding:             { label: 'Onboarding',      cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#3949ab' },
    away:                   { label: 'Away',            cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#d97706' },
    // Ticket priorities (dashboard uses these via the same colour helper)
    high:                   { label: 'High',            cls: 'bg-rose-50 text-rose-700 border-rose-200',        dot: '#dc2626' },
    medium:                 { label: 'Medium',          cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#d97706' },
    low:                    { label: 'Low',             cls: 'bg-slate-100 text-slate-600 border-slate-200',    dot: '#94a3b8' },
    in_progress:            { label: 'In Progress',     cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#1a237e' },
    resolved:               { label: 'Resolved',        cls: 'bg-green-50 text-green-700 border-green-200',     dot: '#059669' },

    // Loans
    pending_approval:       { label: 'Pending',         cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#d97706' },
    disbursed:              { label: 'Disbursed',       cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#1a237e' },
    repaying:               { label: 'Repaying',        cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#3949ab' },
    paid_off:               { label: 'Paid off',        cls: 'bg-green-50 text-green-700 border-green-200',     dot: '#059669' },
    fully_repaid:           { label: 'Repaid',          cls: 'bg-green-50 text-green-700 border-green-200',     dot: '#059669' },

    // Whistleblower
    submitted:              { label: 'Submitted',       cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#ffd700', icon: 'inbox' },
    triaged:                { label: 'Triaged',         cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',        dot: '#12d9e3', icon: 'fact_check' },
    investigating:          { label: 'Investigating',   cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#3949ab', icon: 'manage_search' },
    evidence_gathering:     { label: 'Evidence',        cls: 'bg-blue-50 text-blue-700 border-blue-200',        dot: '#1a237e', icon: 'folder_managed' },
    closed_substantiated:   { label: 'Substantiated',   cls: 'bg-rose-50 text-rose-700 border-rose-200',        dot: '#dc2626', icon: 'gavel' },
    closed_unsubstantiated: { label: 'Unsubstantiated', cls: 'bg-green-50 text-green-700 border-green-200',     dot: '#059669', icon: 'check_circle' },
    closed_referred:        { label: 'Referred',        cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',        dot: '#12d9e3', icon: 'forward' },
    withdrawn:              { label: 'Withdrawn',       cls: 'bg-slate-100 text-slate-600 border-slate-200',    dot: '#64748b', icon: 'cancel' },

    // PIPs
    extended:               { label: 'Extended',        cls: 'bg-rose-50 text-rose-700 border-rose-200',        dot: '#d912e3' },
    succeeded:              { label: 'Succeeded',       cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: '#059669' },
    failed_demoted:         { label: 'Failed — Demoted',    cls: 'bg-amber-50 text-amber-700 border-amber-200', dot: '#d97706' },
    failed_terminated:      { label: 'Failed — Terminated', cls: 'bg-rose-50 text-rose-700 border-rose-200',    dot: '#dc2626' },
};

const FALLBACK = { label: '—', cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b' };

const meta = computed(() => {
    const key = String(props.status ?? '').toLowerCase().trim();
    return STATUS_PILL_REGISTRY[key] ?? { ...FALLBACK, label: props.status ?? FALLBACK.label };
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
