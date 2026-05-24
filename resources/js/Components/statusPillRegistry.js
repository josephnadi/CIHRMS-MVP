/**
 * Unified status-pill registry — single source of truth for status colour,
 * label, dot (hex) and optional icon across Loans, Recruitment, Whistleblower,
 * PIPs, Performance contracts/reviews, and dashboard rows.
 *
 * Lives in its own module (not inside <script setup>) so callers can import
 * it directly without re-introducing local pill maps. Vue's <script setup>
 * disallows `export` statements (compiler error), which is why this lives
 * outside `StatusPill.vue`.
 *
 * Add new statuses here rather than reintroducing local maps. Where two
 * modules use the same key with compatible semantics they share one entry.
 */
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

export const STATUS_PILL_FALLBACK = {
    label: '—',
    cls:   'bg-slate-100 text-slate-600 border-slate-200',
    dot:   '#64748b',
};
