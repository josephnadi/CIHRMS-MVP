<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    report:         Object,
    investigators:  Array,
    activeModule:   String,
});

const R = computed(() => props.report.data ?? props.report);
const tab = ref('case');

const triageForm = useForm({
    severity: 'medium',
    assigned_investigator_id: '',
    notes: '',
});
const submitTriage = () => triageForm.post(route('whistleblower.admin.triage', R.value.id), { preserveScroll: true });

const actionForm = useForm({
    action_type: 'document_review',
    notes:       '',
    new_status:  '',
    closure_summary: '',
});
const submitAction = () => actionForm.post(route('whistleblower.admin.actions', R.value.id), {
    preserveScroll: true,
    onSuccess: () => actionForm.reset('notes'),
});

const messageForm = useForm({ body: '' });
const submitMessage = () => messageForm.post(route('whistleblower.admin.messages', R.value.id), {
    preserveScroll: true,
    onSuccess: () => messageForm.reset('body'),
});

// ── Case age in days since received ───────────────────────────────
const caseAgeDays = computed(() => {
    const received = R.value?.received_at ?? R.value?.created_at;
    if (!received) return 0;
    return Math.max(0, Math.floor((Date.now() - new Date(received).getTime()) / 86_400_000));
});

// ── Severity → tone (for cell coloration) ─────────────────────────
const isSevereCase = computed(() => ['critical', 'high'].includes(R.value?.severity));

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
    <Head :title="`Case ${R.case_number}`" />
    <div data-page-root="true">

            <Teleport to="#page-header-mount" defer>
                <div class="space-y-2">
                    <nav class="flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60" aria-label="Breadcrumb">
                        <Link :href="route('whistleblower.admin.index')" class="hover:text-secondary transition-colors">Whistleblower Office</Link>
                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface" aria-current="page">{{ R.case_number }}</span>
                    </nav>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="material-symbols-outlined text-[16px] text-rose-600" style="font-variation-settings:'FILL' 1">shield</span>
                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-rose-700 dark:text-rose-400">INVESTIGATION CASE · ACT 720</p>
                            </div>
                            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ R.case_number }}</h1>
                            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                                {{ R.category_label }} · {{ R.severity_label || 'Severity to be determined' }} ·
                                {{ R.is_anonymous ? 'Anonymous filing' : 'Identified submitter' }} · Age {{ caseAgeDays }}d
                            </p>
                        </div>
                        <StatusBadge :status="R.status" :label="R.status_label" />
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- Triage panel — only visible while case is still in 'submitted' -->
                <div v-if="R.status === 'submitted'" class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5 space-y-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">Awaiting triage (2FA required)</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                        <div>
                            <label class="block text-xs text-on-surface-variant mb-1">Severity</label>
                            <select v-model="triageForm.severity" aria-label="Severity" class="w-full rounded-lg border-outline-variant text-sm">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-on-surface-variant mb-1">Assign investigator</label>
                            <select v-model="triageForm.assigned_investigator_id" aria-label="Assign investigator" class="w-full rounded-lg border-outline-variant text-sm">
                                <option value="">Myself</option>
                                <option v-for="i in investigators" :key="i.id" :value="i.id">{{ i.name }} ({{ i.role }})</option>
                            </select>
                        </div>
                        <PrimaryButton @click="submitTriage" :disabled="triageForm.processing">Triage</PrimaryButton>
                    </div>
                    <textarea v-model="triageForm.notes" rows="2" placeholder="Triage notes (optional)"
                              class="w-full rounded-lg border-outline-variant text-sm"></textarea>
                </div>

                <!-- Tabs -->
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                    <div class="px-5 py-3 border-b border-outline-variant/40 flex gap-6 text-sm">
                        <button @click="tab='case'"      :class="tab==='case'      ? 'text-secondary font-semibold' : 'text-on-surface-variant/70'">Case detail</button>
                        <button @click="tab='actions'"   :class="tab==='actions'   ? 'text-secondary font-semibold' : 'text-on-surface-variant/70'">Actions ({{ R.actions?.length ?? 0 }})</button>
                        <button @click="tab='thread'"    :class="tab==='thread'    ? 'text-secondary font-semibold' : 'text-on-surface-variant/70'">Submitter thread</button>
                        <button @click="tab='evidence'"  :class="tab==='evidence'  ? 'text-secondary font-semibold' : 'text-on-surface-variant/70'">Evidence ({{ R.evidence?.length ?? 0 }})</button>
                    </div>

                    <!-- CASE -->
                    <div v-if="tab==='case'" class="p-6 space-y-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Summary</p>
                            <p class="text-base">{{ R.subject_summary }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Description</p>
                            <p class="text-sm whitespace-pre-wrap leading-relaxed">{{ R.description }}</p>
                        </div>
                        <div v-if="R.desired_outcome">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Desired outcome</p>
                            <p class="text-sm whitespace-pre-wrap">{{ R.desired_outcome }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div v-if="R.incident_date">
                                <p class="text-xs text-on-surface-variant/60">Incident date</p>
                                <p>{{ R.incident_date }}</p>
                            </div>
                            <div v-if="R.incident_location">
                                <p class="text-xs text-on-surface-variant/60">Location</p>
                                <p>{{ R.incident_location }}</p>
                            </div>
                            <div v-if="!R.is_anonymous && R.submitter_contact">
                                <p class="text-xs text-on-surface-variant/60">Submitter contact</p>
                                <p>{{ R.submitter_contact }}</p>
                            </div>
                        </div>
                        <div v-if="R.subjects?.length">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-2">People named</p>
                            <ul class="text-sm space-y-1">
                                <li v-for="s in R.subjects" :key="s.id" class="flex gap-3">
                                    <span class="font-medium">{{ s.subject_label }}</span>
                                    <span class="text-on-surface-variant/70" v-if="s.role_context">— {{ s.role_context }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- ACTIONS -->
                    <div v-if="tab==='actions'" class="p-6 space-y-5">
                        <div class="rounded-xl border border-outline-variant/40 p-4 space-y-3 bg-brand-navy/[0.03]">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Log investigation action</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-on-surface-variant mb-1">Action</label>
                                    <select v-model="actionForm.action_type" aria-label="Investigation action type" class="w-full rounded-lg border-outline-variant text-sm">
                                        <option value="interview">Interview conducted</option>
                                        <option value="document_review">Document review</option>
                                        <option value="site_visit">Site visit</option>
                                        <option value="evidence_added">Evidence added</option>
                                        <option value="finding_recorded">Finding recorded</option>
                                        <option value="referral_chraj">Referred to CHRAJ</option>
                                        <option value="referral_auditor_general">Referred to Auditor-General</option>
                                        <option value="referral_police">Referred to Police</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-on-surface-variant mb-1">Change status (optional)</label>
                                    <select v-model="actionForm.new_status" aria-label="Change case status" class="w-full rounded-lg border-outline-variant text-sm">
                                        <option value="">Keep current</option>
                                        <option value="triaged">Triaged</option>
                                        <option value="investigating">Investigating</option>
                                        <option value="evidence_gathering">Evidence Gathering</option>
                                        <option value="closed_substantiated">Close — Substantiated</option>
                                        <option value="closed_unsubstantiated">Close — Unsubstantiated</option>
                                        <option value="closed_referred">Close — Referred</option>
                                        <option value="withdrawn">Withdrawn</option>
                                    </select>
                                </div>
                            </div>
                            <textarea v-model="actionForm.notes" rows="3" placeholder="Notes (encrypted)"
                                      class="w-full rounded-lg border-outline-variant text-sm"></textarea>
                            <textarea v-if="['closed_substantiated','closed_unsubstantiated','closed_referred'].includes(actionForm.new_status)"
                                      v-model="actionForm.closure_summary" rows="2" placeholder="Closure summary (visible to submitter)"
                                      class="w-full rounded-lg border-outline-variant text-sm"></textarea>
                            <PrimaryButton @click="submitAction" :disabled="actionForm.processing">Log action</PrimaryButton>
                        </div>

                        <div class="space-y-3">
                            <div v-for="a in R.actions ?? []" :key="a.id"
                                 class="border-l-2 border-secondary pl-4 py-2">
                                <div class="flex justify-between text-xs text-on-surface-variant/70">
                                    <span class="font-semibold">{{ a.action_label }}</span>
                                    <span>{{ new Date(a.occurred_at).toLocaleString('en-GH') }}</span>
                                </div>
                                <p class="text-xs text-on-surface-variant/60">by {{ a.investigator?.name ?? '—' }}</p>
                                <p v-if="a.notes" class="text-sm whitespace-pre-wrap mt-1">{{ a.notes }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- THREAD -->
                    <div v-if="tab==='thread'" class="p-6 space-y-4">
                        <div class="divide-y divide-outline-variant/30 max-h-[450px] overflow-y-auto">
                            <div v-for="m in R.messages ?? []" :key="m.id"
                                 :class="m.direction === 'outbound' ? 'bg-brand-navy/[0.03]' : 'bg-amber-50/30'"
                                 class="px-4 py-3 rounded-lg">
                                <div class="flex justify-between text-xs text-on-surface-variant/70 mb-1">
                                    <span>{{ m.direction === 'outbound' ? `${m.posted_by} (you)` : 'Submitter' }}</span>
                                    <span>{{ new Date(m.posted_at).toLocaleString('en-GH') }}</span>
                                </div>
                                <p class="text-sm whitespace-pre-wrap">{{ m.body }}</p>
                            </div>
                            <div v-if="(R.messages ?? []).length === 0" class="px-4 py-10 text-center text-sm text-on-surface-variant/50">
                                No messages yet.
                            </div>
                        </div>
                        <form @submit.prevent="submitMessage" class="space-y-3 pt-3 border-t border-outline-variant/40">
                            <label class="block text-xs font-semibold text-on-surface-variant">Reply to submitter</label>
                            <textarea v-model="messageForm.body" rows="3" required
                                      class="w-full rounded-lg border-outline-variant text-sm"
                                      placeholder="The submitter sees this via their tracking code only."></textarea>
                            <PrimaryButton type="submit" :disabled="messageForm.processing">Post message</PrimaryButton>
                        </form>
                    </div>

                    <!-- EVIDENCE -->
                    <div v-if="tab==='evidence'" class="p-6 space-y-2">
                        <div v-for="e in R.evidence ?? []" :key="e.id"
                             class="flex items-center justify-between border-b border-outline-variant/30 py-3">
                            <div>
                                <p class="text-sm font-medium">{{ e.original_filename }}</p>
                                <p class="text-xs text-on-surface-variant/60">
                                    {{ e.mime_type }} · {{ (e.size_bytes / 1024).toFixed(0) }} KB ·
                                    uploaded {{ new Date(e.uploaded_at).toLocaleDateString('en-GH') }}
                                </p>
                                <p v-if="e.caption" class="text-xs mt-1">{{ e.caption }}</p>
                            </div>
                        </div>
                        <p v-if="(R.evidence ?? []).length === 0" class="text-center text-sm text-on-surface-variant/50 py-6">
                            No evidence attached.
                        </p>
                    </div>
                </div>
            </div>
    </div>
</template>
