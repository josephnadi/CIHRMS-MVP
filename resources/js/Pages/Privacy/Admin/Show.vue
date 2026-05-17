<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({ request: Object });

const R = computed(() => props.request.data ?? props.request);

const ackForm     = useForm({});
const acknowledge = () => ackForm.post(route('privacy.admin.acknowledge', R.value.id), { preserveScroll: true });

const fulfillForm = useForm({ decision_summary: '' });
const fulfill = () => fulfillForm.post(route('privacy.admin.fulfill', R.value.id), { preserveScroll: true });

const rejectForm = useForm({ statutory_basis: '' });
const reject = () => rejectForm.post(route('privacy.admin.reject', R.value.id), { preserveScroll: true });

const isClosed = computed(() => ['fulfilled', 'partially_fulfilled', 'rejected', 'withdrawn'].includes(R.value.status));

// ── Case age in days since submission ─────────────────────────────
const caseAgeDays = computed(() => {
    const submitted = R.value?.submitted_at;
    if (!submitted) return 0;
    return Math.max(0, Math.floor((Date.now() - new Date(submitted).getTime()) / 86_400_000));
});

const assigneeLabel = computed(() => {
    const a = R.value?.assignee;
    if (!a) return 'Unassigned';
    return a.name ?? a.full_name ?? a.email ?? '—';
});

// ── Editorial Sovereign masthead label ────────────────────────────
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date:    d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});
</script>

<template>
    <Head :title="`DPA — ${R.reference}`" />
    <AuthenticatedLayout active-module="privacy-admin">
        <template #header>
            <div class="es-masthead">
                <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">DPO CASE FILE · ACT 843</span></span>
                <span class="es-masthead-spacer"></span>
                <span>{{ editionLabel.date }}</span>
                <span class="es-masthead-spacer"></span>
                <span>{{ editionLabel.edition }}</span>
                <span class="es-masthead-spacer"></span>
                <span class="es-masthead-live">
                    <span class="es-dot" aria-hidden="true"></span>
                    Confidential · Restricted
                </span>
            </div>
        </template>

        <div class="space-y-8">

            <!-- ─── Broadsheet hero ──────────────────────────────────── -->
            <div class="es-broadsheet rounded-none">
                <!-- LEAD column -->
                <div class="es-broadsheet-lead">
                    <div class="flex items-center justify-between mb-6 gap-3">
                        <p class="es-eyebrow">DPO case file · Act 843</p>
                        <Link :href="route('privacy.admin.index')" class="es-chip">
                            <span class="material-symbols-outlined text-[15px]">arrow_back</span>
                            DPO queue
                        </Link>
                    </div>
                    <h2 class="es-display text-[clamp(2rem,4.6vw,3.6rem)]">
                        Case dossier
                        <span class="es-display-italic block">{{ R.reference }}.</span>
                    </h2>
                    <p class="es-display-sub">
                        {{ R.subject?.name ?? 'Data subject' }}<span v-if="R.subject?.email"> ({{ R.subject.email }})</span> &middot;
                        {{ R.request_type_label }} &middot;
                        Target by
                        <span :class="R.is_overdue ? 'text-rose-700 font-bold' : 'font-semibold'">{{ R.target_completion_date }}</span>.
                        Every action taken on this file is audit-logged with identity, timestamp, and statutory basis
                        under the Data Protection Act 2012.
                    </p>
                </div>

                <!-- SIDEBAR column: case age + status -->
                <div class="es-broadsheet-sidebar">
                    <div class="es-stat-hero">
                        <p class="es-stat-hero-label">Case Age</p>
                        <p class="es-stat-hero-value">{{ caseAgeDays.toLocaleString() }}<span class="es-stat-unit">d</span></p>
                        <p class="es-stat-hero-caption">
                            <StatusBadge :status="R.status" :label="R.status_label" /> &middot;
                            <span v-if="R.is_overdue" class="text-rose-700 font-bold">overdue</span>
                            <span v-else>{{ R.days_remaining ?? '—' }}d to target</span>
                        </p>
                        <span class="es-stat-hero-delta" :class="R.is_overdue ? 'is-down' : ''">
                            <span class="material-symbols-outlined text-[13px]">lock</span>
                            Subject-only disclosure
                        </span>
                    </div>
                </div>
            </div>

            <!-- ─── Sub-metric strip ─────────────────────────────────── -->
            <div class="es-stat-strip rounded-none">
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Status</p>
                    <p class="es-stat-cell-value-sm">{{ R.status_label ?? '—' }}</p>
                    <p class="es-stat-cell-caption">Workflow stage</p>
                </div>
                <div class="es-stat-cell" :class="R.is_overdue ? 'es-stat-cell--down' : ''">
                    <p class="es-stat-cell-label">Age</p>
                    <p class="es-stat-cell-value">{{ caseAgeDays.toLocaleString() }}<span class="es-stat-unit">d</span></p>
                    <p class="es-stat-cell-caption">Since submission</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Type</p>
                    <p class="es-stat-cell-value-sm">{{ R.request_type_label ?? '—' }}</p>
                    <p class="es-stat-cell-caption">Statutory right invoked</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">DPO Assigned</p>
                    <p class="es-stat-cell-value-sm">{{ assigneeLabel }}</p>
                    <p class="es-stat-cell-caption">Adjudicating officer</p>
                </div>
            </div>

            <!-- Subject statement -->
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-2">Subject statement</p>
                <p class="text-sm whitespace-pre-wrap">{{ R.subject_statement }}</p>
                <div v-if="R.rectification_details" class="mt-4 pt-4 border-t border-outline-variant/40">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Rectification details</p>
                    <p class="text-sm whitespace-pre-wrap">{{ R.rectification_details }}</p>
                </div>
                <div v-if="R.objection_purpose" class="mt-4 pt-4 border-t border-outline-variant/40">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Processing objected to</p>
                    <p class="text-sm whitespace-pre-wrap">{{ R.objection_purpose }}</p>
                </div>
            </div>

            <!-- DPO actions -->
            <div v-if="!isClosed" class="space-y-5">
                <div v-if="R.status === 'submitted'" class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800 mb-2">Step 1 — acknowledge receipt</p>
                    <PrimaryButton @click="acknowledge" :disabled="ackForm.processing">Acknowledge &amp; assign to me</PrimaryButton>
                </div>

                <div class="grid md:grid-cols-2 gap-5">
                    <div class="rounded-2xl border border-outline-variant/40 p-5 bg-brand-navy/[0.03] space-y-3">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Fulfil request</p>
                        <p class="text-xs text-on-surface-variant/70" v-if="R.request_type === 'access' || R.request_type === 'portability' || R.request_type === 'information'">
                            Generates a SHA-256-hashed ZIP export of all the subject's data.
                        </p>
                        <p class="text-xs text-on-surface-variant/70" v-if="R.request_type === 'erasure'">
                            Tombstones PII fields. Statutory holds (payroll 6yr, SSNIT 7yr, audit chain) are preserved and reported.
                        </p>
                        <textarea v-model="fulfillForm.decision_summary" rows="3"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="Decision summary (shown to the subject)"></textarea>
                        <PrimaryButton @click="fulfill" :disabled="fulfillForm.processing">Fulfil (2FA required)</PrimaryButton>
                    </div>

                    <div class="rounded-2xl border border-rose-200 bg-rose-50/40 p-5 space-y-3">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rose-800">Reject request</p>
                        <p class="text-xs text-on-surface-variant/70">Cite the statutory basis (e.g. "Act 843 §27(e) — public-interest archive").</p>
                        <textarea v-model="rejectForm.statutory_basis" rows="3"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="Statutory basis for rejection"></textarea>
                        <DangerButton @click="reject" :disabled="rejectForm.processing">Reject</DangerButton>
                    </div>
                </div>
            </div>

            <!-- Closed outcome -->
            <div v-if="isClosed" class="bg-surface-container-low/60 rounded-2xl border border-outline-variant/40 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-2">Outcome</p>
                <p class="text-sm whitespace-pre-wrap" v-if="R.decision_summary">{{ R.decision_summary }}</p>
                <p class="text-sm whitespace-pre-wrap text-rose-800 mt-2" v-if="R.rejection_basis"><strong>Rejection basis:</strong> {{ R.rejection_basis }}</p>

                <div v-if="R.export_path" class="mt-4 pt-4 border-t border-outline-variant/40">
                    <p class="text-xs text-on-surface-variant/60 mb-1">Export bundle generated {{ R.export_generated_at ? new Date(R.export_generated_at).toLocaleString('en-GH') : '' }}</p>
                    <p class="text-xs font-mono">SHA-256: {{ R.export_sha256 }}</p>
                </div>

                <div v-if="R.tombstone_log" class="mt-4 pt-4 border-t border-outline-variant/40">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-2">Erasure receipt</p>
                    <div class="text-xs space-y-2">
                        <div>
                            <p class="font-bold text-emerald-700">Redacted ({{ (R.tombstone_log.redacted ?? []).length }})</p>
                            <ul class="ml-3 list-disc">
                                <li v-for="(x, i) in (R.tombstone_log.redacted ?? [])" :key="`r-${i}`">
                                    {{ x.table }} — {{ Array.isArray(x.fields) ? x.fields.join(', ') : (x.count + ' rows') }}
                                </li>
                            </ul>
                        </div>
                        <div>
                            <p class="font-bold text-amber-700">Held back under statutory retention ({{ (R.tombstone_log.held_back ?? []).length }})</p>
                            <ul class="ml-3 list-disc">
                                <li v-for="(x, i) in (R.tombstone_log.held_back ?? [])" :key="`h-${i}`">
                                    {{ x.table }} ({{ x.count }} rows) — {{ x.statute }}
                                    <span v-if="x.until"> · until {{ x.until }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit trail -->
            <div v-if="R.audit_trail?.length" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-3">Audit trail</p>
                <div class="space-y-2 text-xs">
                    <div v-for="(e, i) in R.audit_trail" :key="i" class="border-l-2 border-outline-variant pl-3">
                        <div class="flex justify-between">
                            <span class="font-semibold">{{ e.action }}</span>
                            <span class="text-on-surface-variant/60">{{ e.at ? new Date(e.at).toLocaleString('en-GH') : '' }}</span>
                        </div>
                        <p class="text-on-surface-variant/70" v-if="e.meta">{{ JSON.stringify(e.meta) }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
