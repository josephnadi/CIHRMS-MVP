<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import GlossaryText from '@/Components/GlossaryText.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ request: Object });

const R = computed(() => props.request.data ?? props.request);

const ackForm     = useForm({});
const acknowledge = () => ackForm.post(route('privacy.admin.acknowledge', R.value.id), { preserveScroll: true });

// Fulfill / reject form fields must match the server-side FormRequest contracts:
//   FulfillRequest  → summary (required, min 20)
//   RejectRequest   → statutory_basis + summary (required, min 20)
const fulfillForm = useForm({ summary: '' });
const fulfill = () => fulfillForm.post(route('privacy.admin.fulfill', R.value.id), { preserveScroll: true });

const rejectForm = useForm({ statutory_basis: '', summary: '' });
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
    <div data-page-root="true" class="space-y-6 animate-reveal-up">
            <Teleport to="#page-header-mount" defer>
                <div class="space-y-2">
                    <nav class="flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60" aria-label="Breadcrumb">
                        <Link :href="route('privacy.admin.index')" class="hover:text-secondary transition-colors"><GlossaryText text="DPO Queue" /></Link>
                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface" aria-current="page">{{ R.reference }}</span>
                    </nav>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">folder_shared</span>
                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80"><GlossaryText text="DPO CASE FILE · ACT 843" /></p>
                            </div>
                            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ R.reference }}</h1>
                            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                                {{ R.subject?.name ?? 'Data subject' }}<span v-if="R.subject?.email"> ({{ R.subject.email }})</span> ·
                                {{ R.request_type_label }} ·
                                Target by
                                <span :class="R.is_overdue ? 'text-rose-700 font-bold' : 'font-semibold'">{{ R.target_completion_date }}</span>
                                · Case age <span class="font-semibold">{{ caseAgeDays }}d</span>
                                · Assignee <span class="font-semibold">{{ assigneeLabel }}</span>
                            </p>
                        </div>
                        <StatusBadge :status="R.status" :label="R.status_label" />
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

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
                            <textarea aria-label="Summary" v-model="fulfillForm.summary" rows="3" minlength="20" maxlength="5000" required
                                      class="w-full rounded-lg border-outline-variant text-sm"
                                      placeholder="Decision summary (min 20 chars, shown to the subject)"></textarea>
                            <p v-if="fulfillForm.errors.summary" class="text-[11px] text-rose-700">{{ fulfillForm.errors.summary }}</p>
                            <PrimaryButton @click="fulfill" :disabled="fulfillForm.processing">Fulfil (2FA required)</PrimaryButton>
                        </div>

                        <div class="rounded-2xl border border-rose-200 bg-rose-50/40 p-5 space-y-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rose-800">Reject request</p>
                            <p class="text-xs text-on-surface-variant/70">Cite the statutory basis (e.g. "Act 843 §27(e) — public-interest archive").</p>
                            <textarea aria-label="Statutory basis" v-model="rejectForm.statutory_basis" rows="2" minlength="5" maxlength="500" required
                                      class="w-full rounded-lg border-outline-variant text-sm"
                                      placeholder="Statutory basis for rejection (min 5 chars)"></textarea>
                            <p v-if="rejectForm.errors.statutory_basis" class="text-[11px] text-rose-700">{{ rejectForm.errors.statutory_basis }}</p>
                            <textarea aria-label="Summary" v-model="rejectForm.summary" rows="3" minlength="20" maxlength="5000" required
                                      class="w-full rounded-lg border-outline-variant text-sm"
                                      placeholder="Explanation summary (min 20 chars, shown to the subject)"></textarea>
                            <p v-if="rejectForm.errors.summary" class="text-[11px] text-rose-700">{{ rejectForm.errors.summary }}</p>
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
                        <p v-if="!(R.tombstone_log.redacted?.length) && !(R.tombstone_log.held_back?.length)"
                           class="text-xs text-on-surface-variant/70">
                            No erasure receipt yet — fulfil or reject to generate one.
                        </p>
                        <div v-else class="text-xs space-y-2">
                            <div v-if="(R.tombstone_log.redacted ?? []).length">
                                <p class="font-bold text-emerald-700">Redacted ({{ R.tombstone_log.redacted.length }})</p>
                                <ul class="ml-3 list-disc">
                                    <li v-for="(x, i) in R.tombstone_log.redacted" :key="`r-${i}`">
                                        {{ x.table }} — {{ Array.isArray(x.fields) ? x.fields.join(', ') : (x.count + ' rows') }}
                                    </li>
                                </ul>
                            </div>
                            <div v-if="(R.tombstone_log.held_back ?? []).length">
                                <p class="font-bold text-amber-700">Held back under statutory retention ({{ R.tombstone_log.held_back.length }})</p>
                                <ul class="ml-3 list-disc">
                                    <li v-for="(x, i) in R.tombstone_log.held_back" :key="`h-${i}`">
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
    </div>
</template>
