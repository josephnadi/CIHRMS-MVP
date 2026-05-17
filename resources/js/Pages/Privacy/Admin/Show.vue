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
</script>

<template>
    <Head :title="`DPA — ${R.reference}`" />
    <AuthenticatedLayout active-module="privacy-admin">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('privacy.admin.index')" class="text-xs text-on-surface-variant/60 hover:underline">← All requests</Link>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ R.reference }}</h1>
                    <p class="text-sm text-on-surface-variant/70">
                        {{ R.subject?.name }} ({{ R.subject?.email }}) ·
                        {{ R.request_type_label }} ·
                        target by <span :class="R.is_overdue ? 'text-rose-700 font-bold' : ''">{{ R.target_completion_date }}</span>
                    </p>
                </div>
                <StatusBadge :status="R.status" :label="R.status_label" class="text-base" />
            </div>
        </template>

        <div class="py-6 space-y-6">
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
