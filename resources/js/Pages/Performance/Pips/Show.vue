<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

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
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <section class="space-y-8">

                <!-- Masthead strip -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">PERFORMANCE — PIP CASE FILE</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Case file · Live
                    </span>
                </div>

                <!-- Broadsheet hero · dossier cover -->
                <div class="es-broadsheet rounded-none">
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Improvement plan dossier</p>
                        <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                            {{ P.employee?.name ?? 'Case' }},
                            <span class="es-display-italic block">on review.</span>
                        </h2>
                        <p class="es-display-sub">
                            {{ P.employee?.employee_no ?? '—' }}
                            <span v-if="P.employee?.department"> · {{ P.employee.department }}</span>
                            · Opened {{ formatDateLong(P.opened_on) }} · Target end {{ formatDateLong(P.target_end_date) }}.
                            Mentored by {{ P.mentor?.name ?? '—' }}.
                        </p>

                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <Link :href="route('performance.pips.index')" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">arrow_back</span>
                                Register
                            </Link>
                            <span class="es-chip-divider">·</span>
                            <span class="es-chip" aria-hidden="true">
                                <span class="material-symbols-outlined text-[15px]">flag</span>
                                <StatusBadge :status="P.status" :label="P.status_label" />
                            </span>
                        </div>
                    </div>

                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Days remaining</p>
                            <p class="es-stat-hero-value">
                                {{ daysRemaining === null ? '—' : Math.max(0, daysRemaining) }}
                            </p>
                            <p class="es-stat-hero-caption">
                                Until target end · {{ formatDateLong(P.target_end_date) }}
                            </p>
                            <span class="es-stat-hero-delta" :class="{ 'is-down': daysRemaining !== null && daysRemaining < 0 }">
                                <span class="material-symbols-outlined text-[13px]">
                                    {{ daysRemaining !== null && daysRemaining < 0 ? 'schedule' : 'event' }}
                                </span>
                                {{ daysRemaining !== null && daysRemaining < 0 ? 'Past due' : 'On schedule' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Sub-metric strip -->
                <div class="es-stat-strip rounded-none">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Stage</p>
                        <p class="es-stat-cell-value-sm">{{ stageLabel }}</p>
                        <p class="es-stat-cell-caption">Current status</p>
                    </div>
                    <div class="es-stat-cell" :class="{ 'es-stat-cell--down': daysRemaining !== null && daysRemaining < 0 }">
                        <p class="es-stat-cell-label">Days remaining</p>
                        <p class="es-stat-cell-value">{{ daysRemaining === null ? '—' : Math.max(0, daysRemaining) }}</p>
                        <p class="es-stat-cell-caption">{{ daysRemaining !== null && daysRemaining < 0 ? 'Past target end' : 'Until target end' }}</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Extensions used</p>
                        <p class="es-stat-cell-value">
                            {{ P.extensions_used ?? 0 }}<span class="es-stat-unit">/{{ P.max_extensions ?? 2 }}</span>
                        </p>
                        <p class="es-stat-cell-caption">Of allotment</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Mentor</p>
                        <p class="es-stat-cell-value-sm">{{ P.mentor?.name ?? '—' }}</p>
                        <p class="es-stat-cell-caption">HR partner</p>
                    </div>
                </div>
            </section>
        </template>

        <div class="py-6 space-y-6">
            <!-- Target metrics -->
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-3">Target metrics</p>
                <ul class="space-y-2 text-sm">
                    <li v-for="(m, i) in P.target_metrics ?? []" :key="i" class="flex justify-between">
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
                    <textarea v-model="checkinForm.note" rows="3" required
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Notes on observed progress, blockers, and next steps."></textarea>
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
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">Extend PIP</p>
                    <p class="text-xs text-on-surface-variant/70">Extensions used: {{ P.extensions_used }} / {{ P.max_extensions }}</p>
                    <input v-model.number="extendForm.additional_days" type="number" min="14" max="90"
                           class="w-full rounded-lg border-outline-variant text-sm" placeholder="Additional days">
                    <textarea v-model="extendForm.reason" rows="2" required
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Reason for extension"></textarea>
                    <PrimaryButton @click="submitExtend" :disabled="extendForm.processing || P.extensions_used >= P.max_extensions">
                        Extend
                    </PrimaryButton>
                </div>

                <div class="rounded-2xl border border-outline-variant/40 p-5 space-y-3 bg-brand-navy/[0.03]">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Close PIP (2FA required)</p>
                    <select v-model="closeForm.outcome" aria-label="PIP close outcome" class="w-full rounded-lg border-outline-variant text-sm">
                        <option value="succeeded">Succeeded — return to normal cycle</option>
                        <option value="failed_demoted">Failed — Demote</option>
                        <option value="failed_terminated">Failed — Terminate (opens off-boarding)</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <textarea v-model="closeForm.summary" rows="3" required minlength="10"
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Outcome summary — included in employee's permanent record."></textarea>
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
    </AuthenticatedLayout>
</template>
