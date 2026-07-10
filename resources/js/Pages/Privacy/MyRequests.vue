<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputError from '@/Components/InputError.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    requests: Array,
});

const showPanel = ref(false);
const form = useForm({
    request_type:           'access',
    subject_statement:      '',
    rectification_details:  '',
    objection_purpose:      '',
});

const submit = () => form.post(route('privacy.submit'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); },
});

const withdraw = (r) => {
    if (confirm('Withdraw this request? You can re-submit later.')) {
        router.post(route('privacy.withdraw', r.id), {}, { preserveScroll: true });
    }
};

const typeLabel = (v) => ({
    access:        'Right to Access',
    rectification: 'Right to Rectification',
    erasure:       'Right to Erasure',
    portability:   'Right to Data Portability',
    objection:     'Right to Object',
    information:   'Right to be Informed',
}[v] ?? v);

// ── Sub-metric tallies derived from `requests` ────────────────────
const isFulfilled = (s) => s === 'fulfilled' || s === 'partially_fulfilled';
const isWithdrawn = (s) => s === 'withdrawn';
const isTerminal  = (s) => isFulfilled(s) || isWithdrawn(s) || s === 'rejected';

const tally = computed(() => {
    const rows = props.requests ?? [];
    return {
        total:     rows.length,
        fulfilled: rows.filter(r => isFulfilled(r.status)).length,
        withdrawn: rows.filter(r => isWithdrawn(r.status)).length,
        open:      rows.filter(r => !isTerminal(r.status)).length,
    };
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
    <Head title="My Data Privacy Requests" />
    <div data-page-root="true">

            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">shield_person</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">DATA SUBJECT REQUESTS · ACT 843</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">My Privacy Requests</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Access, rectify, erase, port, or object — adjudicated by the DPO within 30 days per Act 843.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">post_add</span>
                            File Request
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- Education panel -->
                <div class="rounded-2xl bg-brand-navy/[0.04] border border-brand-navy/15 p-6 space-y-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Your rights under Ghana law</p>
                    <div class="grid md:grid-cols-2 gap-3 text-sm">
                        <div class="flex gap-2"><span class="material-symbols-outlined text-[18px] text-secondary">visibility</span>
                            <div><strong>Access</strong> &mdash; receive a copy of all data we hold about you</div></div>
                        <div class="flex gap-2"><span class="material-symbols-outlined text-[18px] text-secondary">edit</span>
                            <div><strong>Rectification</strong> &mdash; correct anything inaccurate</div></div>
                        <div class="flex gap-2"><span class="material-symbols-outlined text-[18px] text-secondary">delete</span>
                            <div><strong>Erasure</strong> &mdash; delete your data (subject to statutory holds)</div></div>
                        <div class="flex gap-2"><span class="material-symbols-outlined text-[18px] text-secondary">download</span>
                            <div><strong>Portability</strong> &mdash; receive your data in machine-readable form</div></div>
                        <div class="flex gap-2"><span class="material-symbols-outlined text-[18px] text-secondary">block</span>
                            <div><strong>Object</strong> &mdash; stop us processing your data for a specific purpose</div></div>
                        <div class="flex gap-2"><span class="material-symbols-outlined text-[18px] text-secondary">info</span>
                            <div><strong>Information</strong> &mdash; see what we collect and why</div></div>
                    </div>
                    <p class="text-xs text-on-surface-variant/60 pt-2">
                        We respond within 30 days. The first request in any 12-month period is free of charge.
                    </p>
                </div>

                <!-- Request list -->
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                    <div v-if="requests.length === 0">
                        <EmptyState title="No requests yet"
                                    description="You have not submitted any data-subject requests. Use + New request to start." />
                    </div>

                    <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                            <tr>
                                <th class="px-5 py-3 text-left">Reference</th>
                                <th class="px-5 py-3 text-left">Type</th>
                                <th class="px-5 py-3 text-left">Submitted</th>
                                <th class="px-5 py-3 text-left">Target by</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="r in requests" :key="r.id" class="hover:bg-surface-container-low/60">
                                <td class="px-5 py-3 font-mono text-xs">{{ r.reference }}</td>
                                <td class="px-5 py-3">{{ typeLabel(r.request_type) }}</td>
                                <td class="px-5 py-3 text-xs">{{ r.submitted_at ? new Date(r.submitted_at).toLocaleDateString('en-GH') : '—' }}</td>
                                <td class="px-5 py-3 text-xs"
                                    :class="r.is_overdue ? 'text-rose-700 font-semibold' : ''">
                                    {{ r.target_completion_date }}
                                    <span v-if="!r.status_is_terminal && !r.is_overdue"
                                          class="text-on-surface-variant/60"> ({{ r.days_remaining }}d)</span>
                                    <span v-if="r.is_overdue" class="text-rose-700"> · overdue</span>
                                </td>
                                <td class="px-5 py-3"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                                <td class="px-5 py-3 text-right space-x-2">
                                    <a v-if="r.export_path" :href="route('privacy.download', r.id)"
                                       class="text-secondary text-xs hover:underline">Download ZIP</a>
                                    <button v-if="!r.status_is_terminal" @click="withdraw(r)"
                                            class="text-rose-600 text-xs hover:underline">Withdraw</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <SlidePanel :open="showPanel" @close="showPanel = false" title="New data-subject request">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Which right do you wish to exercise?</label>
                        <select v-model="form.request_type" aria-label="Data-subject right to exercise" class="w-full rounded-lg border-outline-variant text-sm" required>
                            <option value="access">Right to Access — get a copy of my data</option>
                            <option value="rectification">Right to Rectification — correct inaccurate data</option>
                            <option value="erasure">Right to Erasure — delete my data</option>
                            <option value="portability">Right to Portability — receive my data in machine-readable form</option>
                            <option value="objection">Right to Object — stop processing for a purpose</option>
                            <option value="information">Right to Information — what is collected and why</option>
                        </select>
                        <InputError :message="form.errors.request_type" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Statement</label>
                        <textarea aria-label="Statement" v-model="form.subject_statement" rows="4" required minlength="10"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="Describe your request in your own words."></textarea>
                        <InputError :message="form.errors.subject_statement" />
                    </div>

                    <div v-if="form.request_type === 'rectification'">
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">What needs correcting?</label>
                        <textarea aria-label="What needs correcting?" v-model="form.rectification_details" rows="3"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="Field name + current value + proposed correction"></textarea>
                        <InputError :message="form.errors.rectification_details" />
                    </div>

                    <div v-if="form.request_type === 'objection'">
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Which processing do you object to?</label>
                        <textarea aria-label="Which processing do you object to?" v-model="form.objection_purpose" rows="3"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="e.g. marketing communications, profiling for performance review"></textarea>
                        <InputError :message="form.errors.objection_purpose" />
                    </div>

                    <PrimaryButton type="submit" :disabled="form.processing">Submit request</PrimaryButton>
                </form>
            </SlidePanel>
    </div>
</template>
