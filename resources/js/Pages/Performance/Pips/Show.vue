<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import GlossaryText from '@/Components/GlossaryText.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    pip:          Object,
    activeModule: String,
});

const P = computed(() => props.pip.data ?? props.pip);

const checkinForm = useForm({ note: '', met_target: false });
const submitCheckin = () => checkinForm.post(route('performance.pips.checkin', P.value.id), {
    preserveScroll: true,
    onSuccess: () => checkinForm.reset(),
});

const extendForm = useForm({ additional_days: 30, reason: '' });
const submitExtend = () => extendForm.post(route('performance.pips.extend', P.value.id), {
    preserveScroll: true,
    onSuccess: () => extendForm.reset('reason'),
});

const closeForm = useForm({ outcome: 'succeeded', summary: '' });
const submitClose = () => closeForm.post(route('performance.pips.close', P.value.id), {
    preserveScroll: true,
});

const isClosed = computed(() => ['succeeded', 'failed_demoted', 'failed_terminated', 'cancelled'].includes(P.value.status));

// ── Editorial Sovereign · masthead helpers ──────────────────────────
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

const formatDateLong = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const daysRemaining = computed(() => {
    if (!P.value.target_end_date) return null;
    const ms = new Date(P.value.target_end_date).getTime() - Date.now();
    return Math.ceil(ms / 86_400_000);
});

const stageLabel = computed(() => {
    const s = P.value.status;
    if (s === 'open')              return 'Opened';
    if (s === 'in_progress')       return 'In progress';
    if (s === 'extended')          return 'Extended';
    if (s === 'succeeded')         return 'Succeeded';
    if (s === 'failed_demoted')    return 'Demoted';
    if (s === 'failed_terminated') return 'Terminated';
    if (s === 'cancelled')         return 'Cancelled';
    return P.value.status_label ?? s ?? '—';
});
</script>

<template>
    <Head :title="`PIP — ${P.employee?.name}`" />
    <div data-page-root="true" class="space-y-6 animate-reveal-up">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                            <Link :href="route('performance.pips.index')" class="hover:text-secondary">PIP Register</Link>
                            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                            <span>Case File</span>
                        </div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">flag</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80"><GlossaryText text="PIP CASE FILE" /></p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ P.employee?.name ?? 'Case' }}</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            {{ P.employee?.employee_no ?? '—' }}<span v-if="P.employee?.department"> · {{ P.employee.department }}</span>
                            · Opened {{ formatDateLong(P.opened_on) }} · Mentored by {{ P.mentor?.name ?? '—' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge :status="P.status" :label="P.status_label" />
                    </div>
                </div>
            </Teleport>

            <div class="py-6 space-y-6">
                <!-- Target metrics -->
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40 p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-3">Target metrics</p>
                    <EmptyState
                        v-if="!(P.target_metrics?.length)"
                        title="No metrics defined"
                        description="Add target metrics to this PIP so check-ins can be scored against measurable goals."
                        icon="flag"
                    />
                    <ul v-else class="space-y-2 text-sm">
                        <li v-for="(m, i) in P.target_metrics" :key="i" class="flex justify-between">
                            <span>{{ m.metric }}</span>
                            <span class="font-mono">Target: {{ m.target }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Check-in log -->
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                    <div class="px-5 py-4 border-b border-outline-variant/40">
                        <h2 class="font-semibold">Check-in log ({{ P.checkins?.length ?? 0 }})</h2>
                    </div>
                    <div v-if="(P.checkins ?? []).length === 0" class="px-5 py-8 text-center text-sm text-on-surface-variant/60">
                        No check-ins logged yet.
                    </div>
                    <div v-else class="divide-y divide-outline-variant/30">
                        <div v-for="(c, i) in P.checkins" :key="i" class="px-5 py-3">
                            <div class="flex justify-between items-baseline text-xs text-on-surface-variant/70">
                                <span>{{ c.date }}</span>
                                <span :class="c.met_target ? 'text-emerald-700' : 'text-rose-700'" class="font-semibold">
                                    {{ c.met_target ? 'Met target' : 'Did not meet' }}
                                </span>
                            </div>
                            <p class="text-sm mt-1 whitespace-pre-wrap">{{ c.note }}</p>
                        </div>
                    </div>

                    <form v-if="!isClosed" @submit.prevent="submitCheckin"
                          class="px-5 py-4 border-t border-outline-variant/40 space-y-3 bg-brand-navy/[0.02]">
                        <p class="text-xs font-semibold">Add check-in</p>
                        <textarea aria-label="Note" v-model="checkinForm.note" rows="3" required
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  :class="{ 'border-red-400': checkinForm.errors.note }"
                                  placeholder="Notes on observed progress, blockers, and next steps."></textarea>
                        <p v-if="checkinForm.errors.note" class="mt-1 text-[11px] text-red-500">{{ checkinForm.errors.note }}</p>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="checkinForm.met_target" aria-label="Targets met for this check-in" type="checkbox">
                            Targets met for this check-in period
                        </label>
                        <PrimaryButton type="submit" :disabled="checkinForm.processing">Record check-in</PrimaryButton>
                    </form>
                </div>

                <!-- Extension / Close -->
                <div v-if="!isClosed" class="grid md:grid-cols-2 gap-5">
                    <div class="rounded-2xl border border-outline-variant/40 p-5 space-y-3 bg-amber-50/30">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800"><GlossaryText text="Extend PIP" /></p>
                        <p class="text-xs text-on-surface-variant/70">Extensions used: {{ P.extensions_used }} / {{ P.max_extensions }}</p>
                        <input aria-label="Additional days" v-model.number="extendForm.additional_days" type="number" min="14" max="90"
                               class="w-full rounded-lg border-outline-variant text-sm" placeholder="Additional days"
                               :class="{ 'border-red-400': extendForm.errors.additional_days }">
                        <p v-if="extendForm.errors.additional_days" class="mt-1 text-[11px] text-red-500">{{ extendForm.errors.additional_days }}</p>
                        <textarea aria-label="Reason" v-model="extendForm.reason" rows="2" required
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="Reason for extension"></textarea>
                        <PrimaryButton @click="submitExtend" :disabled="extendForm.processing || P.extensions_used >= P.max_extensions">
                            Extend
                        </PrimaryButton>
                    </div>

                    <div class="rounded-2xl border border-outline-variant/40 p-5 space-y-3 bg-brand-navy/[0.03]">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70"><GlossaryText text="Close PIP (2FA required)" /></p>
                        <select v-model="closeForm.outcome" aria-label="PIP close outcome" class="w-full rounded-lg border-outline-variant text-sm">
                            <option value="succeeded">Succeeded — return to normal cycle</option>
                            <option value="failed_demoted">Failed — Demote</option>
                            <option value="failed_terminated">Failed — Terminate (opens off-boarding)</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <textarea aria-label="Summary" v-model="closeForm.summary" rows="3" required minlength="10"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  :class="{ 'border-red-400': closeForm.errors.summary }"
                                  placeholder="Outcome summary — included in employee's permanent record."></textarea>
                        <p v-if="closeForm.errors.summary" class="mt-1 text-[11px] text-red-500">{{ closeForm.errors.summary }}</p>
                        <DangerButton v-if="closeForm.outcome.startsWith('failed')" @click="submitClose" :disabled="closeForm.processing">
                            Close as {{ closeForm.outcome.replace('_', ' ') }}
                        </DangerButton>
                        <PrimaryButton v-else @click="submitClose" :disabled="closeForm.processing">
                            Close as {{ closeForm.outcome }}
                        </PrimaryButton>
                    </div>
                </div>

                <div v-else class="bg-surface-container-low/60 rounded-2xl border border-outline-variant/40 p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Outcome</p>
                    <p class="text-sm">{{ P.outcome_summary }}</p>
                    <p class="text-xs text-on-surface-variant/60 mt-2">Closed {{ P.actual_end_date }}</p>
                </div>
            </div>
    </div>
</template>
